#!/usr/bin/env bash
set -euo pipefail

ENDPOINT="${1:-http://95.161.174.134:25555/unf/hs/orders/update}"
DOC="${2:-CASH}"          # CASH | ORDER | INVOICE | BANK | FIXPAYSTATUS
FROM="${3:-2000-01-01}"
TO="${4:-2100-01-01}"
BATCH="${5:-200}"
MAX_OFFSET="${6:-200000}" # верхняя граница, чтобы не крутиться бесконечно
OUT_DIR="${7:-./.repost_out}"
USER="${8:-}"
PASS="${9:-}"

mkdir -p "$OUT_DIR"

echo "ENDPOINT=$ENDPOINT"
echo "DOC=$DOC FROM=$FROM TO=$TO BATCH=$BATCH MAX_OFFSET=$MAX_OFFSET"
echo "OUT_DIR=$OUT_DIR"
if [ -n "$USER" ]; then
  echo "AUTH=basic user=$USER"
else
  echo "AUTH=none (pass USER/PASS as args 8/9)"
fi

# 1) Проверка версии (должна быть 2026-03-13-62 или выше)
echo "== VER =="
ver_tmp="$(mktemp)"
auth_args=()
if [ -n "$USER" ]; then
  auth_args=(-u "${USER}:${PASS}")
fi

# HTTP-сервис 1С часто разрешает только POST, поэтому все запросы шлем POST с пустым телом.
post_args=(-X POST --data '')

ver_code="$(curl -sS --max-time 20 ${auth_args[@]+"${auth_args[@]}"} "${post_args[@]}" -o "$ver_tmp" -w '%{http_code}' \
  -H "Content-Type: application/json" \
  -H "X-MF-Debug: VER" \
  "$ENDPOINT" || true)"
resp="$(sed 's/\r//g' "$ver_tmp" 2>/dev/null || true)"
echo "$resp"
echo
echo "VER http_code=$ver_code bytes=$(wc -c < "$ver_tmp" 2>/dev/null || echo 0)"
cp -f "$ver_tmp" "$OUT_DIR/ver_last.json" 2>/dev/null || true
rm -f "$ver_tmp" || true
echo

# 2) Починка статусов оплат (регистры)
if [ "$DOC" = "FIXPAYSTATUS" ]; then
  off=0
  while [ "$off" -le "$MAX_OFFSET" ]; do
    echo "== fixpaystatus offset=$off =="

    tmp="$(mktemp)"
    code="$(curl -sS --max-time 180 ${auth_args[@]+"${auth_args[@]}"} "${post_args[@]}" -o "$tmp" -w '%{http_code}' \
      -H "Content-Type: application/json" \
      -H "X-MF-Debug: FIXPAYSTATUS" \
      -H "X-MF-From: ${FROM}" \
      -H "X-MF-To: ${TO}" \
      -H "X-MF-Offset: ${off}" \
      -H "X-MF-Limit: ${BATCH}" \
      "$ENDPOINT" || true)"

    resp="$(sed 's/\r//g' "$tmp" 2>/dev/null || true)"
    echo "$resp"
    echo
    echo "http_code=$code bytes=$(wc -c < "$tmp" 2>/dev/null || echo 0)"
    cp -f "$tmp" "$OUT_DIR/fixpaystatus_${off}.json" 2>/dev/null || true
    rm -f "$tmp" || true

    if echo "$resp" | grep -q '"reached_end"[[:space:]]*:[[:space:]]*true'; then
      echo "== done (reached_end=true) =="
      exit 0
    fi

    off=$((off + BATCH))
    sleep 0.2
  done
  echo "== stopped (MAX_OFFSET reached) =="
  exit 0
fi

# 3) Батчевое перепроведение документов
off=0
while [ "$off" -le "$MAX_OFFSET" ]; do
  echo "== batch offset=$off =="

  tmp="$(mktemp)"
  code="$(curl -sS --max-time 180 ${auth_args[@]+"${auth_args[@]}"} "${post_args[@]}" -o "$tmp" -w '%{http_code}' \
    -H "Content-Type: application/json" \
    -H "X-MF-Debug: REPOSTPAY" \
    -H "X-MF-Doc: ${DOC}" \
    -H "X-MF-From: ${FROM}" \
    -H "X-MF-To: ${TO}" \
    -H "X-MF-Offset: ${off}" \
    -H "X-MF-Limit: ${BATCH}" \
    "$ENDPOINT" || true)"

  resp="$(sed 's/\r//g' "$tmp" 2>/dev/null || true)"
  echo "$resp"
  echo
  echo "http_code=$code bytes=$(wc -c < "$tmp" 2>/dev/null || echo 0)"
  cp -f "$tmp" "$OUT_DIR/repost_${DOC}_${off}.json" 2>/dev/null || true
  rm -f "$tmp" || true

  # остановка, если reached_end=true
  if echo "$resp" | grep -q '"reached_end"[[:space:]]*:[[:space:]]*true'; then
    echo "== done (reached_end=true) =="
    exit 0
  fi

  off=$((off + BATCH))
  sleep 0.2
done

echo "== stopped (MAX_OFFSET reached) =="
exit 0
