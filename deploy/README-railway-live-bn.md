# Railway-এ মূল Laravel system live করার সম্পূর্ণ setup

## বর্তমান অবস্থার ফল

বর্তমান live container-এ আগে শুধু এগুলো চলছিল:

```text
sshd  → :22
ttyd  → :8080
```

কিন্তু Laravel application, PHP, Composer, Node এবং `artisan` ছিল না। তাই web terminal live থাকলেও মূল website live ছিল না। এই setup-এর জন্য নতুন production Dockerfile ও startup script যোগ করা হয়েছে।

নতুন files:

```text
deploy/railway-app.Dockerfile
railway/start-laravel-ssh.sh
```

## নতুন architecture

```text
Railway HTTP Domain → $PORT → php artisan serve → Laravel app
Railway TCP Proxy   → :22   → sshd
Optional ttyd       → :7681 → browser terminal
Optional worker     → Laravel queue worker
```

`ttyd` public web domain-এর বদলে optional রাখা হয়েছে, কারণ একই service-এর public HTTP port-এ মূল Laravel app চলবে।

## ১. Railway Build settings

Railway Service → Settings → Build এ:

```text
Builder: Dockerfile
Dockerfile Path: /deploy/railway-app.Dockerfile
Build Context: repository root
```

Start Command খালি রাখুন। Dockerfile-এর `ENTRYPOINT` নিজে startup script চালাবে। GitHub repository-এর branch `master` connect করে deploy/redeploy করুন।

## ২. Railway Variables

Railway Service → Variables-এ অন্তত এগুলো দিন। Secret values কখনো GitHub-এ লিখবেন না।

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_A_REAL_LARAVEL_KEY
APP_URL=https://your-railway-domain.up.railway.app

DB_CONNECTION=mysql
DB_HOST=<Railway MySQL host>
DB_PORT=<Railway MySQL port>
DB_DATABASE=<Railway MySQL database>
DB_USERNAME=<Railway MySQL user>
DB_PASSWORD=<Railway MySQL password>

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=redis

REDIS_CLIENT=predis
REDIS_HOST=<Railway Redis host>
REDIS_PORT=<Railway Redis port>
REDIS_PASSWORD=<Railway Redis password or empty>
```

`APP_KEY` local machine-এ generate করতে পারেন:

```bash
php artisan key:generate --show
```

শুধু output Railway Variable-এ বসাবেন; repository `.env`-এ commit করবেন না।

আপনার domain যদি `example.com` হয়:

```text
APP_URL=https://example.com
SANCTUM_STATEFUL_DOMAINS=example.com,www.example.com
SESSION_DOMAIN=example.com
CAPTCHA_GET_URL=https://example.com/api/captcha/get
VITE_REVERB_HOST=example.com
GOOGLE_REDIRECT_URI=https://example.com/auth/google/callback
```

প্রথম deploy-এ migration চালাতে চাইলে:

```text
RUN_MIGRATIONS=1
```

Existing production database হলে আগে backup নিন। প্রথমে নিরাপদভাবে `RUN_MIGRATIONS=0` রেখে deploy করে logs যাচাই করুন, তারপর explicit migration দিন।

## ৩. SSH Variables

SSH-এর জন্য password-এর বদলে key ব্যবহার করা ভালো:

```text
SSH_AUTHORIZED_KEY=ssh-ed25519 AAAA...your-public-key
```

যদি password login সাময়িকভাবে দরকার হয়:

```text
ROOT_PASSWORD=<নতুন-দীর্ঘ-random-password>
```

আগে chat-এ প্রকাশিত password ব্যবহার করবেন না।

## ৪. Optional browser terminal

Main Laravel HTTP domain এখন app-এর জন্য ব্যবহৃত হবে। Web terminal দরকার হলে:

```text
ENABLE_TTYD=1
TTYD_PORT=7681
TTYD_CREDENTIAL=terminal-user:আলাদা-terminal-password
```

Railway-এ আলাদা TCP Proxy তৈরি করুন:

```text
Public TCP port → container :7681
```

SSH-এর TCP Proxy আলাদা থাকবে:

```text
monorail.proxy.rlwy.net:15510 → container :22
```

HTTP application domain কখনো `22` বা `7681`-এ map করবেন না; সেটি Railway-এর `$PORT`-এ যাবে।

## ৫. Queue worker

Application-এর queue দরকার হলে:

```text
ENABLE_QUEUE_WORKER=1
```

এটি একই container-এ queue worker চালাবে। বড় production load হলে আলাদা Railway worker service তৈরি করে repository-এর `railway/run-worker.sh` ব্যবহার করা ভালো।

## ৬. Deploy-এর পরে checks

Railway deploy logs-এ এগুলো দেখা উচিত:

```text
==> Preparing Laravel
==> Starting sshd on 22
==> Starting Laravel HTTP server on <PORT>
```

Web terminal/SSH দিয়ে যাচাই:

```bash
whoami
php -v
php artisan about
ss -lntp
```

Expected ports:

```text
sshd        → :22
Laravel     → :$PORT
```

`ENABLE_TTYD=1` দিলে অতিরিক্ত:

```text
ttyd        → :7681
```

Laravel endpoint:

```bash
curl -I https://your-railway-domain.up.railway.app/login
```

SSH endpoint:

```bash
ssh -p 15510 root@monorail.proxy.rlwy.net
```

## ৭. বর্তমান container-এ command চালিয়ে app live হবে না

বর্তমান image-এ `php`, `composer`, `node`, `npm` এবং `artisan` অনুপস্থিত ছিল। তাই শুধু web terminal-এ `apt install` করে temporary app চালানো ঠিক নয়; Railway redeploy হলে সব হারিয়ে যাবে। GitHub-connected Railway deployment-এ নতুন Dockerfile ব্যবহার করতেই হবে।

## ৮. গুরুত্বপূর্ণ database বিষয়

Railway application container-এর ভেতরে local MySQL/Redis ধরে নেবেন না। Railway MySQL/Redis service যোগ করে তাদের injected variables ব্যবহার করুন। `DB_HOST=localhost` বা `REDIS_HOST=127.0.0.1` শুধু একই container-এ database চললে ঠিক; Railway managed database-এর ক্ষেত্রে ঠিক নয়।

## ৯. Deploy order

1. GitHub repository-তে নতুন files commit/push করুন।
2. Railway service-এর Dockerfile path `/deploy/railway-app.Dockerfile` দিন।
3. MySQL এবং Redis service যোগ করুন।
4. Railway Variables-এ application এবং database values দিন।
5. প্রথম deploy-এ `RUN_MIGRATIONS=0` রাখুন।
6. Deploy logs দেখুন এবং `/login` endpoint পরীক্ষা করুন।
7. Database connection ঠিক থাকলে `RUN_MIGRATIONS=1` দিয়ে একবার deploy করুন।
8. এরপর `RUN_MIGRATIONS=0` করে রাখুন; প্রতিটি deploy-এ migration auto-run না করাই নিরাপদ।
9. Queue দরকার হলে `ENABLE_QUEUE_WORKER=1` দিন বা আলাদা worker service ব্যবহার করুন।
10. SSH TCP Proxy `:22`-এ এবং optional ttyd TCP Proxy `:7681`-এ রাখুন।

## ১০. GitHub commit না করা পর্যন্ত Railway নতুন files পাবে না

এই নতুন files এখন local working tree-তে আছে। Railway GitHub integration ব্যবহার করলে এগুলো GitHub-এ push করতে হবে:

```bash
git add deploy/railway-app.Dockerfile railway/start-laravel-ssh.sh deploy/README-railway-live-bn.md
git commit -m "Add Railway Laravel live runtime"
git push origin master
```

তারপর Railway নতুন commit deploy করবে।
