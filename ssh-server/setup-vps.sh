#!/bin/bash
#
# Set up a fresh Debian or Ubuntu VPS as the IndieLogin SSH sign-in server.
#
# Build the server where you have Go (1.24 or newer), and copy it here with
# this script and the systemd unit. The VPS needs no Go toolchain:
#
#   cd ssh-server
#   CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -o indielogin-ssh
#   scp indielogin-ssh indielogin-ssh.service setup-vps.sh root@<vps>:
#   ssh root@<vps> ./setup-vps.sh --hostname ssh.indielogin.com
#
# Use GOARCH=arm64 for an ARM VPS.
#
# What it does, in this order:
#
#   1. Moves the VPS's own sshd from port 22 to an admin port (2200 unless
#      --admin-port says otherwise), in two steps so you cannot be locked out:
#      sshd first listens on both, and port 22 is only given up after you have
#      logged in on the admin port from a second terminal and said so. If ufw
#      is active, the admin port is opened in it.
#   2. Installs the server as /usr/local/bin/indielogin-ssh, run by the
#      unprivileged indielogin-ssh user under the sandboxed systemd unit, with
#      its settings in /etc/indielogin-ssh.env. A random API key is made the
#      first time.
#   3. Starts it on port 22, checks it is listening and that indielogin.com
#      can be reached, and prints what to put in indielogin.com's .env.
#
# Running it again is safe: a step that is already done is skipped, the host
# key and API key are kept, and the binary and unit are replaced, which is
# also how to upgrade.
#
# Options:
#   --hostname NAME     the name people will ssh to (default: hostname -f)
#   --admin-port PORT   where the VPS's own sshd moves to (default: 2200)
#   --indielogin-url U  default: https://indielogin.com
#   --api-key KEY       use this key instead of the stored or a new one
#   --binary PATH       default: indielogin-ssh next to this script
#   --unit PATH         default: indielogin-ssh.service next to this script
#   --dry-run           show what would be done, and change nothing
#
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

HOSTNAME_FOR_USERS=$(hostname -f 2>/dev/null || hostname)
ADMIN_PORT=2200
INDIELOGIN_URL=https://indielogin.com
API_KEY=
BINARY="$HERE/indielogin-ssh"
UNIT="$HERE/indielogin-ssh.service"
DRY_RUN=0

SERVICE_USER=indielogin-ssh
ENV_FILE=/etc/indielogin-ssh.env
STATE_DIR=/var/lib/indielogin-ssh
HOST_KEY="$STATE_DIR/host_ed25519_key"
SSHD_DROPIN=/etc/ssh/sshd_config.d/00-indielogin-admin-port.conf
SOCKET_DROPIN=/etc/systemd/system/ssh.socket.d/addresses.conf

while [ $# -gt 0 ]; do
  case "$1" in
    --hostname) HOSTNAME_FOR_USERS=$2; shift 2 ;;
    --admin-port) ADMIN_PORT=$2; shift 2 ;;
    --indielogin-url) INDIELOGIN_URL=${2%/}; shift 2 ;;
    --api-key) API_KEY=$2; shift 2 ;;
    --binary) BINARY=$2; shift 2 ;;
    --unit) UNIT=$2; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die()  { printf '\n\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# Every change goes through run or write_file, so that --dry-run shows it
# instead of doing it. Everything else only reads.
run() {
  if [ "$DRY_RUN" = 1 ]; then
    printf '    [dry run] %s\n' "$*"
  else
    "$@"
  fi
}

write_file() { # path mode, with the contents on stdin
  local path=$1 mode=$2 contents
  contents=$(cat)
  if [ "$DRY_RUN" = 1 ]; then
    printf '    [dry run] write %s (mode %s):\n' "$path" "$mode"
    printf '%s\n' "$contents" | sed 's/^/        /'
    return
  fi
  install -d -m 0755 "$(dirname "$path")"
  printf '%s\n' "$contents" > "$path.tmp"
  chmod "$mode" "$path.tmp"
  mv "$path.tmp" "$path"
}

confirm() { # question; answers yes in a dry run
  if [ "$DRY_RUN" = 1 ]; then
    printf '    [dry run] would ask: %s\n' "$1"
    return 0
  fi
  local answer
  read -r -p "    $1 Type yes to continue: " answer < /dev/tty
  [ "$answer" = yes ]
}

# Which ports sshd is listening on right now, one per line. Under socket
# activation it is systemd that holds them, so ask ssh.socket rather than
# look for an sshd process.
sshd_ports() {
  if [ "${SOCKET_ACTIVATED:-0}" = 1 ]; then
    systemctl show -p Listen --value ssh.socket 2>/dev/null | grep -oE ':[0-9]+ \(Stream\)' | grep -oE '[0-9]+'
  else
    ss -Hltnp 2>/dev/null | awk '/"sshd"/ {print $4}' | sed 's/.*://'
  fi | sort -un
}

sshd_listens_on() { # port
  sshd_ports | grep -qx "$1"
}

listening_on() { # port program
  ss -Hltnp "sport = :$1" 2>/dev/null | grep -q "\"$2\""
}

# --- Checks -----------------------------------------------------------------

say "Checking"

if [ "$DRY_RUN" != 1 ] && [ "$(id -u)" != 0 ]; then
  die "Run this as root."
fi

if ! [[ "$ADMIN_PORT" =~ ^[0-9]+$ ]] || [ "$ADMIN_PORT" -lt 1024 ] || [ "$ADMIN_PORT" -gt 65535 ]; then
  die "--admin-port must be a number from 1024 to 65535."
fi

for tool in ss systemctl ssh-keygen curl; do
  command -v "$tool" > /dev/null || die "$tool is not installed."
done

# sshd lives in /usr/sbin, which is not on every PATH
SSHD=$(command -v sshd || echo /usr/sbin/sshd)
[ -x "$SSHD" ] || die "sshd is not installed."

[ -f "$BINARY" ] || die "No server binary at $BINARY. Build it as described at the top of this script, or give --binary."
[ -f "$UNIT" ] || die "No unit file at $UNIT. Copy indielogin-ssh.service next to this script, or give --unit."

# The server refuses to start without its settings, which makes a harmless
# check that this binary runs here at all (right architecture, not corrupt)
# (it exits with an error on purpose, so its output is checked, not its status)
binary_says=$(env -i "$BINARY" 2>&1 || true)
if [[ "$binary_says" != *"must both be set"* ]]; then
  die "$BINARY does not run on this machine. Was it built with the right GOARCH?"
fi

grep -qE '^\s*Include\s+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config \
  || die "/etc/ssh/sshd_config does not include /etc/ssh/sshd_config.d/*.conf, which this relies on. Add the line \"Include /etc/ssh/sshd_config.d/*.conf\" at its top."

SOCKET_ACTIVATED=0
if systemctl is-active --quiet ssh.socket 2>/dev/null; then
  SOCKET_ACTIVATED=1
fi
SSH_SERVICE=ssh
systemctl cat ssh.service > /dev/null 2>&1 || SSH_SERVICE=sshd

note "Hostname for users:  $HOSTNAME_FOR_USERS"
note "Admin sshd port:     $ADMIN_PORT"
note "indielogin.com:      $INDIELOGIN_URL"
note "sshd started by:     $([ $SOCKET_ACTIVATED = 1 ] && echo 'ssh.socket (socket activation)' || echo "$SSH_SERVICE.service")"
note "sshd listening on:   $(sshd_ports | tr '\n' ' ')"

if listening_on 22 indielogin-ssh; then
  note "The sign-in server is already on port 22; this run will upgrade it."
fi

# --- 1. Move the VPS's own sshd to the admin port -----------------------------

# Point sshd, and ssh.socket if it is the one listening, at the given ports.
# The socket drop-in is named addresses.conf so that it replaces the one the
# Debian/Ubuntu generator makes from sshd_config's Port lines.
set_sshd_ports() {
  local ports=("$@") port config="" socket="[Socket]
ListenStream="
  for port in "${ports[@]}"; do
    config+="Port $port"$'\n'
    socket+=$'\n'"ListenStream=$port"
  done

  printf '%s' "# The VPS's own sshd, moved off port 22 for the IndieLogin SSH sign-in server
$config" | write_file "$SSHD_DROPIN" 0644

  if [ "$DRY_RUN" != 1 ]; then
    "$SSHD" -t || die "sshd does not accept the new configuration; nothing was reloaded. See the error above."
  fi

  if [ $SOCKET_ACTIVATED = 1 ]; then
    printf '%s' "$socket" | write_file "$SOCKET_DROPIN" 0644
    run systemctl daemon-reload
    # Restarting the socket changes where new connections are accepted;
    # sessions already open, this one included, carry on
    run systemctl restart ssh.socket
  else
    run systemctl restart "$SSH_SERVICE"
  fi
  [ "$DRY_RUN" = 1 ] || sleep 1
}

say "Moving the VPS's own sshd to port $ADMIN_PORT"

if sshd_listens_on "$ADMIN_PORT" && ! sshd_listens_on 22; then
  note "Already done: sshd listens on $ADMIN_PORT and not on 22."
else
  # Port lines in the main sshd_config would keep 22 open whatever the
  # drop-in says, since sshd adds up every Port it is given
  if grep -qE '^\s*Port\s' /etc/ssh/sshd_config; then
    note "Commenting out the Port lines in /etc/ssh/sshd_config (a copy is kept as sshd_config.indielogin-backup)."
    run cp -p /etc/ssh/sshd_config /etc/ssh/sshd_config.indielogin-backup
    run sed -i -E 's/^(\s*Port\s)/# indielogin-ssh moved this to sshd_config.d: \1/' /etc/ssh/sshd_config
  fi

  if command -v ufw > /dev/null && ufw status 2>/dev/null | grep -q '^Status: active'; then
    note "Opening port $ADMIN_PORT in ufw."
    run ufw allow "$ADMIN_PORT/tcp"
  else
    note "ufw is not active. If this VPS has another firewall, or a cloud firewall in front of it, open TCP port $ADMIN_PORT there now."
  fi

  note "Step 1 of 2: sshd listens on both 22 and $ADMIN_PORT."
  set_sshd_ports 22 "$ADMIN_PORT"

  if [ "$DRY_RUN" != 1 ] && ! sshd_listens_on "$ADMIN_PORT"; then
    die "sshd is not listening on $ADMIN_PORT. Nothing else was changed; port 22 still works."
  fi

  cat <<EOF

    Keep this session open. In a second terminal, check that you can log in
    on the new port:

        ssh -p $ADMIN_PORT $(logname 2>/dev/null || echo root)@$HOSTNAME_FOR_USERS

    Only once that works, continue here. Port 22 is then given up for the
    sign-in server, and from now on you log in to this VPS on port $ADMIN_PORT.
    You may want this in your ~/.ssh/config:

        Host <a name for this VPS>
          HostName <its address>
          Port $ADMIN_PORT

EOF
  confirm "Did logging in on port $ADMIN_PORT work?" \
    || die "Stopped. sshd listens on both 22 and $ADMIN_PORT; run this again when $ADMIN_PORT works."

  note "Step 2 of 2: sshd listens on $ADMIN_PORT only."
  set_sshd_ports "$ADMIN_PORT"

  if [ "$DRY_RUN" != 1 ] && sshd_listens_on 22; then
    die "sshd is still listening on port 22: $(ss -Hltnp 'sport = :22')"
  fi
fi

# --- 2. Install the sign-in server -------------------------------------------

say "Installing the sign-in server"

if ! id "$SERVICE_USER" > /dev/null 2>&1; then
  run useradd --system --home-dir "$STATE_DIR" --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
else
  note "User $SERVICE_USER already exists."
fi

run install -m 0755 "$BINARY" /usr/local/bin/indielogin-ssh
run install -m 0644 "$UNIT" /etc/systemd/system/indielogin-ssh.service

# Keep the API key already in use unless a new one was given, so that a
# re-run does not break the link with indielogin.com
if [ -z "$API_KEY" ] && [ -r "$ENV_FILE" ]; then
  API_KEY=$(sed -n 's/^SSH_SERVER_API_KEY=//p' "$ENV_FILE")
fi
NEW_KEY=0
if [ -z "$API_KEY" ]; then
  API_KEY=$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')
  NEW_KEY=1
fi

write_file "$ENV_FILE" 0600 <<EOF
# Settings for the IndieLogin SSH sign-in server, read by
# /etc/systemd/system/indielogin-ssh.service. Written by setup-vps.sh.
SSH_SERVER_LISTEN=:22
INDIELOGIN_URL=$INDIELOGIN_URL
SSH_SERVER_API_KEY=$API_KEY
EOF

# --- 3. Start it --------------------------------------------------------------

say "Starting it on port 22"

run systemctl daemon-reload
run systemctl enable indielogin-ssh
run systemctl restart indielogin-ssh

if [ "$DRY_RUN" != 1 ]; then
  for _ in $(seq 1 20); do
    listening_on 22 indielogin-ssh && break
    sleep 0.5
  done
  listening_on 22 indielogin-ssh \
    || die "The sign-in server is not listening on port 22. Its log: journalctl -u indielogin-ssh"
  note "Listening on port 22."
fi

if [ -r "$HOST_KEY.pub" ]; then
  FINGERPRINT=$(ssh-keygen -lf "$HOST_KEY.pub" | awk '{print $2}')
else
  FINGERPRINT="<run this again once the server has started, or: ssh-keygen -lf $HOST_KEY.pub>"
fi

# Ask the API something meaningless, to see whether it is there and whether it
# accepts this server yet: 200 once indielogin.com is configured, 404 before
API_STATUS=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X POST \
  -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json' -d '{}' \
  "$INDIELOGIN_URL/ssh-server/check" || true)

# This machine's own addresses, as the routing table has them. Nothing is
# sent anywhere to find them out.
IPV4=$(ip -4 route get 192.0.2.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' || true)
IPV6=$(ip -6 route get 2001:db8::1 2>/dev/null | sed -n 's/.* src \([0-9a-f:]*\).*/\1/p' || true)
ADDRESSES=$(printf '%s\n' "$IPV4" "$IPV6" | sed '/^$/d' | paste -sd, -)
[ -n "$ADDRESSES" ] || ADDRESSES="<this VPS's public address>"

say "Done"

cat <<EOF

    Add these to indielogin.com's .env:

        SSH_SERVER_HOST=$HOSTNAME_FOR_USERS
        SSH_SERVER_FINGERPRINT=$FINGERPRINT
        SSH_SERVER_API_KEY=$API_KEY
        SSH_SERVER_API_IPS=$ADDRESSES

EOF

if [ $NEW_KEY = 1 ]; then
  note "The API key is new. It is stored in $ENV_FILE, readable by root only."
fi
note "SSH_SERVER_API_IPS is this VPS's own address as it sees it. If it reaches the"
note "internet through NAT, use the address indielogin.com sees instead."
note "Point $HOSTNAME_FOR_USERS's DNS at this VPS if you have not yet."
echo

case "$API_STATUS" in
  200) note "indielogin.com already accepts this server's API key." ;;
  404) note "indielogin.com answered 404, as expected until the settings above are in its .env." ;;
  000) note "Could not reach $INDIELOGIN_URL from here. Check the URL and this VPS's outgoing connections." ;;
  *)   note "indielogin.com answered HTTP $API_STATUS to the API check." ;;
esac
note "The server's log: journalctl -u indielogin-ssh -f"
echo
