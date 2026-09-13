#!/usr/bin/env bash
#
# Tunnel-coordinator entrypoint.
#
# IndieLogin needs a public HTTPS base URL (it is baked into OAuth client_id,
# redirect URIs and the `iss` value, and ATProto/Bluesky fetch it over HTTPS).
# Under docker-compose the public URL often is not known until a tunnel starts,
# so this entrypoint:
#
#   1. discovers the public base URL from whichever tunnel backend is in use,
#   2. writes it into /var/www/html/.env (BASE_URL + derived ATProto values),
#   3. then starts Apache.
#
# It deliberately does NOT export the discovered URL into the shell env. The
# app loads .env per request with Dotenv::createImmutable(), which would REFUSE
# to overwrite a var already present in the real environment. So BASE_URL must
# be left out of the compose env (no env_file / environment entry) and only
# live in the mounted .env, which the app then reads via getenv().
set -euo pipefail

ENV_FILE="${ENV_FILE:-/var/www/html/.env}"

# The cloudflared image runs as a non-root user (uid 65532). When that
# container writes its quick-tunnel URL to a shared volume, the volume must be
# writable by that uid. This php image runs as root, so fix the ownership here
# (before Apache starts); cloudflared is ordered to start after php via
# depends_on, so the chown is in place before it writes.
if [ -n "${TUNNEL_LOG_FILE:-}" ]; then
  chown -R 65532:65532 "$(dirname "$TUNNEL_LOG_FILE")" 2>/dev/null || true
fi

apply_or_overwrite_env() {
  local key="$1" value="$2" file="$3"
  # .env is a bind mount, so `sed -i` (which renames a temp file over it) fails
  # with "Device or resource busy". Rewrite in place on the SAME inode instead:
  # sed to a temp file, then `cat temp > file` truncates+rewrites the existing
  # inode (works on bind mounts). Append when the key is absent.
  if grep -q "^${key}=" "$file" 2>/dev/null; then
    sed -e "s|^${key}=.*|${key}=${value}|" "$file" > "$file.new" \
      && cat "$file.new" > "$file" \
      && rm -f "$file.new"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

# Wait (optionally) for a readiness endpoint before declaring the tunnel live.
wait_for_ready() {
  local url="${1:-}"
  if [ -n "$url" ]; then
    echo "[coordinator] waiting for readiness: ${url}"
    until curl -fsS "$url" >/dev/null 2>&1; do
      sleep 2
    done
    echo "[coordinator] readiness OK: ${url}"
  fi
}

# Discover the public base URL. The exact source is chosen by TUNNEL_DISCOVERY,
# so any tunnel backend can be plugged in:
#   static  -> TUNNEL_PUBLIC_URL (named/static tunnels, e.g. `tunnel run --token`)
#   http    -> GET TUNNEL_URL_ENDPOINT, first line is the public base URL
#   file    -> first line of the shared file TUNNEL_URL_FILE
#   ngrok   -> query the ngrok local API TUNNEL_API_URL for public_url
#   log     -> grep a public URL out of the shared tunnel log TUNNEL_LOG_FILE
discover_base_url() {
  BASE_URL=""
  local discovery="${TUNNEL_DISCOVERY:-static}"

  case "$discovery" in
    static)
      wait_for_ready "${TUNNEL_READY_ENDPOINT:-}"
      BASE_URL="${TUNNEL_PUBLIC_URL:-}"
      ;;
    http)
      wait_for_ready "${TUNNEL_READY_ENDPOINT:-${TUNNEL_URL_ENDPOINT:-}}"
      BASE_URL="$(curl -fsS "${TUNNEL_URL_ENDPOINT:?TUNNEL_URL_ENDPOINT required (TUNNEL_DISCOVERY=http)}" | head -n1)"
      ;;
    file)
      wait_for_ready "${TUNNEL_READY_ENDPOINT:-}"
      local f="${TUNNEL_URL_FILE:?TUNNEL_URL_FILE required (TUNNEL_DISCOVERY=file)}"
      until [ -s "$f" ] 2>/dev/null; do
        echo "[coordinator] waiting for ${f} to contain a URL..."
        sleep 2
      done
      BASE_URL="$(head -n1 "$f")"
      ;;
    ngrok)
      wait_for_ready "${TUNNEL_READY_ENDPOINT:-}"
      local api="${TUNNEL_API_URL:?TUNNEL_API_URL required (TUNNEL_DISCOVERY=ngrok)}"
      until curl -fsS "$api" 2>/dev/null | grep -q '"public_url"'; do
        echo "[coordinator] waiting for ngrok URL..."
        sleep 2
      done
      BASE_URL="$(curl -fsS "$api" | grep -o '"public_url":"[^"]*"' | head -n1 | cut -d'"' -f4)"
      ;;
    log)
      wait_for_ready "${TUNNEL_READY_ENDPOINT:-}"
      local f="${TUNNEL_LOG_FILE:?TUNNEL_LOG_FILE required (TUNNEL_DISCOVERY=log)}"
      until [ -f "$f" ] && grep -qio 'https://[a-zA-Z0-9.-]*\.trycloudflare\.com' "$f" 2>/dev/null; do
        echo "[coordinator] waiting for a trycloudflare URL in ${f}..."
        sleep 2
      done
      BASE_URL="$(grep -io 'https://[a-zA-Z0-9.-]*\.trycloudflare\.com' "$f" | head -n1)"
      ;;
    *)
      echo "[coordinator] ERROR: unknown TUNNEL_DISCOVERY '${discovery}'" >&2
      exit 1
      ;;
  esac
}

# --- Configure the app -------------------------------------------------------
discover_base_url

if [ -z "${BASE_URL:-}" ]; then
  echo "[coordinator] no tunnel URL discovered; leaving .env as-is (BASE_URL must already be set)."
else
  BASE_URL="${BASE_URL%/}/"
  [ -f "$ENV_FILE" ] || touch "$ENV_FILE"
  apply_or_overwrite_env "BASE_URL" "$BASE_URL" "$ENV_FILE"

  # ATProto client/redirect are derived from the base URL unless pinned.
  if ! grep -q '^ATPROTO_CLIENT_ID=' "$ENV_FILE" 2>/dev/null || [ -z "$(grep '^ATPROTO_CLIENT_ID=' "$ENV_FILE" | cut -d= -f2-)" ]; then
    apply_or_overwrite_env "ATPROTO_CLIENT_ID" "${BASE_URL}id" "$ENV_FILE"
  fi
  if ! grep -q '^ATPROTO_REDIRECT_URI=' "$ENV_FILE" 2>/dev/null || [ -z "$(grep '^ATPROTO_REDIRECT_URI=' "$ENV_FILE" | cut -d= -f2-)" ]; then
    apply_or_overwrite_env "ATPROTO_REDIRECT_URI" "${BASE_URL}redirect/atproto" "$ENV_FILE"
  fi
  echo "[coordinator] BASE_URL set to ${BASE_URL}"
fi

echo "--- ${ENV_FILE} ---"
grep -E '^(BASE_URL|ATPROTO_)=' "$ENV_FILE" 2>/dev/null || true
echo "------------------------"

exec "$@"
