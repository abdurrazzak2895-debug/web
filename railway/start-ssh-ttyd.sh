#!/usr/bin/env bash
set -Eeuo pipefail

SSH_PORT="${SSH_PORT:-22}"
TTYD_PORT="${PORT:-7681}"
TTYD_CREDENTIAL="${TTYD_CREDENTIAL:-}"
ROOT_PASSWORD="${ROOT_PASSWORD:-}"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "This container must start as root."

# Railway injects PORT for the HTTP public domain. ttyd must use that port;
# SSH stays on 22 and is exposed separately through Railway TCP Proxy.
if [[ -z "${PORT:-}" ]]; then
  log "PORT is not set; using ttyd port ${TTYD_PORT}"
fi

if [[ -n "${ROOT_PASSWORD}" ]]; then
  printf 'root:%s\n' "${ROOT_PASSWORD}" | chpasswd
  unset ROOT_PASSWORD
else
  log "ROOT_PASSWORD is empty; password SSH login is disabled for safety"
  sed -i 's/^PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
fi

# Prefer an SSH public key supplied as an environment variable. Newlines may
# be represented as literal \\n in Railway variables, so convert them.
if [[ -n "${SSH_AUTHORIZED_KEY:-}" ]]; then
  install -d -m 0700 /root/.ssh
  printf '%b\n' "${SSH_AUTHORIZED_KEY}" > /root/.ssh/authorized_keys
  chmod 0600 /root/.ssh/authorized_keys
fi

# Validate SSH configuration before starting services.
mkdir -p /run/sshd
ssh-keygen -A
/usr/sbin/sshd -t

# ttyd is intentionally separate from SSH: HTTP domain -> PORT, TCP proxy -> 22.
TTYD_ARGS=(--port "${TTYD_PORT}" --interface 0.0.0.0 --writable)
if [[ -n "${TTYD_CREDENTIAL}" ]]; then
  TTYD_ARGS+=(--credential "${TTYD_CREDENTIAL}")
fi

log "Starting sshd on ${SSH_PORT}"
/usr/sbin/sshd -D -p "${SSH_PORT}" &
SSHD_PID=$!

log "Starting ttyd HTTP terminal on ${TTYD_PORT}"
ttyd "${TTYD_ARGS[@]}" bash &
TTYD_PID=$!

cleanup() {
  kill "${SSHD_PID}" "${TTYD_PID}" 2>/dev/null || true
}
trap cleanup TERM INT EXIT

wait -n "${SSHD_PID}" "${TTYD_PID}"
status=$?
printf 'A service exited with status %s\n' "${status}" >&2
exit "${status}"
