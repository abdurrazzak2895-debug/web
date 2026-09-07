#!/usr/bin/env bash
# First deployment of a Laravel application from a private GitHub repository.
# Uses an existing read-only GitHub Deploy Key. Does not create or print secrets.
#
# Example:
#   sudo APP_DIR=/var/www/ipms \
#     REPO_SSH=git@github.com:abdurrazzak2895-debug/web.git \
#     DEPLOY_KEY=/root/.ssh/ipms_deploy \
#     BRANCH=master \
#     APP_URL=https://example.com \
#     bash deploy-with-github-deploy-key.sh
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/ipms}"
REPO_SSH="${REPO_SSH:-git@github.com:abdurrazzak2895-debug/web.git}"
BRANCH="${BRANCH:-master}"
DEPLOY_KEY="${DEPLOY_KEY:-}"
APP_URL="${APP_URL:-}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-0}"
BUILD_FRONTEND="${BUILD_FRONTEND:-1}"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "Run with sudo."
command -v git >/dev/null 2>&1 || die "git is required."
command -v sudo >/dev/null 2>&1 || die "sudo is required."
[[ -n "${DEPLOY_KEY}" ]] || die "Set DEPLOY_KEY to the private key path; never put the key contents in this script."
[[ -f "${DEPLOY_KEY}" ]] || die "Deploy key not found: ${DEPLOY_KEY}"
[[ "${REPO_SSH}" == git@github.com:* ]] || die "REPO_SSH must use GitHub SSH syntax, for example git@github.com:OWNER/REPO.git"
[[ "${APP_DIR}" == /* ]] || die "APP_DIR must be an absolute path."
[[ "${WEB_USER}" != root && "${WEB_GROUP}" != root ]] || die "Do not run the Laravel application as root."

if [[ -e "${APP_DIR}" && -n "$(find "${APP_DIR}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
  die "APP_DIR is not empty: ${APP_DIR}. This script will not overwrite an existing application."
fi

install -d -m 0755 -o root -g root "${APP_DIR}"
key_mode="$(stat -c '%a' "${DEPLOY_KEY}")"
if [[ "${key_mode}" != "600" && "${key_mode}" != "400" ]]; then
  log "Restricting deploy-key permissions"
  chmod 600 "${DEPLOY_KEY}"
fi

known_hosts_file="/root/.ssh/known_hosts"
install -d -m 0700 /root/.ssh
if ! ssh-keygen -F github.com -f "${known_hosts_file}" >/dev/null 2>&1; then
  log "Adding GitHub host key to root's known_hosts"
  ssh-keyscan -t ed25519 github.com >> "${known_hosts_file}" 2>/dev/null || die "Could not retrieve GitHub host key."
  chmod 0644 "${known_hosts_file}"
fi

GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=${known_hosts_file}"
export GIT_SSH_COMMAND

log "Testing read-only GitHub Deploy Key access"
git ls-remote --heads "${REPO_SSH}" "refs/heads/${BRANCH}" >/dev/null || die "GitHub access failed. Confirm the public key is added as a read-only Deploy Key and the repository/branch are correct."

log "Cloning ${REPO_SSH} branch ${BRANCH}"
git clone --branch "${BRANCH}" --single-branch --depth 1 "${REPO_SSH}" "${APP_DIR}"

cd "${APP_DIR}"
[[ -f artisan ]] || die "The cloned repository does not look like a Laravel application: artisan is missing."
[[ -f composer.json ]] || die "composer.json is missing."

log "Installing PHP dependencies"
command -v composer >/dev/null 2>&1 || die "Composer is not installed. Run the PHP/Composer installer first."
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [[ "${BUILD_FRONTEND}" == "1" && -f package.json && -f package-lock.json ]]; then
  command -v npm >/dev/null 2>&1 || die "npm is required because package-lock.json exists."
  log "Installing and building frontend assets"
  npm ci --omit=dev
  npm run build
fi

if [[ ! -e .env ]]; then
  [[ -f .env.example ]] || die ".env is missing and .env.example is unavailable."
  log "Creating .env from .env.example"
  install -m 0640 -o root -g "${WEB_GROUP}" .env.example .env
  if [[ -z "${APP_URL}" ]]; then
    printf '%s\n' "NOTICE: Set APP_URL in ${APP_DIR}/.env before serving traffic."
  else
    sed -i "s#^APP_URL=.*#APP_URL=${APP_URL}#" .env
  fi
else
  die ".env already exists unexpectedly. Refusing to overwrite it."
fi

if grep -q '^APP_KEY=$' .env 2>/dev/null; then
  log "Generating a new Laravel APP_KEY for this new installation"
  php artisan key:generate --force
fi

log "Preparing writable Laravel directories"
install -d -o "${WEB_USER}" -g "${WEB_GROUP}" \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache
chown -R "${WEB_USER}:${WEB_GROUP}" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

log "Clearing and caching Laravel configuration"
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan storage:link || true

if [[ "${RUN_MIGRATIONS}" == "1" ]]; then
  log "Running database migrations"
  php artisan migrate --force
else
  printf '%s\n' "NOTICE: Migrations were not run. After checking .env and database connectivity, run:"
  printf '  cd %q && php artisan migrate --force\n' "${APP_DIR}"
fi

log "Final ownership and permission pass"
chown -R root:root "${APP_DIR}"
chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chown root:"${WEB_GROUP}" "${APP_DIR}/.env"
chmod 0640 "${APP_DIR}/.env"
chmod -R ug+rwX "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

printf '\nDeployment preparation completed.\n'
printf 'Application: %s\n' "${APP_DIR}"
printf 'Branch:      %s\n' "${BRANCH}"
printf 'Repository:  %s\n' "${REPO_SSH}"
printf '\nNext steps:\n'
printf '  1. Edit %s/.env and set database, Redis, mail, and application secrets.\n' "${APP_DIR}"
printf '  2. Run migrations after verifying the database connection.\n'
printf '  3. Point Nginx root to %s/public.\n' "${APP_DIR}"
printf '  4. Configure Supervisor for queue workers.\n'
printf '  5. Never commit %s/.env.\n' "${APP_DIR}"
