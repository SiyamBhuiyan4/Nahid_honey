#!/bin/bash
# Launcher script for Nahid Honey E-Commerce Website
cd "$(dirname "$0")"
echo "🍯 Starting Nahid Honey E-Commerce Web Server..."
echo "📍 Document Root: $(pwd)/public"
echo "🌐 URL: http://127.0.0.1:8080"
echo "💬 Telegram CDN & Orders Channel: https://t.me/+T16Rq2v7cRFiNzU1"
php -S 127.0.0.1:8080 -t public
