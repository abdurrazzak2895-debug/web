---
name: railway-captcha-solver-amine
description: "amine account captcha-solver Railway service — diagnosis + fix of CRASHED solver (bundle sync), plus auth requirements"
metadata:
  node_type: memory
  type: incident
  originSessionId: captcha-solver-amine-fix-20260907
---

## captcha-solver-amine (Railway, AL AMINE account) — fixed Sep 7 2026

- **Account:** aminea01578616@gmail.com ("AL AMINE"), workspace "AL AMINE's Projects".
- **Project:** `captcha-solver-amine` (id `af805a4c-f09d-45bf-bed9-aecd45c88ca9`), env `production` (id `3f9d9576-...`), **single service** `captcha-solver-amine`.
- **Domain:** `https://captcha-solver-amine-production.up.railway.app` → port **8788** (the in-house solver, not a Laravel web app).

## Why it was down
Every endpoint returned **502**; deployments were CRASHED/FAILED. Logs showed:
```
bundle sync attempt N/60 failed: bundle: HTTP 503 ... (repeated)
captcha bundle sync exhausted all retries; refusing to start an unhealthy solver
```
Two things combined:
1. `deploy/captcha-solver-entrypoint.sh` fetches bundle+meta from `$CAPTCHA_BUNDLE_URL`/`$CAPTCHA_META_URL` with header **`X-Captcha-Sync-Token: $CAPTCHA_SYNC_TOKEN`** (line 28-31). It retries 60×5s then **`exit 1`** → container exits → 502.
2. The deployed vars pointed at **`https://ivacbd.up.railway.app/internal/captcha/...`** — which is 404 unless the request carries the correct token. The web route (`routes/web.php:22-38`) requires exact `X-Captcha-Sync-Token` match + `storage/app/captcha/ivac-bundle.js` present (503 if missing). At deploy time the token/header did not match or the file did not exist yet → 503/404 through the whole retry window → crash.

## The fix (no code change needed)
- Redeploy (which reruns sync) after ensuring the web app had the bundle on disk. Confirmed with a live curl using the token:
  - `internal/captcha/ivac-bundle.js` → 200, 2,316,681 B
  - `internal/captcha/encrypt_meta.json` → 200, 491 B
- Solver then booted: `synced bundle: 2316681 bytes`, `synced metadata: 491 bytes`, `listening on http://0.0.0.0:8788`.
- Verified live: `GET /health` with header **`x-captcha-solver-token: <token>`** → `{ok:true, chrome:"idle", pool:{...}}`. (`Authorization: Bearer` is NOT accepted — the solver wants `x-captcha-solver-token`; check `app/Scripts/in_house_captcha_solver.cjs::isAuthorized`.)

## Solver env (service vars)
- `CAPTCHA_BUNDLE_URL` / `CAPTCHA_META_URL` → `https://ivacbd.up.railway.app/internal/captcha/{ivac-bundle.js,encrypt_meta.json}`
- `CAPTCHA_SYNC_TOKEN` = same hex as `CAPTCHA_SOLVER_API_TOKEN`
- `CAPTCHA_SOLVER_PORT=8787`, `CAPTCHA_SOLVER_HOST=0.0.0.0`, `CAPTCHA_SOLVER_CONCURRENCY=2`, `CAPTCHA_SOLVER_BROWSERS=1`, `CAPTCHA_SOLVER_PREWARM=0`, `CAPTCHA_SYNC_RETRIES=60`, `CAPTCHA_SYNC_RETRY_SECONDS=5`
- Fleet off (no `CAPTCHA_NODE_KEY`) — loopback `/solve` only.

## Lesson / playbook
- When solver on Railway 502s: check `railway logs --service ... --lines 120` for `bundle sync` errors first — 9/10 it's the token/URL/absent-file, all self-healable by re-deploy once the web has the file.
- Auth for solver HTTP surface: `x-captcha-solver-token` header = `CAPTCHA_SOLVER_API_TOKEN`, NOT `Authorization: Bearer`.
- Web `/internal/captcha/*` endpoints require the SAME token; 404 = wrong/missing token, 503 = file not yet on disk.