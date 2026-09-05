#!/usr/bin/env bash
# Antispam webhook load run (todo.antispam.md final phase).
# Runs everything in ONE session: server lifecycle + baseline + load phases.
set -u

export DB_HOST=127.0.0.1
export REDIS_HOST=127.0.0.1
export REDIS_PORT=16379
export TG_WEBHOOK_ALLOW_LOCAL_IPS=true
export ANTISPAM_EXCLUDE_USER_IDS=424242   # suppress outbound Telegram calls (no network in this env)
export PHP_CLI_SERVER_WORKERS=8

TOOLS=misc/BAGArt/tgbot-module-antispam/tools

pkill -f "artisan serve" 2>/dev/null || true
pkill -f "server.php" 2>/dev/null || true
sleep 1

# Direct php -S with opcache: artisan serve does not enable it, which caps
# throughput far below production php-fpm numbers. server.php resolves the
# app relative to cwd, so serve from public/.
(cd public && PHP_CLI_SERVER_WORKERS=12 php -d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.memory_consumption=128 \
    -S 127.0.0.1:8088 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > /tmp/serve.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT

for i in $(seq 1 20); do
  CODE=$(curl -s -m 2 -o /dev/null -w '%{http_code}' http://127.0.0.1:8088/up || true)
  [ "$CODE" = "200" ] && break
  sleep 1
done
if [ "${CODE:-}" != "200" ]; then
  echo "server failed to start"; tail -5 /tmp/serve.log; exit 1
fi
echo "== server up =="

echo "== BASELINE (300 msg/min) =="
php "$TOOLS/load-webhook.php" --url=http://127.0.0.1:8088 --bot=load_bot --chat=-1001234 --rate=300 --duration=15

echo "== LOAD (1000 msg/min) =="
php "$TOOLS/load-webhook.php" --url=http://127.0.0.1:8088 --bot=load_bot --chat=-1001234 --rate=1000 --duration=45

echo "== CONTROL /up at load rate (framework floor) =="
php "$TOOLS/load-control.php" --url=http://127.0.0.1:8088 --rate=1000 --duration=20

echo "== done =="