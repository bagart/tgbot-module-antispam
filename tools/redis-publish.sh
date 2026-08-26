#!/usr/bin/env bash
set -eu
NET=$(docker inspect telegram-bot-platform-redis-1 --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}')
echo "net=$NET"
docker run -d --rm --name tmp-redis-pub --network "$NET" -p 127.0.0.1:16379:6379 alpine/socat tcp-listen:6379,fork,reuseaddr tcp-connect:telegram-bot-platform-redis-1:6379
sleep 2
(timeout 3 bash -c "exec 3<>/dev/tcp/127.0.0.1/16379" && echo REDIS_VIA_SOCAT_OK) || echo REDIS_VIA_SOCAT_FAIL
