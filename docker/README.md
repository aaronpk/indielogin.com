# Docker setup

Run IndieLogin as a self-hosted stack behind a **Cloudflare quick tunnel** — a public HTTPS URL with zero provisioning (no domain, DNS, or Cloudflare account). Meant for local experimentation: clone → `up -d --build` → open the printed URL.

> **Why a public URL?** IndieLogin's OAuth and ATProto/Bluesky flows fetch the
> `client_id` metadata document (`/id`) from external providers over HTTPS.
> A `localhost` `BASE_URL` isn't enough.

## Stack

| Service | Container | Image | Role |
|---|---|---|---|
| `php` | `indielogin-app` | `php:8.3-apache` (custom) | app + tunnel coordinator |
| `mysql` | `indielogin-db` | `mysql:8.4` | DB; schema auto-loaded on first boot |
| `redis` | `indielogin-redis` | `redis:7-alpine` | auth-code store |
| `cloudflared` | `indielogin-tunnel` | `cloudflare/cloudflared` | public HTTPS origin → `php:80` |

## Prerequisites

- Docker with Compose v2
- **No** Cloudflare account/domain/token (quick tunnel)
- Optional per-provider secrets in `.env`: `GITHUB_*`, `GITLAB_*`, `CODEBERG_*`, `MAILGUN_*`

## Quick start

```sh
cp .env.example .env          # paste any provider secrets
docker compose up -d --build  # first boot loads schema/*.sql automatically
grep '^BASE_URL=' .env        # the public URL to open
```

## How the coordinator sets BASE_URL

The `php` entrypoint (`docker/entrypoint.sh`) boots Apache only **after** it has a public URL. It watches the shared log `/shared/tunnel.log` (written by `cloudflared`), greps the `trycloudflare.com` URL, writes it to the mounted `.env` (`BASE_URL`, plus derived `ATPROTO_CLIENT_ID`/`ATPROTO_REDIRECT_URI` unless you pinned them), then `exec apache2-foreground`.

Two gotchas worth knowing:

- **`BASE_URL` must not be in the container env.** The app loads `.env` via `Dotenv::createImmutable()`, which refuses to override a real env var. So compose has **no** `env_file:`/`environment: BASE_URL`; it lives only in the mounted `.env`.
- **`.env` is a bind mount**, so the coordinator edits it *in place* (`sed`/`cat`) rather than `sed -i` (which would fail with `Device or resource busy`).

The `cloudflared` image runs as uid `65532`; the coordinator chowns `/shared` to that uid so cloudflared can write its log.

## Config

`TUNNEL_DISCOVERY` selects the URL source (default `log`):

| Value | Source | Variable |
|---|---|---|
| `log` (default) | `trycloudflare.com` URL in shared log | `TUNNEL_LOG_FILE` = `/shared/tunnel.log` |
| `static` | used verbatim (named tunnel) | `BASE_URL` |
| `http` | an endpoint | `TUNNEL_URL_ENDPOINT` |
| `file` | first line of a file | `TUNNEL_URL_FILE` |
| `ngrok` | ngrok local API | `TUNNEL_API_URL` |

DB/Redis are wired in compose to the service names and the docker MySQL user, overriding `.env` (`DB_HOST=mysql`, `REDIS_URL=tcp://redis:6379`). The MySQL container forbids `MYSQL_USER=root`, so compose uses a dedicated app user (`indielogin`) shared into `php` as `DB_USER`/`DB_PASS`.

## Health

```sh
docker compose ps                         # mysql/redis healthy
BASE="$(grep '^BASE_URL=' .env | cut -d= -f2)"
curl -sS "$BASE/health"                  # {"mysql":"OK","redis":"OK"}
curl -sS "$BASE/id"                       # OAuth client metadata; client_id = <BASE_URL>/id
```

## Caveats

- **The URL rotates on every `cloudflared` start** (restart, `up -d`, reboot) → new `trycloudflare.com` hostname. Externally-registered clients (ATProto/Bluesky) and cached browsers must use the current `BASE_URL`. Great for experimentation, not production.
- Quick tunnels **never appear in the Cloudflare dashboard**; they're not linked to an account. Use a named tunnel (`static`, `tunnel run --token`) if you need a stable hostname or dashboard control.
- `CLIENT_REGISTRATION=false` to restrict client-ID registration.
- Port `8080:80` is the local debug path; TLS comes from the tunnel.

## Troubleshooting

- **`php` spins "waiting for a trycloudflare URL"** — expected until the log has a URL. If it never appears, rebuild: `docker compose build php && docker compose up -d --force-recreate php` (an old image may lack the entrypoint or the in-place write).
- **`sed: cannot rename … Device or resource busy`** — old image. Rebuild as above.
- **`Incorrect Usage: flag needs an argument: -token`** — you switched to a named tunnel but left `TUNNEL_TOKEN=` empty.
- **Requests missing from `docker logs indielogin-app`** — you're using a stale (rotated) URL; re-read `BASE_URL` from `.env`.
