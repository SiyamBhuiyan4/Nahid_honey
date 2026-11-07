<?php
/**
 * TelegramDB & CDN Engine
 * Uses Telegram Channel (-1004372641845) via Bot API as Database & CDN storage
 */

class TelegramDB {
    private string $token;
    private string $channelId;
    private string $apiBase;
    private string $storageDir;

    public function __construct() {
        $config = require __DIR__ . '/../config/telegram.php';
        $this->token = $config['bot_token'];
        $this->channelId = $config['channel_id'];
        $this->apiBase = rtrim($config['api_base'], '/');
        $this->storageDir = __DIR__;
    }

    /**
     * Send HTTP request to Telegram Bot API
     */
    private function request(string $method, array $params = [], ?string $filePath = null): ?array {
        $url = "{$this->apiBase}/bot{$this->token}/{$method}";
        $ch = curl_init();

        if ($filePath && file_exists($filePath)) {
            $cfile = new CURLFile(realpath($filePath));
            $params['document'] = $cfile;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        if (PHP_VERSION_ID < 80500) {
            @curl_close($ch);
        }

        if (!$response) return null;
        $decoded = json_decode($response, true);
        return $decoded['ok'] ? $decoded['result'] : null;
    }

    /**
     * Post a text message or order alert to Telegram Channel
     */
    public function notifyChannel(string $text): bool {
        $res = $this->request('sendMessage', [
            'chat_id' => $this->channelId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]);
        return !empty($res);
    }

    /**
     * Upload an image/file to Telegram CDN Channel and get public URL / file_id
     */
    public function uploadToCDN(string $filePath, string $caption = ''): ?array {
        if (!file_exists($filePath)) return null;

        $cfile = new CURLFile(realpath($filePath));
        $res = $this->request('sendPhoto', [
            'chat_id' => $this->channelId,
            'photo' => $cfile,
            'caption' => $caption
        ]);

        if ($res && isset($res['photo'])) {
            $photos = $res['photo'];
            $best = end($photos); // highest resolution
            $fileId = $best['file_id'];
            $fileInfo = $this->request('getFile', ['file_id' => $fileId]);
            if ($fileInfo && isset($fileInfo['file_path'])) {
                return [
                    'file_id' => $fileId,
                    'file_path' => $fileInfo['file_path'],
                    'cdn_url' => "{$this->apiBase}/file/bot{$this->token}/{$fileInfo['file_path']}"
                ];
            }
        }
        return null;
    }

    /**
     * LEGACY (read-only): the old database/*.json files. The live data is in
     * the store (app/db.php); these files are only the first-run seed.
     */
    public function getCollection(string $name): array {
        $localPath = "{$this->storageDir}/{$name}.json";
        if (file_exists($localPath)) {
            $data = json_decode(file_get_contents($localPath), true);
            if (is_array($data)) return $data;
        }
        return [];
    }

    /**
     * LEGACY: no longer writes JSON files (they do not persist on Vercel and
     * would drift from the real store). Kept so old callers do not fatal.
     */
    public function saveCollection(string $name, array $data, bool $syncToTelegram = false): bool {
        return false;
    }

    /**
     * LEGACY: delegates to create_order_record() (app/orders.php) so an order
     * is never written to the JSON file. New code should call it directly.
     */
    public function createOrder(array $orderData): array {
        if (!function_exists("create_order_record")) {
            throw new \RuntimeException("Order store unavailable.");
        }
        return create_order_record($orderData, "website");
    }

    /** LEGACY: delegates to find_order_public() (app/orders.php). */
    public function findOrder(string $query): ?array {
        return function_exists("find_order_public") ? find_order_public($query) : null;
    }
}
