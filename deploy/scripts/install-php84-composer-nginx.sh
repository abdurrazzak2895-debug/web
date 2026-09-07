#!/usr/bin/env bash
# Ubuntu 24.04 Laravel base stack installer
# Installs PHP 8.4, PHP-FPM, Composer 2, and Nginx.
# This script does not clone an application, create a database, or modify .env.
set -Eeuo pipefail

PHP_VERSION="${PHP_VERSION:-8.4}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "Run as root: sudo bash $0"
command -v apt-get >/dev/null 2>&1 || die "This script requires Ubuntu/Debian apt-get."

. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "This script targets Ubuntu; detected ${ID:-unknown}."

log "Checking Ubuntu release"
case "${VERSION_ID:-}" in
  22.04|24.04) ;;
  *) die "Supported Ubuntu releases: 22.04 and 24.04. Detected ${VERSION_ID:-unknown}." ;;
esac

export DEBIAN_FRONTEND=noninteractive

log "Updating base packages"
apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates curl gnupg lsb-release software-properties-common \
  unzip git nginx

log "Adding the maintained PHP package source"
add-apt-repository -y ppa:ondrej/php
apt-get update

log "Installing PHP ${PHP_VERSION}, PHP-FPM, and Laravel extensions"
apt-get install -y --no-install-recommends \
  "php${PHP_VERSION}-fpm" \
  "php${PHP_VERSION}-cli" \
  "php${PHP_VERSION}-common" \
  "php${PHP_VERSION}-mysql" \
  "php${PHP_VERSION}-redis" \
  "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-bcmath" \
  "php${PHP_VERSION}-intl" \
  "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-opcache"

PHP_BIN="$(command -v "php${PHP_VERSION}" || true)"
[[ -n "${PHP_BIN}" ]] || die "php${PHP_VERSION} was not installed."

log "Making PHP ${PHP_VERSION} the CLI default"
update-alternatives --install /usr/bin/php php "${PHP_BIN}" 84
update-alternatives --set php "${PHP_BIN}"

PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

log "Installing Composer with installer-signature verification"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
curl -fsSL https://getcomposer.org/installer -o "${tmp_dir}/composer-setup.php"
signature="$(curl -fsSL https://composer.github.io/installer.sig)"
actual_signature="$(php "${tmp_dir}/composer-setup.php" --check)"
[[ "${actual_signature}" == "${signature}" ]] || die "Composer installer signature verification failed."
php "${tmp_dir}/composer-setup.php" --install-dir="$(dirname "${COMPOSER_BIN}")" --filename="$(basename "${COMPOSER_BIN}")"
rm -f "${tmp_dir}/composer-setup.php"

log "Enabling Nginx and PHP-FPM"
systemctl enable --now nginx
systemctl enable --now "${PHP_FPM_SERVICE}"

log "Writing an Nginx helper snippet for Laravel PHP-FPM"
cat > /etc/nginx/snippets/laravel-php.conf <<EOF
location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

location ~ \.php$ {
    try_files \$uri =404;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PHP_FPM_SOCKET};
    fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT \$realpath_root;
}

location ~ /\.(?!well-known).* {
    deny all;
}
EOF

nginx -t
systemctl reload nginx

log "Installation summary"
printf 'Ubuntu:       %s\n' "${PRETTY_NAME:-unknown}"
printf 'PHP CLI:      %s\n' "$(php -r 'echo PHP_VERSION, PHP_EOL;')"
printf 'PHP-FPM:       %s (%s)\n' "${PHP_FPM_SERVICE}" "${PHP_FPM_SOCKET}"
printf 'Composer:      %s\n' "$(composer --version)"
printf 'Nginx:         %s\n' "$(nginx -v 2>&1)"
printf '\nNext step: create an Nginx server block with root /var/www/your-app/public.\n'
printf 'This script intentionally did not change your application, database, firewall, DNS, or secrets.\n'
