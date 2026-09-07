# syntax=docker/dockerfile:1

FROM composer:2 AS php-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM node:22-bookworm-slim AS node-runtime

FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       openssh-server bash ca-certificates git unzip \
       libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
       libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring opcache pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/sshd /root/.ssh

COPY --from=node-runtime /usr/local/ /usr/local/
COPY --from=php-deps /usr/bin/composer /usr/bin/composer
COPY --from=php-deps /app/vendor ./vendor
COPY . .

RUN APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" \
    composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" npm ci --no-audit --no-fund \
    && APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" npm run build \
    && rm -rf node_modules

COPY railway/start-laravel-ssh.sh /usr/local/bin/start-laravel-ssh.sh
RUN chmod 755 /usr/local/bin/start-laravel-ssh.sh \
    && sed -i 's/^#\?Port .*/Port 22/' /etc/ssh/sshd_config \
    && sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication yes/' /etc/ssh/sshd_config \
    && sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin yes/' /etc/ssh/sshd_config \
    && printf '%s\n' 'UsePAM no' 'X11Forwarding no' 'AllowTcpForwarding no' >> /etc/ssh/sshd_config \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/start-laravel-ssh.sh"]
