#!/usr/bin/env bash
# Preparação única do ambiente (Codespaces / Dev Containers).
set -euo pipefail

[ -f .env ] || cp .env.example .env
composer install --no-interaction --no-progress
grep -q '^APP_KEY=base64' .env || php artisan key:generate --ansi

echo "Aguardando o MySQL..."
for _ in $(seq 1 60); do
  mysqladmin ping -h "${DB_HOST:-127.0.0.1}" -u"${DB_USERNAME:-tripflow}" -p"${DB_PASSWORD:-secret}" --silent 2>/dev/null && break
  sleep 2
done

php artisan migrate:fresh --seed --force
echo "Ambiente pronto. Teste com: bash scripts/demo.sh"
