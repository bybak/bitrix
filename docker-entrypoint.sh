#!/usr/bin/env sh
set -eu

# Configure msmtp if SMTP env vars are present.
# This enables Bitrix (PHP mail()) to actually send emails from the container.
#
# Supported env:
# - MF_SMTP_HOST
# - MF_SMTP_PORT (default 587)
# - MF_SMTP_USER
# - MF_SMTP_PASS
# - MF_SMTP_FROM (envelope-from)
# - MF_SMTP_TLS ("1"/"0", default 1)
#
# For local dev you can use Mailhog:
#   MF_SMTP_HOST=mailhog
#   MF_SMTP_PORT=1025
#   MF_SMTP_TLS=0

if [ "${MF_SMTP_HOST:-}" != "" ]; then
  PORT="${MF_SMTP_PORT:-587}"
  TLS="${MF_SMTP_TLS:-1}"
  FROM="${MF_SMTP_FROM:-}"

  # www-data home in this image is /var/www
  HOME_DIR="${HOME:-/var/www}"
  CONF="${HOME_DIR}/.msmtprc"

  {
    echo "defaults"
    echo "logfile /tmp/msmtp.log"
    echo "account default"
    echo "host ${MF_SMTP_HOST}"
    echo "port ${PORT}"

    if [ "${TLS}" = "0" ] || [ "${TLS}" = "false" ]; then
      echo "tls off"
    else
      echo "tls on"
      echo "tls_starttls on"
    fi

    if [ "${MF_SMTP_USER:-}" != "" ]; then
      echo "auth on"
      echo "user ${MF_SMTP_USER}"
    else
      echo "auth off"
    fi

    if [ "${MF_SMTP_PASS:-}" != "" ]; then
      echo "password ${MF_SMTP_PASS}"
    fi

    if [ "${FROM}" != "" ]; then
      echo "from ${FROM}"
    fi
  } > "${CONF}"

  chmod 600 "${CONF}" || true
fi

exec "$@"

