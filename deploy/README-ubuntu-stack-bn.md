# Ubuntu VPS-এ Laravel-এর জন্য PHP 8.4, Composer ও Nginx ইনস্টলেশন

এই নির্দেশিকাটি Ubuntu 22.04 বা 24.04 VPS-এ Laravel application চালানোর জন্য PHP 8.4, PHP-FPM, Composer 2 এবং Nginx ইনস্টল করার পদ্ধতি দেখায়। এতে একটি পুনরায় চালানো যায় এমন installer script দেওয়া হয়েছে। Scriptটি application clone করে না, database তৈরি করে না, `.env` পরিবর্তন করে না এবং firewall বা DNS পরিবর্তন করে না।

> **গুরুত্বপূর্ণ:** এই scriptটি আপনার VPS-এ চালাতে হবে। এটি Manus sandbox-এ চালানো হয়নি। Production server-এ চালানোর আগে VPS snapshot বা backup নিন।

## কী ইনস্টল হবে

| উপাদান | উদ্দেশ্য |
|---|---|
| PHP 8.4 CLI | Artisan ও Composer চালানো |
| PHP 8.4-FPM | Nginx-এর মাধ্যমে Laravel PHP request চালানো |
| MySQL, Redis, XML, cURL ইত্যাদি extension | Laravel ও এই application-এর সাধারণ runtime dependency |
| Composer 2 | PHP dependency install করা |
| Nginx | Public web server এবং reverse proxy |
| `/etc/nginx/snippets/laravel-php.conf` | Laravel-এর নিরাপদ routing ও PHP-FPM snippet |

PHP 8.4 Ubuntu-এর default repository-তে সব release-এ নাও থাকতে পারে। Scriptটি `ppa:ondrej/php` ব্যবহার করে। PPA ব্যবহারের আগে আপনার organization-এর package policy যাচাই করুন।

## ১. VPS-এ script পৌঁছে দিন

Repository বা chat থেকে scriptটি VPS-এ কপি করুন। উদাহরণস্বরূপ, local machine থেকে:

```bash
scp install-php84-composer-nginx.sh ubuntu@YOUR_SERVER_IP:/tmp/
```

তারপর VPS-এ SSH করুন:

```bash
ssh ubuntu@YOUR_SERVER_IP
```

Script review করুন। অজানা remote script সরাসরি `curl | sudo bash` করবেন না।

```bash
less /tmp/install-php84-composer-nginx.sh
sha256sum /tmp/install-php84-composer-nginx.sh
```

## ২. Installer চালান

```bash
sudo bash /tmp/install-php84-composer-nginx.sh
```

Scriptটি শুধু Ubuntu 22.04 বা 24.04-এ চলবে। এটি PHP installer-এর signature যাচাই করে তারপর Composer install করবে। কোনো command ব্যর্থ হলে `set -Eeuo pipefail`-এর কারণে script থেমে যাবে।

Scriptটি চালানোর পর version যাচাই করুন:

```bash
php -v
php-fpm8.4 -v
composer --version
nginx -v
```

Service status যাচাই করুন:

```bash
sudo systemctl status nginx --no-pager
sudo systemctl status php8.4-fpm --no-pager
```

Expected PHP-FPM socket:

```text
/run/php/php8.4-fpm.sock
```

## ৩. Laravel application রাখুন

উদাহরণ:

```bash
sudo mkdir -p /var/www
sudo chown -R "$USER":"$USER" /var/www
cd /var/www
git clone git@github.com:abdurrazzak2895-debug/web.git ipms
cd /var/www/ipms
```

Private repository হলে GitHub deploy key ব্যবহার করুন। Deploy key-তে write permission দেবেন না।

Application dependency install করুন:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

যদি frontend build থাকে, Node.js এবং npm আলাদাভাবে install করে চালান:

```bash
npm ci
npm run build
```

## ৪. Laravel environment প্রস্তুত করুন

```bash
cd /var/www/ipms
cp .env.example .env
nano .env
```

সাধারণ production settings-এর উদাহরণ:

```dotenv
APP_NAME=IPMS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

CACHE_STORE=database
SESSION_DRIVER=file
QUEUE_CONNECTION=redis

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_database_user
DB_PASSWORD=use-a-long-unique-password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=use-a-long-unique-password
```

`APP_KEY`, database password, Redis password, API key বা Fleet key কখনো GitHub-এ commit করবেন না। Existing application হলে নতুন `APP_KEY` generate করবেন না, কারণ এতে encrypted cookies এবং encrypted data invalid হতে পারে। New application হলে:

```bash
php artisan key:generate --force
```

## ৫. Laravel permission ও initialization

Nginx-এর web root অবশ্যই `/var/www/ipms/public` হবে। Laravel project root public করবেন না।

```bash
sudo chown -R "$USER":www-data /var/www/ipms
sudo find /var/www/ipms -type f -exec chmod 640 {} \;
sudo find /var/www/ipms -type d -exec chmod 750 {} \;
sudo chown -R www-data:www-data /var/www/ipms/storage /var/www/ipms/bootstrap/cache
sudo chmod -R ug+rwX /var/www/ipms/storage /var/www/ipms/bootstrap/cache
```

তারপর:

```bash
cd /var/www/ipms
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Application-এর route cache compatible হলে চালাতে পারেন:

```bash
php artisan route:cache
```

## ৬. Nginx server block তৈরি করুন

Domain পরিবর্তন করে নিচের file তৈরি করুন:

```bash
sudo nano /etc/nginx/sites-available/ipms
```

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name your-domain.example www.your-domain.example;

    root /var/www/ipms/public;
    index index.php index.html;
    charset utf-8;
    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico {
        access_log off;
        log_not_found off;
    }

    location = /robots.txt {
        access_log off;
        log_not_found off;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|ico|webp|woff|woff2|ttf)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    access_log /var/log/nginx/ipms_access.log;
    error_log /var/log/nginx/ipms_error.log;
}
```

Site enable করুন:

```bash
sudo ln -s /etc/nginx/sites-available/ipms /etc/nginx/sites-enabled/ipms
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

`nginx -t` সফল না হলে reload করবেন না।

## ৭. HTTPS চালু করুন

DNS-এ domain-এর `A` record VPS-এর public IP-তে point করুন। DNS update হওয়ার পর:

```bash
sudo apt-get update
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.example -d www.your-domain.example
sudo certbot renew --dry-run
```

তারপর `.env`-এ:

```dotenv
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
```

Cache refresh করুন:

```bash
cd /var/www/ipms
php artisan optimize:clear
php artisan config:cache
```

## ৮. Basic verification

```bash
sudo systemctl is-active nginx
sudo systemctl is-active php8.4-fpm
sudo nginx -t
curl -I http://your-domain.example/login
```

HTTPS চালু হলে:

```bash
curl -I https://your-domain.example/login
```

Laravel log:

```bash
tail -f /var/www/ipms/storage/logs/laravel.log
```

Nginx log:

```bash
sudo tail -f /var/log/nginx/ipms_error.log
```

## সাধারণ সমস্যা

### `502 Bad Gateway`

PHP-FPM socket ও service যাচাই করুন:

```bash
ls -l /run/php/php8.4-fpm.sock
sudo systemctl restart php8.4-fpm
sudo tail -50 /var/log/nginx/ipms_error.log
```

### `403 Forbidden`

Nginx root অবশ্যই `/var/www/ipms/public` কিনা যাচাই করুন:

```bash
sudo nginx -T | grep -A8 -B3 'server_name your-domain.example'
```

### Laravel `500` error

```bash
cd /var/www/ipms
php artisan optimize:clear
tail -100 storage/logs/laravel.log
```

### Asset `404`

```bash
cd /var/www/ipms
npm ci
npm run build
find public/build -maxdepth 2 -type f | head
```

## Security checklist

| বিষয় | করণীয় |
|---|---|
| SSH | Password login বন্ধ করে SSH key ব্যবহার করুন |
| Firewall | শুধু SSH, HTTP ও HTTPS খুলুন |
| MySQL | Public internet-এ expose করবেন না |
| Redis | Public internet-এ expose করবেন না |
| `.env` | GitHub বা web root-এ রাখবেন না |
| Application | `APP_DEBUG=false` রাখুন |
| Secrets | Password, token, API key server-side secret store-এ রাখুন |
| Backup | Daily encrypted database backup রাখুন |
| Updates | নিয়মিত `apt update` এবং security upgrade চালান |

## Reference files

এই package-এ installer scriptটি আছে:

```text
install-php84-composer-nginx.sh
```

Installer scriptটি intentionally application deployment, database setup, DNS, firewall এবং secret configuration করেনি। এগুলো application ও infrastructure অনুযায়ী আলাদাভাবে review করে করতে হবে।

## References

[1]: https://getcomposer.org/download/ "Composer Download and Installation"

[2]: https://ubuntu.com/tutorials/install-and-configure-nginx "Ubuntu: Install and configure Nginx"

[3]: https://nginx.org/en/linux_packages.html "NGINX: Linux packages"

[4]: https://launchpad.net/~ondrej/+archive/ubuntu/php "Ondřej Surý PHP packages for Ubuntu"
