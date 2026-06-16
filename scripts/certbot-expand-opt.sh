#!/bin/bash
# Расширить сертификат motor-force.ru на opt.motor-force.ru (Docker nginx на :80).
# Запуск на сервере: bash scripts/certbot-expand-opt.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
WEBROOT="$ROOT/certbot/www"
CERT="/etc/letsencrypt/live/motor-force.ru/fullchain.pem"

echo "=== 1. Webroot для ACME (opt + основной домен) ==="
mkdir -p "$WEBROOT/.well-known/acme-challenge"
TOKEN="ping-$(date +%s)"
echo ok >"$WEBROOT/.well-known/acme-challenge/$TOKEN"

for HOST in opt.motor-force.ru motor-force.ru; do
  URL="http://${HOST}/.well-known/acme-challenge/${TOKEN}"
  if curl -fsS "$URL" | grep -q ok; then
    echo "OK  $URL"
  else
    echo "FAIL $URL — проверьте DNS (A-запись opt → этот сервер) и nginx."
    exit 1
  fi
done

echo ""
echo "=== 2. Текущий SAN сертификата ==="
if [ -f "$CERT" ]; then
  openssl x509 -in "$CERT" -noout -text 2>/dev/null | grep -A1 "Subject Alternative Name" || true
else
  echo "Сертификат не найден: $CERT"
fi

echo ""
echo "=== 3. Certbot webroot (expand) ==="
if certbot certonly --webroot \
  -w "$WEBROOT" \
  -d motor-force.ru \
  -d www.motor-force.ru \
  -d opt.motor-force.ru \
  --expand \
  --non-interactive; then
  echo "Webroot: успех"
else
  echo ""
  echo "Webroot не удался → standalone (кратко останавливаем Docker nginx)..."
  $COMPOSE stop nginx
  trap '$COMPOSE start nginx' EXIT
  certbot certonly --standalone \
    -d motor-force.ru \
    -d www.motor-force.ru \
    -d opt.motor-force.ru \
    --expand \
    --non-interactive
  $COMPOSE start nginx
  trap - EXIT
fi

echo ""
echo "=== 4. SAN после выпуска ==="
openssl x509 -in "$CERT" -noout -text | grep -A1 "Subject Alternative Name"

if ! openssl x509 -in "$CERT" -noout -text | grep -q "opt.motor-force.ru"; then
  echo "ОШИБКА: opt.motor-force.ru нет в сертификате!"
  exit 1
fi

echo ""
echo "=== 5. Reload Docker nginx ==="
$COMPOSE exec nginx nginx -t
$COMPOSE exec nginx nginx -s reload

echo ""
echo "Готово. Проверка: curl -sI https://opt.motor-force.ru | head -5"
