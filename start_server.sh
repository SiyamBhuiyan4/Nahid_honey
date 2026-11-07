#!/bin/bash
# Local dev server for Nahid Honey. Uses the same front controller as Vercel
# (api/index.php), so routing, 404s and blocked paths behave like production.
#   ./start_server.sh            -> http://127.0.0.1:8080
#   PORT=9000 ./start_server.sh
# Data: storage/store.sqlite by default. Set STORE_DRIVER=telegram or
# DATABASE_URL=postgres://... to use another store (see README.md).
cd "$(dirname "$0")" || exit 1
if [ -f .env ]; then set -a; . ./.env; set +a; fi
PORT="${PORT:-8080}"
echo "Nahid Honey dev server: http://127.0.0.1:${PORT}  (admin: http://127.0.0.1:${PORT}/admin/)"
exec php -S "127.0.0.1:${PORT}" api/index.php
