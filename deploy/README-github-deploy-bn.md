# GitHub Deploy Key দিয়ে Ubuntu VPS-এ Laravel-এর প্রথম deployment

এই নির্দেশিকায় private GitHub repository থেকে Ubuntu VPS-এ Laravel application clone ও প্রস্তুত করার নিরাপদ পদ্ধতি দেওয়া হয়েছে। Deploy Key হলো repository-specific SSH key। এটি GitHub Actions token বা personal access token-এর বিকল্প হিসেবে read-only code checkout-এর জন্য ব্যবহার করা যায়।

Automation scriptটি:

- Read-only GitHub Deploy Key দিয়ে SSH access পরীক্ষা করে;
- GitHub repository-এর নির্দিষ্ট branch shallow clone করে;
- Composer dependency install করে;
- `package-lock.json` থাকলে optional frontend build চালায়;
- `.env` না থাকলে `.env.example` থেকে তৈরি করে;
- `.env` থাকলে overwrite করতে অস্বীকার করে;
- নতুন installation হলে `APP_KEY` generate করে;
- Laravel writable directories ঠিক করে;
- Laravel config ও view cache তৈরি করে;
- Database migration শুধু `RUN_MIGRATIONS=1` দিলে চালায়।

Scriptটি real password, API key বা private key file তৈরি করে না এবং কোনো secret terminal-এ print করে না।

## ১. GitHub Deploy Key তৈরি করুন

VPS-এ root বা deployment user হিসেবে key তৈরি করুন:

```bash
sudo install -d -m 0700 /root/.ssh
sudo ssh-keygen -t ed25519 \
  -C "ipms-vps-readonly-deploy" \
  -f /root/.ssh/ipms_deploy
```

Passphrase চাইলে একটি আলাদা passphrase ব্যবহার করুন। Unattended deployment-এর জন্য passphrase-less key ব্যবহার করলে file permission ও server access আরও কঠোরভাবে নিয়ন্ত্রণ করতে হবে।

Public key দেখুন:

```bash
sudo cat /root/.ssh/ipms_deploy.pub
```

শুধু `.pub` file-এর content GitHub-এ যোগ করবেন। Private key `/root/.ssh/ipms_deploy` কখনো GitHub, chat, screenshot বা repository-তে পাঠাবেন না।

## ২. GitHub repository-তে key যোগ করুন

GitHub-এ যান:

```text
Repository → Settings → Deploy keys → Add deploy key
```

তারপর:

| Field | Value |
|---|---|
| Title | `IPMS VPS read-only` |
| Key | `/root/.ssh/ipms_deploy.pub`-এর content |
| Allow write access | বন্ধ রাখুন |

Deploy Key repository-specific। অন্য repository-তে এটি স্বয়ংক্রিয়ভাবে কাজ করবে না।

## ৩. Script VPS-এ কপি করুন

এই fileটি VPS-এ কপি করুন:

```bash
scp deploy-with-github-deploy-key.sh ubuntu@YOUR_SERVER_IP:/tmp/
```

VPS-এ SSH করে script review করুন:

```bash
ssh ubuntu@YOUR_SERVER_IP
less /tmp/deploy-with-github-deploy-key.sh
bash -n /tmp/deploy-with-github-deploy-key.sh
```

Unknown script সরাসরি remote pipe করে root হিসেবে চালাবেন না।

## ৪. First deployment চালান

আগে নিশ্চিত করুন:

- `/root/.ssh/ipms_deploy` private key file আছে;
- key-এর public অংশ GitHub Deploy Key হিসেবে যোগ করা হয়েছে;
- `/var/www/ipms` directory নতুন বা খালি;
- Composer, PHP এবং Nginx আগে install করা হয়েছে;
- Node.js/npm দরকার হলে আগে install করা হয়েছে।

তারপর:

```bash
sudo APP_DIR=/var/www/ipms \
  REPO_SSH=git@github.com:abdurrazzak2895-debug/web.git \
  DEPLOY_KEY=/root/.ssh/ipms_deploy \
  BRANCH=master \
  APP_URL=https://your-domain.example \
  BUILD_FRONTEND=1 \
  RUN_MIGRATIONS=0 \
  bash /tmp/deploy-with-github-deploy-key.sh
```

Scriptটি clone, Composer install, frontend build এবং Laravel cache preparation করবে। এটি existing `.env` overwrite করবে না।

## ৫. `.env` পূরণ করুন

```bash
sudo nano /var/www/ipms/.env
```

Production-এর জন্য অন্তত এই ধরনের values দিন:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

CACHE_STORE=database
SESSION_DRIVER=file
QUEUE_CONNECTION=redis

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ipms
DB_USERNAME=ipms_user
DB_PASSWORD=use-a-long-unique-password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=use-a-long-unique-password
```

Real secrets শুধু server-এর `.env`-এ রাখবেন। `.env` GitHub-এ commit করবেন না।

## ৬. Database migration চালান

Database connection test করার পর:

```bash
cd /var/www/ipms
sudo -u www-data php artisan migrate --force
```

প্রথম command-এ `RUN_MIGRATIONS=0` রাখা হয়েছে কারণ migration irreversible schema change করতে পারে। Migration আগে database backup নিন।

## ৭. Nginx ও worker configuration

Nginx-এর document root হবে:

```text
/var/www/ipms/public
```

Nginx configuration test করুন:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Queue worker Supervisor দিয়ে চালান:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart ipms-worker:*
```

নতুন code deploy করার পর:

```bash
cd /var/www/ipms
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan queue:restart
sudo supervisorctl restart ipms-worker:*
```

## ৮. GitHub access test

Script নিজেই access পরীক্ষা করে। আলাদাভাবে পরীক্ষা করতে চাইলে secret content print না করে চালান:

```bash
sudo GIT_SSH_COMMAND='ssh -i /root/.ssh/ipms_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes' \
  git ls-remote git@github.com:abdurrazzak2895-debug/web.git refs/heads/master
```

Expected output একটি commit hash এবং branch reference হবে। `Permission denied (publickey)` হলে public key GitHub-এ সঠিক repository-তে যোগ হয়েছে কিনা পরীক্ষা করুন।

## ৯. Security checklist

| বিষয় | নিয়ম |
|---|---|
| Deploy Key | Read-only রাখুন; write permission দেবেন না |
| Private key | শুধু VPS-এ `chmod 600` permission-এ রাখুন |
| `.env` | GitHub-এ commit করবেন না |
| API keys | Chat, screenshot বা shell history-তে রাখবেন না |
| APP_KEY | Existing application-এ অকারণে regenerate করবেন না |
| Directory | Nginx root `/var/www/ipms/public` হবে |
| Database | Public internet-এ port expose করবেন না |
| Redis | Public internet-এ port expose করবেন না |
| Migration | Backup নিয়ে explicit command-এ চালান |

## Script variables

| Variable | Default | ব্যবহার |
|---|---|---|
| `APP_DIR` | `/var/www/ipms` | Application directory |
| `REPO_SSH` | repository example | GitHub SSH repository |
| `BRANCH` | `master` | Clone branch |
| `DEPLOY_KEY` | নেই | Private Deploy Key path; অবশ্যই দিতে হবে |
| `APP_URL` | খালি | New `.env`-এ `APP_URL` বসায় |
| `BUILD_FRONTEND` | `1` | `1` হলে `npm ci` ও `npm run build` চালায় |
| `RUN_MIGRATIONS` | `0` | `1` হলে `php artisan migrate --force` চালায় |
| `WEB_USER` | `www-data` | Laravel writable directories-এর owner |
| `WEB_GROUP` | `www-data` | Laravel writable directories-এর group |

## References

[1]: https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys "GitHub: Managing deploy keys"

[2]: https://docs.github.com/en/authentication/connecting-to-github-with-ssh/checking-for-existing-ssh-keys "GitHub: Checking for existing SSH keys"

[3]: https://laravel.com/docs/deployment "Laravel: Deployment"
