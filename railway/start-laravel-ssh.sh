#!/usr/bin/env bash
set -Eeuo pipefail

APP_PORT="${PORT:-8080}"
SSH_PORT=22
TTYD_PORT="${TTYD_PORT:-7681}"
ROOT_PASSWORD="${ROOT_PASSWORD:-}"
SSH_AUTHORIZED_KEY="${SSH_AUTHORIZED_KEY:-}"
TTYD_CREDENTIAL="${TTYD_CREDENTIAL:-}"
ENABLE_TTYD="${ENABLE_TTYD:-0}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-0}"
ENABLE_QUEUE_WORKER="${ENABLE_QUEUE_WORKER:-0}"
SYNC_BUNDLE_HASH="${SYNC_BUNDLE_HASH:-1}"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

cd /app
[[ "${EUID}" -eq 0 ]] || die "Container must start as root."
[[ -n "${APP_KEY:-}" ]] || die "APP_KEY is required in Railway Variables."

mkdir -p /run/sshd database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [[ "${DB_CONNECTION:-sqlite}" == "sqlite" ]]; then
  touch database/database.sqlite
fi
chmod -R ug+rwX storage bootstrap/cache

# Configure SSH without putting secrets in the image.
if [[ -n "${ROOT_PASSWORD}" ]]; then
  printf 'root:%s\n' "${ROOT_PASSWORD}" | chpasswd
else
  sed -i 's/^PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
fi

if [[ -n "${SSH_AUTHORIZED_KEY}" ]]; then
  install -d -m 0700 /root/.ssh
  printf '%b\n' "${SSH_AUTHORIZED_KEY}" > /root/.ssh/authorized_keys
  chmod 0600 /root/.ssh/authorized_keys
fi

ssh-keygen -A
/usr/sbin/sshd -t

# Build/cache only after Railway Variables are available at runtime.
log "Preparing Laravel"
if [[ "${RUN_MIGRATIONS}" == "1" ]]; then
  php artisan migrate --force
fi
if [[ -n "${BOOTSTRAP_ADMIN_EMAIL:-}" && -n "${BOOTSTRAP_ADMIN_PASSWORD:-}" ]]; then
  php artisan tinker --execute='
    $email = env("BOOTSTRAP_ADMIN_EMAIL");
    $password = env("BOOTSTRAP_ADMIN_PASSWORD");
    $name = env("BOOTSTRAP_ADMIN_NAME", "Administrator");
    $user = \App\Models\User::updateOrCreate(
        ["email" => $email],
        [
            "name" => $name,
            "password" => $password,
            "plain_password" => $password,
            "role" => \App\Models\User::ROLE_SUPER_ADMIN,
            "is_approved" => true,
            "approved_at" => now(),
        ]
    );
    echo "Bootstrap admin ready: {$user->email}\n";
  '
fi
if [[ "${SYNC_BUNDLE_HASH}" == "1" ]]; then
  log "Synchronizing captcha bundle metadata hash"
  php artisan captcha:sync-bundle-hash || log "Bundle hash synchronization skipped; continuing startup"
fi
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan storage:link || true

# Start SSH on 22. Railway TCP Proxy must target container port 22.
log "Starting sshd on ${SSH_PORT}"
/usr/sbin/sshd -D -p "${SSH_PORT}" &
PIDS=("$!")

# Start the real Laravel application on Railway's HTTP port.
log "Starting Laravel HTTP server on ${APP_PORT}"
php artisan serve --host=0.0.0.0 --port="${APP_PORT}" &
PIDS+=("$!")

# Optional browser terminal; never use port 22 for ttyd.
if [[ "${ENABLE_TTYD}" == "1" ]]; then
  TTYD_ARGS=(--port "${TTYD_PORT}" --interface 0.0.0.0 --writable)
  if [[ -n "${TTYD_CREDENTIAL}" ]]; then
    TTYD_ARGS+=(--credential "${TTYD_CREDENTIAL}")
  fi
  log "Starting ttyd on ${TTYD_PORT}"
  ttyd "${TTYD_ARGS[@]}" bash &
  PIDS+=("$!")
fi

if [[ "${ENABLE_QUEUE_WORKER}" == "1" ]]; then
  log "Starting Laravel queue worker"
  php artisan queue:work --sleep=3 --tries=1 --timeout=330 --no-interaction &
  PIDS+=("$!")
fi

cleanup() {
  kill "${PIDS[@]}" 2>/dev/null || true
}
trap cleanup TERM INT EXIT

wait -n "${PIDS[@]}"
status=$?
printf 'A runtime process exited with status %s\n' "${status}" >&2
exit "${status}"
