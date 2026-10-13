# DURONTO / IPMS Web

Laravel 12 + Inertia + Vue 3 application for the DURONTO / IPMS booking automation platform, including the
admin console, the in-house captcha stack, the payment helper and the companion desktop/mobile components.

> **This repository is public.** Never commit real credentials, tokens, `.env` files, private keys or
> customer data. See [SECURITY.md](SECURITY.md).

## Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.4 (Composer requires `^8.2`), Laravel 12 |
| Frontend | Vue 3 + TypeScript, Inertia 2, Vite 6, Tailwind CSS 4 |
| Auth | Laravel Fortify + Sanctum (stateful domains) |
| Realtime | Laravel Reverb (`config/reverb.php`, `resources/js` Echo) |
| Routing helpers | Laravel Wayfinder (`@laravel/vite-plugin-wayfinder`) |
| Database | MySQL (SQLite for local/Railway bootstrap) |
| Cache / queue | Database or Redis (predis), queue workers |
| Tooling | Pint, ESLint, Prettier, PHPUnit, Laravel Boost |
| Node | 23 (`.nvmrc`); Docker image builds with Node 22 |

## Repository layout

```
app/                    Laravel application code (Actions, Services, Jobs, Scripts, …)
bootstrap/  config/     Framework bootstrap and configuration
database/               Migrations and seeders
resources/js/pages/     Inertia pages (admin console screens)
routes/                 web.php, api.php, console.php, settings.php, channels.php
tests/                  PHPUnit feature tests
deploy/                 VPS deployment assets: systemd units, nginx, install scripts, runbooks
railway/                Railway container entrypoints (init, ssh, worker, scheduler)
cf-worker/              Cloudflare Worker relay (wrangler)
solver-node/            Standalone Node captcha solver service
ipms_java/              Java booking component (Maven, `target/ivac-booking.jar`)
ipms_sms_android/       Android SMS companion app (Gradle) + distributed `DURONTO.apk`
ipms_payment_helper/    Browser extension payment helper (built zip)
docs/                   Internal playbooks
```

The admin console lives under `resources/js/pages/` (Accounts, ApiTester, ApkManager, BotControl,
CaptchaControl, SlotLogs, VpsManager, Wallet, …) and is driven by `routes/web.php` + `routes/api.php`.

## Local setup

```bash
# 1. PHP + JS dependencies, .env, app key, migrations, frontend build
composer setup

# 2. Configure your own .env (never commit it)
cp .env.example .env
php artisan key:generate

# 3. Run the dev stack (server + queue listener + Vite)
composer dev
```

Other useful scripts:

```bash
composer lint          # Pint
composer test          # Pint (test mode) + PHPUnit
npm run format         # Prettier over resources/
npm run lint           # ESLint --fix
npm run build          # production Vite build
```

## Configuration

Copy `.env.example` to `.env` and fill in the values you need. Key groups:

- **Application** — `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_LOCALE`
- **Database / cache / queue** — `DB_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `REDIS_*`, `SESSION_*`
- **Captcha** — `CAPTCHA_GET_URL`, in-house captcha bundle settings
- **Cloudflare Turnstile** — `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` (protects `/login` and `/register`)
- **Payments** — `TOKENHUNTER_PUBLISH_URL`, `TOKENHUNTER_PUBLISH_TOKEN`
- **Google** — `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_PUBSUB_TOPIC`
- **Realtime** — `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`
- **APK distribution** — `APK_SYNC_BUNDLED_RELEASE`, `APK_BUNDLED_VERSION`, `APK_BUNDLED_FILE_NAME`
- **Access gate** — `PAGE_PASSWORD`

All secrets belong in the environment (or the host's secret manager), never in the repository.

## Deployment

Two supported targets:

- **Railway** — root `Dockerfile` (PHP 8.4 CLI + Node runtime + Composer), `.railway/railway.ts`, and the
  entrypoints in `railway/` (`init-app.sh`, `start-laravel-ssh.sh`, `run-worker.sh`, `run-scheduler.sh`,
  `start-ssh-ttyd.sh`). Runbooks: `deploy/README-railway-live-bn.md`, `deploy/README-railway-ssh-ttyd-bn.md`.
- **Ubuntu VPS** — systemd units and nginx configs in `deploy/`, installed via `deploy/secure-install.sh`.
  Runbooks: `deploy/README-ubuntu-stack-bn.md`, `deploy/README-github-deploy-bn.md`,
  `deploy/README-scheduler.md`.

Captcha components have their own runbooks: `deploy/README-in-house-captcha.md`,
`deploy/README-captcha-encrypt.md`, and `solver-node/README.md`.

## CI

`.github/workflows/lint.yml` (Pint + Prettier + ESLint) and `.github/workflows/tests.yml` (PHPUnit) run on
pushes and pull requests to `master`, `main`, `develop` and `workos`.

## Contributing

1. Branch from `master`.
2. Keep secrets out of the diff; run `composer test` and `npm run build` before opening a PR.
3. Do not commit build output, logs, database dumps or large binaries — attach release artifacts to a
   GitHub Release instead.
