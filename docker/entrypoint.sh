#!/usr/bin/env bash
# Boots Apache only after it knows the public base URL: picks a URL from the
# tunnel backend named by TUNNEL_DISCOVERY, writes it (and derived ATProto
# values) into the mounted .env, then execs Apache.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/var/www/html/.env}"

# cloudflared (uid 65532) must be able to write its quick-tunnel log; php runs
# as root, so fix ownership before cloudflared (depends_on: php) writes.
if [ -n "${TUNNEL_LOG_FILE:-}" ]; then
  chown -R 65532:65532 "$(dirname "$TUNNEL_LOG_FILE")" 2>/dev/null || true
fi

# .env is a bind mount: rewrite in place (same inode) rather than `sed -i`,
# which would rename over the mount and fail with "Device or resource busy".
apply_or_overwrite_env() {
  local key="$1" value="$2" file="$3"
  if grep -q "^${key}=" "$file" 2>/dev/null; then
    sed -e "s|^${key}=.*|${key}=${value}|" "$file" > "$file.new" \
      && cat "$file.new" > "$file" \
      && rm -f "$file.new"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

discover_base_url() {
  BASE_URL=""
  local discovery="${TUNNEL_DISCOVERY:-static}"
  case "$discovery" in
    static) BASE_URL="${TUNNEL_PUBLIC_URL:-}" ;;
    http)   BASE_URL="$(curl -fsS "${TUNNEL_URL_ENDPOINT:?TUNNEL_URL_ENDPOINT required}" | head -n1)" ;;
    file)   until [ -s "${TUNNEL_URL_FILE:?TUNNEL_URL_FILE required}" ] 2>/dev/null; do sleep 2; done
            BASE_URL="$(head -n1 "${TUNNEL_URL_FILE}")" ;;
    ngrok)  until curl -fsS "${TUNNEL_API_URL:?TUNNEL_API_URL required}" 2>/dev/null | grep -q '"public_url"'; do sleep 2; done
            BASE_URL="$(curl -fsS "${TUNNEL_API_URL}" | grep -o '"public_url":"[^"]*"' | head -n1 | cut -d'"' -f4)" ;;
    log)    until [ -f "${TUNNEL_LOG_FILE:?TUNNEL_LOG_FILE required}" ] \
                && grep -qio 'https://[a-zA-Z0-9.-]*\.trycloudflare\.com' "$TUNNEL_LOG_FILE" 2>/dev/null; do
              sleep 2
            done
            BASE_URL="$(grep -io 'https://[a-zA-Z0-9.-]*\.trycloudflare\.com' "$TUNNEL_LOG_FILE" | head -n1)" ;;
    *)      echo "unknown TUNNEL_DISCOVERY '${discovery}'" >&2; exit 1 ;;
  esac
}

discover_base_url

if [ -z "${BASE_URL:-}" ]; then
  echo "[coordinator] no tunnel URL; leaving .env as-is"
else
  BASE_URL="${BASE_URL%/}/"
  [ -f "$ENV_FILE" ] || touch "$ENV_FILE"
  apply_or_overwrite_env "BASE_URL" "$BASE_URL" "$ENV_FILE"
  if ! grep -q '^ATPROTO_CLIENT_ID=' "$ENV_FILE" 2>/dev/null || [ -z "$(grep '^ATPROTO_CLIENT_ID=' "$ENV_FILE" | cut -d= -f2-)" ]; then
    apply_or_overwrite_env "ATPROTO_CLIENT_ID" "${BASE_URL}id" "$ENV_FILE"
  fi
  if ! grep -q '^ATPROTO_REDIRECT_URI=' "$ENV_FILE" 2>/dev/null || [ -z "$(grep '^ATPROTO_REDIRECT_URI=' "$ENV_FILE" | cut -d= -f2-)" ]; then
    apply_or_overwrite_env "ATPROTO_REDIRECT_URI" "${BASE_URL}redirect/atproto" "$ENV_FILE"
  fi
  echo "[coordinator] BASE_URL set to ${BASE_URL}"
fi

exec "$@"
