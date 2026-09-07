---
name: project-railway-deploy
description: "Railway deployment of the portal — project IVACBD SLOT (ALAMIN account), IaC services, admin bootstrap gotcha"
metadata:
  node_type: memory
  type: project
  originSessionId: railway-full-check-20260907
---

## Railway deployment — "IVACBD SLOT" (Sep 2026)

Dashboard: `https://railway.app/project/4666ebb8-be41-4cc3-bd2d-85810b12f1ea`

- **Account:** ALAMIN (`alamin200076@gmail.com`) — Railway CLI `railway whoami`. (The user separately mentioned `aminea01578616@gmail.com`; the CLI is logged in as ALAMIN.)
- **Workspace:** ALAMIN's Projects
- **Environment:** production (id `91804d38-ce31-4de8-899c-fb5fd6e187b4`)
- **Project name in IaC:** `duronto-ipms` (`.railway/railway.ts`)
- **Source:** GitHub `abdurrazzak2895-debug/web` branch `master` (web/worker/scheduler), region `iad`
- **Public URL:** `https://ivacbd.up.railway.app` (web service, port 8080, healthcheck `/login` 200)

## Services
| Service | Status (Sep 7 2026) | Start command |
|---|---|---|
| web | SUCCESS | preDeploy `railway/init-app.sh`, build `npm run build` |
| worker | SUCCESS | `bash railway/run-worker.sh` |
| scheduler | SUCCESS | `bash railway/run-scheduler.sh` |
| Redis + redis-volume | Online | regexport `--requirepass $REDIS_PASSWORD` |
| MySQL + mysql-volume | Online | `mysqld --innodb-use-native-aio=0 ...` |

## CRITICAL: admin bootstrap (init-app.sh)
- `railway/init-app.sh` runs `php artisan migrate` then **only creates/updates the super-admin when `BOOTSTRAP_ADMIN_EMAIL` AND `BOOTSTRAP_ADMIN_PASSWORD` are set** (uses `updateOrCreate` on email, sets `role=super_admin`, `is_approved=true`). On a fresh Railway project these three (`..._EMAIL`, `..._NAME`, `..._PASSWORD`) were **missing** → no admin could log in.
- **Fixed Sep 7 2026:** set on web service via `railway variable set BOOTSTRAP_ADMIN_EMAIL/NAME/PASSWORD=...` (one deploy). Admin is `abdurrazzak7395@gmail.com` (super_admin). Password was generated and shown in chat — change it from the portal (Profile → password) if not already done.
- Note: `users.id=1` already existed before (created ~Sep 6 23:28 BDT on first deploy) with an unknown email — left untouched; verify it isn't a stray duplicate.

## Railway CLI quirks (Windows)
- PATH has **two** `railway` installs: nvm4w's `5.37.7` (used by `railway`) and AppData Roaming npm `5.49.x`. The newer one must be invoked as `& 'C:\Users\User\AppData\Roaming\npm\railway.cmd'` — plain `railway` still resolves v5.37.7.
- `railway config plan/apply` (IaC, needs SDK ≥5.42.1) FAILS on this box ("node failed to evaluate IaC file" / "MINIMUM_IAC_CLI_MESSAGE" / "CLI 5.42.1 or newer"). The IaC state was **not** drift-applied; variables were set directly with `railway variable set --service web --project <id> --environment <id>` (safe: idempotent, preserves secrets).
- `railway ssh ...` works only after registering a local public key (`railway ssh keys add -k <pub>`); the SSH session then hangs waiting — run it detached/batch file and kill stray `ssh.exe` afterwards (it holds log-file handles).
- The CLI prints variables with `railway variable list --json`; values reveal non-secrets only. Secret deltas vs `.env.example` must be reviewed manually — current project is missing the optional-but-useful `TURNSTILE_*`, `GOOGLE_CLIENT_*`, `MAIL_*`, `PAGE_PASSWORD`, `TOKENHUNTER_*`.

## Repo hook
The web repo auto-commits on every PostToolUse (chown+chmod+build) — any temp/diagnostic file you create will be swept into a commit; clean up scratch files or they land in git history (see `1926d0a`).