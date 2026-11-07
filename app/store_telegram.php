<?php
/**
 * Telegram storage driver for app/db.php (STORE_DRIVER=telegram).
 *
 * Layout inside the channel (TELEGRAM_DB_CHANNEL_ID, default TELEGRAM_CHANNEL_ID):
 *   - one document message per collection (products.json.gz, orders.json.gz, ...).
 *     Writes replace the file in place (editMessageMedia), so the channel does not
 *     fill up with versions. Every version has its own immutable file_id.
 *   - one pinned text message, the index:
 *       NAHID_HONEY_DB_INDEX — do not delete
 *       {"v":1,"c":{"products":["<file_id>",<msg_id>],...},"n":{"products":41,...},"t":<unix>}
 *     (if it ever grows past Telegram's 4096-char limit the index itself is stored
 *     as a document and the pinned text holds {"v":1,"ix":["<file_id>",<msg_id>]}).
 *
 * Reads: index (getChat → pinned_message, cached in /tmp for a few seconds) →
 * collection files (cached in /tmp forever, keyed by file_id). Writes are buffered
 * per request and flushed once (store_flush(), also run automatically at shutdown):
 * fresh index → latest version of each dirty collection → re-apply this request's
 * per-document changes → upload → re-check → edit index → verify. Concurrent
 * writers touching different documents therefore merge instead of overwriting.
 *
 * Only messages this driver created are ever edited/deleted/unpinned.
 */

final class TgStore {
    const MARK = 'NAHID_HONEY_DB_INDEX';
    const HEADER = 'NAHID_HONEY_DB_INDEX — do not delete';
    /** Collections that are kept only on this server instance (never written to Telegram). */
    const LOCAL_ONLY = ['login_attempts'];
    /** Keep the newest N entries of fast-growing logs. */
    const CAPS = ['activity' => 3000];

    private static ?TgStore $inst = null;

    private string $token;
    private string $chat;
    private string $api;
    private string $tmp;
    private ?string $key;
    private int $ttl;

    private ?array $idx = null;      // index used for reads in this request
    private ?int $idxMsg = null;     // message id of the index message
    private array $base = [];        // coll => [id => doc] (as of $idx)
    private array $dirty = [];       // coll => [id => doc|null]
    private array $auto = [];        // coll => [id => true] ids allocated by store_next_id
    private array $counters = [];    // name => value allocated in this request
    private $ch = null;              // reused curl handle (keep-alive)
    public array $stats = ['calls' => 0, 'ms' => 0.0];

    public static function get(): TgStore {
        return self::$inst ??= new TgStore();
    }

    private function __construct() {
        $cfg = require __DIR__ . '/../config/telegram.php';
        $this->token = (string)($cfg['bot_token'] ?? '');
        $this->chat = (string)(getenv('TELEGRAM_DB_CHANNEL_ID') ?: ($cfg['channel_id'] ?? ''));
        $this->api = rtrim((string)($cfg['api_base'] ?? 'https://api.telegram.org'), '/');
        if ($this->token === '' || $this->chat === '') {
            throw new RuntimeException('Telegram store: TELEGRAM_BOT_TOKEN and TELEGRAM_CHANNEL_ID must be set');
        }
        $this->tmp = rtrim(sys_get_temp_dir(), '/') . '/nh-tgdb-' . substr(sha1($this->token . '|' . $this->chat), 0, 12);
        if (!is_dir($this->tmp)) @mkdir($this->tmp, 0700, true);
        $k = getenv('TELEGRAM_DB_KEY');
        $this->key = $k ? hash('sha256', 'nh-store|' . $k, true) : null;
        $this->ttl = max(0, (int)(getenv('TELEGRAM_DB_INDEX_TTL') ?: 3));

        // Flush buffered writes when the request ends (also after exit()/redirect()).
        // Output is buffered so a failed save can still turn into an error page.
        if (PHP_SAPI !== 'cli') ob_start();
        register_shutdown_function([$this, 'shutdown']);
    }

    // ------------------------------------------------------------------ public API

    public function all(string $coll): array {
        $docs = $this->docs($coll);
        $list = array_values(array_filter($docs, 'is_array'));
        usort($list, fn($a, $b) => strcmp(store_sort_ts($a['created_at'] ?? ''), store_sort_ts($b['created_at'] ?? ''))
            ?: strcmp((string)$a['id'], (string)$b['id']));
        return $list;
    }

    public function find(string $coll, string $id): ?array {
        return $this->docs($coll)[$id] ?? null;
    }

    public function put(string $coll, array $doc, bool $autoId = false): void {
        if (in_array($coll, self::LOCAL_ONLY, true)) { $this->localWrite($coll, $doc['id'], $doc); return; }
        $this->dirty[$coll][$doc['id']] = $doc;
        if ($autoId) $this->auto[$coll][$doc['id']] = true;
    }

    public function delete(string $coll, string $id): bool {
        $existed = $this->find($coll, $id) !== null;
        if (in_array($coll, self::LOCAL_ONLY, true)) { $this->localWrite($coll, $id, null); return $existed; }
        if ($existed) $this->dirty[$coll][$id] = null;
        return $existed;
    }

    public function collections(): array {
        $names = array_keys($this->index(false)['c'] ?? []);
        foreach ($this->dirty as $c => $_) if (!in_array($c, $names, true)) $names[] = $c;
        sort($names);
        return $names;
    }

    public function nextId(string $name): int {
        $idx = $this->index(true);
        $v = max((int)($idx['n'][$name] ?? 0), (int)($this->counters[$name] ?? 0));
        $v = max($v, $this->maxNumericId($this->docs($name)));
        $this->counters[$name] = ++$v;
        return $v;
    }

    public function shutdown(): void {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            error_log('[nahid-honey] Telegram store flush failed: ' . $e->getMessage());
            if (PHP_SAPI === 'cli') { fwrite(STDERR, 'Telegram store flush failed: ' . $e->getMessage() . "\n"); return; }
            if (!headers_sent()) {
                while (ob_get_level() > 0) ob_end_clean();
                header_remove('Location');
                http_response_code(503);
                header('Content-Type: text/html; charset=utf-8');
                header('Retry-After: 5');
                echo '<!doctype html><meta charset="utf-8"><title>Could not save</title>'
                   . '<div style="font:16px system-ui;max-width:520px;margin:80px auto;padding:0 16px">'
                   . '<h1>Sorry, we could not save that</h1><p>Our storage service did not respond. Nothing was saved. '
                   . 'Please go back and try again in a moment.</p></div>';
            }
        }
    }

    /** Persist buffered writes. Throws on failure (nothing is silently dropped). */
    public function flush(): void {
        if (!$this->dirty && !$this->counters) return;
        $colls = array_keys($this->dirty);
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $idx = $this->index(true);
            $this->prefetch($idx, $colls);
            $uploads = [];
            $merged = [];
            foreach ($colls as $coll) {
                $docs = $this->loadColl($idx, $coll);
                foreach ($this->dirty[$coll] as $id => $doc) {
                    $id = (string)$id;
                    if ($doc === null) { unset($docs[$id]); continue; }
                    if (!empty($this->auto[$coll][$id]) && isset($docs[$id]) && $docs[$id] != $doc) {
                        // Another request took this auto id meanwhile — move ours to a free one.
                        $new = (string)(max($this->maxNumericId($docs), $this->maxNumericId($this->dirty[$coll]), (int)($idx['n'][$coll] ?? 0)) + 1);
                        unset($this->dirty[$coll][$id], $this->auto[$coll][$id]);
                        $doc['id'] = $new;
                        $this->dirty[$coll][$new] = $doc;
                        $this->auto[$coll][$new] = true;
                        $this->counters[$coll] = max((int)($this->counters[$coll] ?? 0), (int)$new);
                        $id = $new;
                    }
                    $docs[$id] = $doc;
                }
                if (isset(self::CAPS[$coll]) && count($docs) > self::CAPS[$coll]) {
                    uasort($docs, fn($a, $b) => strcmp(store_sort_ts($a['created_at'] ?? ''), store_sort_ts($b['created_at'] ?? '')));
                    $docs = array_slice($docs, -self::CAPS[$coll], null, true);
                }
                $merged[$coll] = $docs;
                $uploads[$coll] = ['name' => $coll, 'docs' => array_values($docs), 'msg' => $idx['c'][$coll][1] ?? null];
            }
            $res = $uploads ? $this->uploadMany($uploads) : [];

            $check = $this->index(true);
            $conflict = false;
            foreach ($colls as $coll) {
                if (($check['c'][$coll][0] ?? null) !== ($idx['c'][$coll][0] ?? null)) $conflict = true;
            }
            if ($conflict) { usleep(random_int(100, 400) * 1000); continue; }

            $next = $check;
            foreach ($res as $coll => $ref) $next['c'][$coll] = $ref;
            foreach ($this->counters as $k => $v) $next['n'][$k] = max((int)($next['n'][$k] ?? 0), (int)$v);
            foreach ($colls as $coll) {
                $next['n'][$coll] = max((int)($next['n'][$coll] ?? 0), $this->maxNumericId($merged[$coll]));
            }
            $next['t'] = time();
            $this->writeIndex($next);

            // Verify nobody overwrote the index at the same moment; if so, redo the merge.
            usleep(random_int(150, 350) * 1000);
            $verify = $this->index(true);
            $ok = true;
            foreach ($res as $coll => $ref) {
                if (($verify['c'][$coll][0] ?? null) !== $ref[0]) $ok = false;
            }
            if (!$ok) continue;

            foreach ($merged as $coll => $docs) $this->base[$coll] = $docs;
            $this->idx = $verify;
            $this->dirty = $this->auto = $this->counters = [];
            return;
        }
        throw new RuntimeException('Telegram store: too many concurrent writes, could not save');
    }

    // ------------------------------------------------------------------ documents

    private function docs(string $coll): array {
        if (in_array($coll, self::LOCAL_ONLY, true)) return $this->localRead($coll);
        if (!array_key_exists($coll, $this->base)) {
            $idx = $this->index(false);
            $this->prefetch($idx, array_keys($idx['c'] ?? []));
            try {
                $this->base[$coll] = $this->loadColl($idx, $coll);
            } catch (\Throwable $e) {
                // The file behind a stale cached index may be gone; retry with a fresh index.
                $idx = $this->index(true);
                $this->base[$coll] = $this->loadColl($idx, $coll);
            }
        }
        $docs = $this->base[$coll];
        foreach ($this->dirty[$coll] ?? [] as $id => $doc) {
            if ($doc === null) unset($docs[$id]); else $docs[$id] = $doc;
        }
        return $docs;
    }

    private function maxNumericId(array $docs): int {
        $m = 0;
        foreach ($docs as $id => $d) {
            $id = (string)(is_array($d) ? ($d['id'] ?? $id) : $id);
            if (ctype_digit($id) && strlen($id) < 15) $m = max($m, (int)$id);
        }
        return $m;
    }

    /** [id => doc] for a collection as referenced by $idx. */
    private function loadColl(array $idx, string $coll): array {
        $ref = $idx['c'][$coll] ?? null;
        if (!$ref) return [];
        $data = $this->decode($this->file($ref[0]));
        $out = [];
        foreach ($data['docs'] ?? [] as $d) if (is_array($d) && isset($d['id'])) $out[(string)$d['id']] = $d;
        return $out;
    }

    // ------------------------------------------------------------------ index

    private function idxCachePath(): string { return $this->tmp . '/index.json'; }

    private function index(bool $fresh): array {
        if (!$fresh && $this->idx !== null) return $this->idx;
        $cache = is_file($this->idxCachePath()) ? json_decode((string)@file_get_contents($this->idxCachePath()), true) : null;
        if (!$fresh && $cache && time() - (int)$cache['at'] < $this->ttl) {
            $this->idxMsg = (int)$cache['msg'];
            return $this->idx = $cache['idx'];
        }
        try {
            [$idx, $msg] = $this->fetchIndex($cache['msg'] ?? null);
        } catch (TgNetworkError $e) {
            if ($fresh || !$cache) throw $e;
            error_log('[nahid-honey] Telegram unreachable, serving cached data: ' . $e->getMessage());
            $this->idxMsg = (int)$cache['msg'];
            return $this->idx = $cache['idx'];
        }
        $this->idxMsg = $msg;
        $this->saveIdxCache($idx, $msg);
        if (!$fresh || $this->idx === null) $this->idx = $idx;
        return $idx;
    }

    private function saveIdxCache(array $idx, int $msg): void {
        $f = $this->idxCachePath();
        @file_put_contents($f . '.' . getmypid(), json_encode(['at' => time(), 'msg' => $msg, 'idx' => $idx]));
        @rename($f . '.' . getmypid(), $f);
    }

    /** @return array{0: array, 1: int} [index, message id] */
    private function fetchIndex(?int $cachedMsg): array {
        $chat = $this->call('getChat', ['chat_id' => $this->chat]);
        $pm = $chat['pinned_message'] ?? null;
        if ($pm && $this->isOurs($pm['text'] ?? '')) {
            return [$this->parseIndex($pm['text']), (int)$pm['message_id']];
        }
        $known = (int)(getenv('TELEGRAM_DB_INDEX_MSG_ID') ?: $cachedMsg ?: 0);
        if ($known > 0) {
            // Someone else's message is pinned on top. Read ours by forwarding it
            // to the same channel and deleting the forwarded copy immediately.
            $fw = $this->call('forwardMessage', ['chat_id' => $this->chat, 'from_chat_id' => $this->chat,
                'message_id' => $known, 'disable_notification' => 'true']);
            try { $this->call('deleteMessage', ['chat_id' => $this->chat, 'message_id' => $fw['message_id']]); } catch (\Throwable $e) {}
            if (!$this->isOurs($fw['text'] ?? '')) throw new RuntimeException("Telegram store: message $known is not the Nahid Honey index");
            return [$this->parseIndex($fw['text']), $known];
        }
        if ($pm && !getenv('TELEGRAM_DB_INIT')) {
            throw new RuntimeException('Telegram store: the pinned message in the channel is not the Nahid Honey index. '
                . 'Set TELEGRAM_DB_INDEX_MSG_ID to the index message id (or TELEGRAM_DB_INIT=1 to create a new store).');
        }
        return $this->bootstrap();
    }

    private function isOurs(string $text): bool { return str_starts_with($text, self::MARK); }

    private function parseIndex(string $text): array {
        $json = substr($text, strpos($text, "\n") ?: 0);
        $idx = json_decode(trim($json), true);
        if (!is_array($idx)) throw new RuntimeException('Telegram store: index message is corrupt');
        if (isset($idx['ix'])) $idx = $this->decode($this->file($idx['ix'][0])) + ['ixm' => $idx['ix'][1] ?? null];
        $idx['c'] = (array)($idx['c'] ?? []);
        $idx['n'] = (array)($idx['n'] ?? []);
        return $idx;
    }

    private function writeIndex(array $idx): void {
        $prevIx = $idx['ixm'] ?? null;
        unset($idx['ixm']);
        $text = self::HEADER . "\n" . json_encode($idx, JSON_UNESCAPED_SLASHES);
        if (mb_strlen($text) > 3900) {
            $ref = $this->uploadMany(['_index' => ['name' => '_index', 'docs' => null, 'raw' => $idx, 'msg' => $prevIx]])['_index'];
            $text = self::HEADER . "\n" . json_encode(['v' => 1, 'ix' => $ref, 't' => time()], JSON_UNESCAPED_SLASHES);
        }
        try {
            $this->call('editMessageText', ['chat_id' => $this->chat, 'message_id' => $this->idxMsg, 'text' => $text]);
        } catch (TgApiError $e) {
            if (!str_contains($e->getMessage(), 'not modified')) throw $e;
        }
        $this->saveIdxCache($idx, (int)$this->idxMsg);
    }

    /** First run: seed from database/*.json, upload, post + pin the index. */
    private function bootstrap(): array {
        [$colls, $counters] = store_seed_rows();
        $uploads = [];
        foreach ($colls as $name => $docs) $uploads[$name] = ['name' => $name, 'docs' => array_values($docs), 'msg' => null];
        $refs = $this->uploadMany($uploads);
        $idx = ['v' => 1, 'c' => $refs, 'n' => $counters, 't' => time()];
        $msg = $this->call('sendMessage', ['chat_id' => $this->chat, 'disable_notification' => 'true',
            'text' => self::HEADER . "\n" . json_encode($idx, JSON_UNESCAPED_SLASHES)]);
        $mid = (int)$msg['message_id'];
        $this->call('pinChatMessage', ['chat_id' => $this->chat, 'message_id' => $mid, 'disable_notification' => 'true']);

        // Two cold requests may bootstrap at once — the most recent pin wins, the other cleans up.
        usleep(300000);
        $pm = $this->call('getChat', ['chat_id' => $this->chat])['pinned_message'] ?? null;
        if ($pm && $this->isOurs($pm['text'] ?? '') && (int)$pm['message_id'] !== $mid) {
            try { $this->call('unpinChatMessage', ['chat_id' => $this->chat, 'message_id' => $mid]); } catch (\Throwable $e) {}
            foreach (array_merge([[null, $mid]], array_values($refs)) as $r) {
                try { $this->call('deleteMessage', ['chat_id' => $this->chat, 'message_id' => $r[1]]); } catch (\Throwable $e) {}
            }
            return [$this->parseIndex($pm['text']), (int)$pm['message_id']];
        }
        error_log("[nahid-honey] Telegram store created; index message id $mid");
        return [$idx, $mid];
    }

    // ------------------------------------------------------------------ files

    private function encode(array $payload): string {
        $bin = gzencode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), 6);
        if (!$this->key) return $bin;
        $iv = random_bytes(12);
        $ct = openssl_encrypt($bin, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'NHE1' . $iv . $tag . $ct;
    }

    private function decode(string $bin): array {
        if (str_starts_with($bin, 'NHE1')) {
            if (!$this->key) throw new RuntimeException('Telegram store: data is encrypted but TELEGRAM_DB_KEY is not set');
            $bin = openssl_decrypt(substr($bin, 32), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($bin, 4, 12), substr($bin, 16, 16));
            if ($bin === false) throw new RuntimeException('Telegram store: wrong TELEGRAM_DB_KEY');
        }
        if (str_starts_with($bin, "\x1f\x8b")) $bin = gzdecode($bin);
        $d = json_decode((string)$bin, true);
        if (!is_array($d)) throw new RuntimeException('Telegram store: unreadable data file');
        return $d;
    }

    private function fileCachePath(string $fileId): string { return $this->tmp . '/f-' . sha1($fileId); }

    private function file(string $fileId): string {
        $p = $this->fileCachePath($fileId);
        if (is_file($p)) return (string)file_get_contents($p);
        $this->prefetchFiles([$fileId]);
        if (!is_file($p)) throw new RuntimeException('Telegram store: could not download ' . $fileId);
        return (string)file_get_contents($p);
    }

    private function prefetch(array $idx, array $colls): void {
        $ids = [];
        foreach ($colls as $c) if (!empty($idx['c'][$c][0]) && !is_file($this->fileCachePath($idx['c'][$c][0]))) $ids[] = $idx['c'][$c][0];
        if ($ids) { try { $this->prefetchFiles($ids); } catch (\Throwable $e) { /* file() retries individually */ } }
    }

    /** Download several files in parallel into the cache (getFile → file URL). */
    private function prefetchFiles(array $fileIds): void {
        $reqs = [];
        foreach ($fileIds as $fid) $reqs[$fid] = ['url' => "{$this->api}/bot{$this->token}/getFile", 'post' => ['file_id' => $fid]];
        $paths = [];
        foreach ($this->multi($reqs) as $fid => $r) {
            $j = json_decode((string)$r['body'], true);
            if (!empty($j['ok'])) $paths[$fid] = ['url' => "{$this->api}/file/bot{$this->token}/" . $j['result']['file_path']];
        }
        foreach ($this->multi($paths) as $fid => $r) {
            if ($r['code'] === 200 && $r['body'] !== '') {
                $p = $this->fileCachePath($fid);
                @file_put_contents($p . '.' . getmypid(), $r['body']);
                @rename($p . '.' . getmypid(), $p);
            }
        }
    }

    /**
     * Upload collections in parallel. Replaces the existing message's file when
     * possible (editMessageMedia), else posts a new document.
     * @return array coll => [file_id, message_id]
     */
    private function uploadMany(array $items): array {
        $out = [];
        $reqs = [];
        $tmpFiles = [];
        foreach ($items as $key => $it) {
            $bin = $this->encode($it['raw'] ?? ['coll' => $it['name'], 'docs' => $it['docs'], 't' => time()]);
            $tf = $this->tmp . '/up-' . bin2hex(random_bytes(6));
            file_put_contents($tf, $bin);
            $tmpFiles[$key] = [$tf, $bin];
            $fname = $it['name'] . '.json.gz' . ($this->key ? '.enc' : '');
            $caption = 'Nahid Honey data · ' . $it['name'] . ' · ' . gmdate('Y-m-d H:i:s') . ' UTC — do not delete';
            if (!empty($it['msg'])) {
                $reqs[$key] = ['url' => "{$this->api}/bot{$this->token}/editMessageMedia", 'post' => [
                    'chat_id' => $this->chat, 'message_id' => $it['msg'],
                    'media' => json_encode(['type' => 'document', 'media' => 'attach://f', 'caption' => $caption]),
                    'f' => new CURLFile($tf, 'application/gzip', $fname),
                ], 'edit' => true];
            } else {
                $reqs[$key] = ['url' => "{$this->api}/bot{$this->token}/sendDocument", 'post' => [
                    'chat_id' => $this->chat, 'disable_notification' => 'true', 'caption' => $caption,
                    'document' => new CURLFile($tf, 'application/gzip', $fname),
                ]];
            }
        }
        try {
            for ($round = 0; $round < 3 && $reqs; $round++) {
                $wait = 0;
                foreach ($this->multi($reqs, 30) as $key => $r) {
                    $j = json_decode((string)$r['body'], true);
                    if (!empty($j['ok']) && !empty($j['result']['document']['file_id'])) {
                        $fid = $j['result']['document']['file_id'];
                        $out[$key] = [$fid, (int)$j['result']['message_id']];
                        @file_put_contents($this->fileCachePath($fid), $tmpFiles[$key][1]);
                        unset($reqs[$key]);
                        continue;
                    }
                    $code = (int)($j['error_code'] ?? 0);
                    if ($code === 429) { $wait = max($wait, min(10, (int)($j['parameters']['retry_after'] ?? 1))); continue; }
                    if (!empty($reqs[$key]['edit']) && $code === 400) {
                        // Our old message is gone / not editable: post a fresh one instead.
                        $p = $reqs[$key]['post'];
                        $reqs[$key] = ['url' => "{$this->api}/bot{$this->token}/sendDocument", 'post' => [
                            'chat_id' => $this->chat, 'disable_notification' => 'true',
                            'caption' => json_decode($p['media'], true)['caption'], 'document' => $p['f'],
                        ]];
                        continue;
                    }
                    if ($r['code'] === 0 || $r['code'] >= 500) { $wait = max($wait, 1); continue; }
                    throw new TgApiError('Telegram upload failed: ' . ($j['description'] ?? ('HTTP ' . $r['code'])));
                }
                if ($reqs && $wait) sleep($wait);
            }
        } finally {
            foreach ($tmpFiles as [$tf]) @unlink($tf);
        }
        if ($reqs) throw new TgNetworkError('Telegram upload failed for: ' . implode(', ', array_keys($reqs)));
        return $out;
    }

    // ------------------------------------------------------------------ HTTP

    /** One Bot API call with retry on 429 / network errors. */
    private function call(string $method, array $params): array {
        for ($try = 0; $try < 3; $try++) {
            $t = microtime(true);
            $this->ch ??= curl_init();
            curl_reset($this->ch);
            curl_setopt_array($this->ch, [
                CURLOPT_URL => "{$this->api}/bot{$this->token}/{$method}",
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($this->ch);
            $this->stats['calls']++; $this->stats['ms'] += (microtime(true) - $t) * 1000;
            $j = is_string($body) ? json_decode($body, true) : null;
            if (!empty($j['ok'])) return is_array($j['result']) ? $j['result'] : ['value' => $j['result']];
            if (is_array($j) && ($j['error_code'] ?? 0) == 429 && $try < 2) {
                sleep(min(10, (int)($j['parameters']['retry_after'] ?? 1)));
                continue;
            }
            if (!is_array($j) || ($j['error_code'] ?? 0) >= 500) {
                if ($try < 1) { usleep(500000); continue; }
                throw new TgNetworkError("Telegram $method failed: " . (is_array($j) ? ($j['description'] ?? '') : curl_error($this->ch)));
            }
            throw new TgApiError("Telegram $method: " . ($j['description'] ?? 'error'));
        }
        throw new TgNetworkError("Telegram $method failed after retries");
    }

    /** Parallel requests. $reqs: key => ['url' => ..., 'post' => array|null]. */
    private function multi(array $reqs, int $timeout = 10): array {
        if (!$reqs) return [];
        $t = microtime(true);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($reqs as $k => $r) {
            $h = curl_init($r['url']);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout]);
            if (isset($r['post'])) curl_setopt($h, CURLOPT_POSTFIELDS, $r['post']);
            curl_multi_add_handle($mh, $h);
            $handles[$k] = $h;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($handles as $k => $h) {
            $out[$k] = ['code' => (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => (string)curl_multi_getcontent($h)];
            curl_multi_remove_handle($mh, $h);
        }
        curl_multi_close($mh);
        $this->stats['calls'] += count($reqs); $this->stats['ms'] += (microtime(true) - $t) * 1000;
        return $out;
    }

    // ------------------------------------------------------------------ local-only collections

    private function localPath(string $coll): string { return $this->tmp . '/local-' . preg_replace('/\W/', '', $coll) . '.json'; }

    private function localRead(string $coll): array {
        $p = $this->localPath($coll);
        return is_file($p) ? (json_decode((string)file_get_contents($p), true) ?: []) : [];
    }

    private function localWrite(string $coll, string $id, ?array $doc): void {
        $fh = fopen($this->localPath($coll), 'c+');
        flock($fh, LOCK_EX);
        $all = json_decode((string)stream_get_contents($fh), true) ?: [];
        if ($doc === null) unset($all[$id]); else $all[$id] = $doc;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($all));
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

class TgApiError extends RuntimeException {}
class TgNetworkError extends RuntimeException {}
