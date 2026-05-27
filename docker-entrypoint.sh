#!/usr/bin/env sh
set -eu

# Каталоги для кеша и upload_tmp (проверка системы Битрикс).
DOC_ROOT="${DOC_ROOT:-/var/www/html}"
for d in \
  "$DOC_ROOT/bitrix/cache" \
  "$DOC_ROOT/bitrix/managed_cache" \
  "$DOC_ROOT/bitrix/tmp" \
  "$DOC_ROOT/bitrix/upload_tmp"
do
  mkdir -p "$d" 2>/dev/null || true
done

# Configure msmtp if SMTP env vars are present.
# This enables Bitrix (PHP mail()) to actually send emails from the container.
#
# Supported env:
# - MF_SMTP_HOST
# - MF_SMTP_PORT (default 587)
# - MF_SMTP_TLS ("1"/"0", default 1)
# - MF_SMTP_EHLO_DOMAIN (optional; EHLO/Message-ID domain)
# Профили отправителя (Mail.ru: From = user SMTP):
# - MF_SMTP_USER_ANDREY / MF_SMTP_PASS_ANDREY / MF_SMTP_FROM_ANDREY — письма клиентам
# - MF_SMTP_USER_ROBOT / MF_SMTP_PASS_ROBOT / MF_SMTP_FROM_ROBOT — письма администратору
# Legacy (один ящик): MF_SMTP_USER, MF_SMTP_PASS, MF_SMTP_FROM
#
# Уведомление о новом заказе (mf_order_notify.php):
# - MF_ORDER_NOTIFY_EMAIL (default andrey@motor-force.ru if unset)
# - MF_ORDER_NOTIFY_ENABLED=0 to disable
#
# For local dev you can use Mailhog:
#   MF_SMTP_HOST=mailhog
#   MF_SMTP_PORT=1025
#   MF_SMTP_TLS=0

if [ "${MF_SMTP_HOST:-}" != "" ]; then
  PORT="${MF_SMTP_PORT:-587}"
  TLS="${MF_SMTP_TLS:-1}"

  ANDREY_USER="${MF_SMTP_USER_ANDREY:-${MF_SMTP_USER:-}}"
  ANDREY_PASS="${MF_SMTP_PASS_ANDREY:-${MF_SMTP_PASS:-}}"
  ANDREY_FROM="${MF_SMTP_FROM_ANDREY:-${MF_SMTP_FROM:-}}"
  ROBOT_USER="${MF_SMTP_USER_ROBOT:-}"
  ROBOT_PASS="${MF_SMTP_PASS_ROBOT:-}"
  ROBOT_FROM="${MF_SMTP_FROM_ROBOT:-}"

  # www-data home in this image is /var/www
  HOME_DIR="${HOME:-/var/www}"
  CONF="${HOME_DIR}/.msmtprc"

  EHLO_DOMAIN="${MF_SMTP_EHLO_DOMAIN:-}"
  if [ -z "${EHLO_DOMAIN}" ] && [ -n "${ANDREY_FROM}" ]; then
    EHLO_DOMAIN="${ANDREY_FROM#*@}"
  fi
  if [ -z "${EHLO_DOMAIN}" ] && [ -n "${ROBOT_FROM}" ]; then
    EHLO_DOMAIN="${ROBOT_FROM#*@}"
  fi
  if [ -z "${EHLO_DOMAIN}" ]; then
    EHLO_DOMAIN="motor-force.ru"
  fi

  {
    echo "defaults"
    echo "logfile /tmp/msmtp.log"
    echo "host ${MF_SMTP_HOST}"
    echo "port ${PORT}"
    if [ "${TLS}" = "0" ] || [ "${TLS}" = "false" ]; then
      echo "tls off"
    else
      echo "tls on"
      echo "tls_starttls on"
    fi
    echo "domain ${EHLO_DOMAIN}"

    if [ -n "${ANDREY_USER}" ]; then
      echo "account andrey"
      echo "auth on"
      echo "user ${ANDREY_USER}"
      if [ -n "${ANDREY_PASS}" ]; then
        echo "password ${ANDREY_PASS}"
      fi
      if [ -n "${ANDREY_FROM}" ]; then
        echo "from ${ANDREY_FROM}"
      fi
    fi

    if [ -n "${ROBOT_USER}" ]; then
      echo "account robot"
      echo "auth on"
      echo "user ${ROBOT_USER}"
      if [ -n "${ROBOT_PASS}" ]; then
        echo "password ${ROBOT_PASS}"
      fi
      if [ -n "${ROBOT_FROM}" ]; then
        echo "from ${ROBOT_FROM}"
      fi
    fi

    if [ -n "${ANDREY_USER}" ]; then
      echo "account default : andrey"
    elif [ -n "${ROBOT_USER}" ]; then
      echo "account default : robot"
    else
      echo "account default"
      echo "auth off"
    fi
  } > "${CONF}"

  chmod 600 "${CONF}" || true
fi

exec "$@"

