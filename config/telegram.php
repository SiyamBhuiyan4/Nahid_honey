<?php
// Telegram CDN & Database Configuration.
// Environment variables (TELEGRAM_BOT_TOKEN, TELEGRAM_CHANNEL_ID, TELEGRAM_CHANNEL_LINK)
// take priority; the values below are the fallback so existing setups keep working.
return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '8891159487:AAHca28svvJzlc79zShH2PK6_Mxe9HGZRm4',
    'channel_id' => getenv('TELEGRAM_CHANNEL_ID') ?: '-1004372641845',
    'channel_link' => getenv('TELEGRAM_CHANNEL_LINK') ?: 'https://t.me/+T16Rq2v7cRFiNzU1',
    'api_base' => getenv('TELEGRAM_API_BASE') ?: 'https://api.telegram.org',
];
