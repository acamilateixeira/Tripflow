#!/usr/bin/env bash
# Sobe a API e os processos de fundo (relay da outbox, consumidor e worker).
# Logs em storage/logs/*.log
set -uo pipefail
mkdir -p storage/logs

start() {
  local name=$1; shift
  if ! pgrep -f "artisan $1" >/dev/null; then
    nohup php artisan "$@" > "storage/logs/$name.log" 2>&1 &
    echo "  $name iniciado"
  fi
}

start api serve --host=0.0.0.0 --port=8000
start outbox-relay outbox:relay
start consumer events:consume
start worker queue:work redis --tries=3
echo "API em http://localhost:8000/api/health"
