# Docker + Cloudflare Tunnel setup

This deploys IndieLogin as a self-hosted stack behind a **Cloudflare quick
tunnel** so it works from a public HTTPS URL with **zero provisioning** — no
domain, no DNS, no Cloudflare dashboard, no account credentials. It is aimed at
local experimentation: clone, run `docker compose up -d --build`, and you get a
fully working IndieLogin instance reachable at a public `https://…trycloudflare.com`
URL.

> **Why a public URL?** IndieLogin's OAuth and ATProto/Bluesky flows route back
> to the app from external providers. Those providers fetch the `client_id`
> metadata document (served at `/id`) and validate it **over HTTPS**. A local
> `localhost` base URL is not enough — `BASE_URL` must be a real,
> publicly-reachable HTTPS hostname.

---

## Table of contents

- [Architecture](#architecture)
- [Prerequisites](#prerequisites)
- [Quick start](#quick-start)
- [How the tunnel discovery works](#how-the-tunnel-discovery-works)
- [Configuration](#configuration)
- [Tunnel discovery backends](#tunnel-discovery-backends)
- [Checking it's healthy](#checking-its-healthy)
- [Stability & caveats](#stability--caveats)
- [Troubleshooting](#troubleshooting)

---

## Architecture

| Service       | Container        | Base image                       | Purpose                                            |
|---------------|------------------|----------------------------------|----------------------------------------------------|
| `php`         | `indielogin-app` | `php:8.3-apache` (custom)       | The IndieLogin app + its tunnel-coordinator        |
| `mysql`       | `indielogin-db`  | `mysql:8.4`                      | Database; schema auto-loaded on first boot         |
| `redis`       | `indielogin-redis`| `redis:7-alpine`               | Temporary authorization-code store                 |
| `cloudflared` | `indielogin-tunnel`| `cloudflare/cloudflared:latest`| Public HTTPS origin → the `php` container          |

All containers share one private Docker network (`internal`) so they address each
other by service name. The only two ports exposed to the host are `8080` (the
`php` app, for local debugging) and `6379`/none for the others (not published).

```
  internet
      │  HTTPS (trycloudflare.com)
      ▼
 cloudflared  ──(internal net, hostname: php:80)──▶  php
                                                      ┌───────► mysql (localhost net: `mysql`)
                                                      └───────► redis (localhost net: `redis`)
```

---

## Prerequisites

- **Docker** with **Docker Compose v2** (`docker compose`, not `docker-compose`).
- **No** Cloudflare account, domain, or token required for the quick-tunnel path.
- **Provider OAuth credentials** (optional per provider) if you want that
  provider to work — IndieLogin *consumes* these; it does not create them:
  - GitHub `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET`
  - GitLab `GITLAB_CLIENT_ID` / `GITLAB_CLIENT_SECRET`
  - Codeberg `CODEBERG_CLIENT_ID` / `CODEBERG_CLIENT_SECRET`
  - Mailgun `MAILGUN_KEY` / `MAILGUN_DOMAIN` / `MAILGUN_FROM`

---

## Quick start

```sh
# 1. Clone (or you're already here) and prepare the environment file
cp .env.example .env

# 2. (Optional) paste any provider secrets into .env

# 3. Build and start
docker compose up -d --build

# 4. Find the public URL
grep '^BASE_URL=' .env          # or: docker compose exec php grep BASE_URL /var/www/html/.env
```

Open that URL in your browser. The app should load and, on first boot, the
database schema is applied automatically (MySQL runs `schema/*.sql` from its
init directory).

---

## How the tunnel discovery works

The `php` container does **not** boot Apache immediately. Its entrypoint
(`docker/entrypoint.sh`) runs as a tiny **coordinator** that:

1. **Waits** for the tunnel to produce a public URL. With the default
   (`TUNNEL_DISCOVERY=log`) it watches the shared log file
   `/shared/tunnel.log`, which `cloudflared` writes.
2. **Writes** that URL into the mounted `.env`:
   - `BASE_URL`
   - `ATPROTO_CLIENT_ID` (= `<base>/id`) and `ATPROTO_REDIRECT_URI`
     (= `<base>/redirect/atproto`) — unless you already pinned those.
3. **Boots Apache** (`exec apache2-foreground`).

The coordinator deliberately does **not** export the URL into the container's
process environment. The app loads its config per request with
`Dotenv::createImmutable()`, which *refuses* to overwrite a variable that is
already present in the real environment. So `BASE_URL` is left **out** of the
compose `environment`/`env_file`, and lives only in the mounted `.env`, which
the app then reads through `getenv()`.

**Why the file must be writable in place:** `.env` is a *bind mount* (the host
file), so it can't be atomically replaced with a rename. The coordinator edits
it **in place on the same inode** (via `sed`/`cat`) — a naive `sed -i` fails
with `Device or resource busy`, which is a common gotcha.

**Why the shared volume is chowned:** the `cloudflared` image runs as a
non-root user (uid `65532`). The coordinator (running as root in the `php`
container) chowns `/shared` to `65532` before cloudflared writes its log.

---

## Configuration

The root `.env.example` is the **single source of truth** — there is no
docker-specific `.env`. Compose reads `.env` for interpolation; the `php`
service mounts it read-write so the coordinator can set `BASE_URL`.

Key variables:

| Variable              | Used by            | Notes                                                        |
|-----------------------|--------------------|--------------------------------------------------------------|
| `BASE_URL`            | app / coordinator  | Overwritten automatically on `up` from the quick-tunnel URL  |
| `TUNNEL_DISCOVERY`    | coordinator        | `log` (default), `static`, `http`, `file`, `ngrok` (see below)|
| `TUNNEL_LOG_FILE`     | coordinator        | Shared log path to watch (`/shared/tunnel.log`)              |
| `DB_HOST/DB_NAME/DB_USER/DB_PASS` | app | Overridden in compose to the mysql service + docker user  |
| `REDIS_URL`           | app                | Overridden in compose to `tcp://redis:6379`                 |

The `mysql`/`redis` containers expose their own docker-only variables
(`MYSQL_DATABASE`, `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`, `MYSQL_PASSWORD`,
`MYSQL_APP_USER`, `MYSQL_APP_PASSWORD`). The compose file keeps one app account
in sync between the `mysql` service and the `php` service.

> **Note on `DB_USER=root`:** the MySQL image forbids `MYSQL_USER=root` (root is
> controlled by `MYSQL_ROOT_PASSWORD` only). The compose file therefore uses a
> dedicated non-root app user (`indielogin`) and wires the same credentials into
> the `php` service as `DB_USER`/`DB_PASS`.

---

## Tunnel discovery backends

`TUNNEL_DISCOVERY` tells the coordinator where to find the public base URL.
The default is `log`, which matches the bundled quick-tunnel service.

| `TUNNEL_DISCOVERY` | Source of the URL                                        | Relevant variable                       |
|--------------------|----------------------------------------------------------|-----------------------------------------|
| `log` (default)    | A trycloudflare URL grepped from the shared log file     | `TUNNEL_LOG_FILE`                      |
| `static`           | Used verbatim (for a named/stable tunnel)                | `BASE_URL`                             |
| `http`             | Returned by an endpoint                                  | `TUNNEL_URL_ENDPOINT`                  |
| `file`             | First line of a shared file                              | `TUNNEL_URL_FILE`                      |
| `ngrok`            | Discovered from ngrok's local API                        | `TUNNEL_API_URL`                       |

You are not locked into Cloudflare: the coordinator is a thin contract, so you
can swap in any tunnel that can surface a URL through one of those sources.

**Switching to a stable, named tunnel:** if you later want a fixed hostname you
control (and that appears in the Cloudflare Zero-Trust dashboard), switch the
`cloudflared` service to `tunnel run --token ${TUNNEL_TOKEN}`, set
`TUNNEL_DISCOVERY=static`, and set `BASE_URL` to your hostname. That trades the
zero-setup quick tunnel for stability.

---

## Checking it's healthy

```sh
docker compose ps          # mysql and redis should be healthy
curl http://localhost:8080/health
```

and through the tunnel:

```sh
BASE="$(grep '^BASE_URL=' .env | cut -d= -f2)"
curl -sS "$BASE/health"    # → {"mysql":"OK","redis":"OK"}
```

Useful endpoints to sanity check:

| Route       | Expect                                                        |
|-------------|---------------------------------------------------------------|
| `/health`   | `{"mysql":"OK","redis":"OK"}`                                 |
| `/id`       | OAuth client metadata JSON with `client_id` = `<BASE_URL>/id` |
| `/auth`     | 200 (the sign-in entrypoint)                                  |
| `/`         | 200 (home page)                                               |

---

## Stability & caveats

- **Quick-tunnel URL changes every run.** Each `cloudflared` start (container
  restart, `docker compose up`, host reboot) gets a **new random
  `trycloudflare.com` hostname**. Any process that registered the old URL
  externally (e.g. an ATProto/Bluesky client registration, or a browser that
  cached it) must use the **current** `BASE_URL` from `.env`.
- **Ephemeral, not in the dashboard.** Quick tunnels are not tied to a
  Cloudflare account and never appear in the Zero-Trust dashboard. Use a named
  tunnel if you need dashboard management or a stable hostname.
- **Provider registration.** For a real provider sign-in, the provider needs the
  *current* callback URL. With a rotating quick-tunnel URL this is impractical
  for long-lived use — it is ideal for local experimentation, not production.
- **`CLIENT_REGISTRATION`**: set `false` to restrict who can register client IDs.
- **Port `8080`** on the host maps to the app for local debugging; TLS and the
  public URL come from the tunnel.

---

## Troubleshooting

### `php` exits or hangs in "waiting for a trycloudflare URL"

The coordinator **blocks** booting Apache until it sees the URL in the shared
log. This is by design (the app must not start without a real base URL). Causes:

1. **cloudflared isn't writing the log.** Confirm the shared volume:
   ```sh
   docker run --rm -v indielogincom_tunnel_share:/shared alpine ls -la /shared
   ```
   You should see `tunnel.log`. If not, re-create both containers so the chown
   runs:
   ```sh
   docker compose up -d --force-recreate
   ```
2. **Old image without the entrypoint.** Ensure the coordinator image is baked:
   ```sh
   docker compose build php && docker compose up -d --force-recreate php
   ```

### `sed: cannot rename … Device or resource busy`

The `.env` is a bind mount and can't be atomically replaced. The coordinator
already handles this by editing in place — if you see this, you're on an older
image; rebuild it as above.

### `Incorrect Usage: flag needs an argument: -token`

Only happens if you switched the `cloudflared` service to a named tunnel but left
`TUNNEL_TOKEN` empty in `.env`. Set a real token (`cloudflared tunnel token
<name>`) or go back to the quick-tunnel command.

### Tunnel is up but `BASE_URL` seems stale

If the tunnel restarted, the URL rotated. Read the current one:
```sh
grep '^BASE_URL=' .env
docker exec indielogin-tunnel cat /shared/tunnel.log 2>/dev/null | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com'
```

### No requests appear in the app logs

`docker logs indielogin-app` shows Apache access logs. If browser visits to the
public URL aren't showing there, the request isn't reaching the app — check the
tunnel with the public `curl` test above, and make sure you're using the
**current** URL, not a cached older one.

### I want to see it in the Cloudflare dashboard

You can't with a quick tunnel. Create a named tunnel and switch
`TUNNEL_DISCOVERY` to `static` (see the discovery backends table above).
