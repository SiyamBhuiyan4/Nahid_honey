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
     * Load JSON collection with local cache fallback
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
     * Save collection locally and sync snapshot to Telegram Channel
     */
    public function saveCollection(string $name, array $data, bool $syncToTelegram = false): bool {
        $localPath = "{$this->storageDir}/{$name}.json";
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $written = file_put_contents($localPath, $json);

        if ($written && $syncToTelegram) {
            $this->notifyChannel("📦 <b>Database Sync: {$name}</b>\nItems Count: " . count($data) . "\nTimestamp: " . date('Y-m-d H:i:s'));
        }

        return $written !== false;
    }

    /**
     * Store new Customer Order and broadcast to Telegram
     */
    public function createOrder(array $orderData): array {
        $orders = $this->getCollection('orders');
        $orderId = 'NH-' . strtoupper(substr(uniqid(), -6));
        $orderData['order_id'] = $orderId;
        $orderData['created_at'] = date('Y-m-d H:i:s');
        $orderData['status'] = 'Processing';

        $orders[] = $orderData;
        $this->saveCollection('orders', $orders, false);

        // Format Telegram Notification message
        $itemsText = "";
        foreach ($orderData['items'] as $item) {
            $itemsText .= "• {$item['title']} (x{$item['quantity']}) - ৳{$item['price']}\n";
        }

        $msg = "🔔 <b>New Order Received! [{$orderId}]</b>\n\n";
        $msg .= "👤 <b>Customer:</b> " . htmlspecialchars($orderData['name']) . "\n";
        $msg .= "📞 <b>Phone:</b> " . htmlspecialchars($orderData['phone']) . "\n";
        $msg .= "📍 <b>Address:</b> " . htmlspecialchars($orderData['address']) . "\n";
        $msg .= "🚚 <b>Zone:</b> " . htmlspecialchars($orderData['zone']) . " (৳{$orderData['delivery_fee']})\n";
        $msg .= "💳 <b>Payment:</b> " . htmlspecialchars($orderData['payment_method']) . "\n\n";
        $msg .= "🛒 <b>Ordered Items:</b>\n{$itemsText}\n";
        $msg .= "💰 <b>Total Payable: ৳" . number_format($orderData['total']) . "</b>";

        $this->notifyChannel($msg);

        return $orderData;
    }

    /**
     * Track Order by ID or Phone
     */
    public function findOrder(string $query): ?array {
        $orders = $this->getCollection('orders');
        $query = trim(strtolower($query));
        foreach (array_reverse($orders) as $order) {
            if (strtolower($order['order_id']) === $query || strtolower($order['phone']) === $query) {
                return $order;
            }
        }
        return null;
    }
}
