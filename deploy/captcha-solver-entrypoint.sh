#!/usr/bin/env bash
set -Eeuo pipefail

: "${CAPTCHA_BUNDLE_URL:=http://web.railway.internal/internal/captcha/ivac-bundle.js}"
: "${CAPTCHA_META_URL:=http://web.railway.internal/internal/captcha/encrypt_meta.json}"
: "${CAPTCHA_SYNC_TOKEN:?CAPTCHA_SYNC_TOKEN must be set on the solver service}"
: "${CAPTCHA_SOLVER_STORAGE_DIR:=/app/storage}"
: "${CAPTCHA_SOLVER_CAPTCHA_DIR:=${CAPTCHA_SOLVER_STORAGE_DIR}/captcha}"
: "${CAPTCHA_SYNC_RETRIES:=30}"
: "${CAPTCHA_SYNC_RETRY_SECONDS:=5}"

mkdir -p "$CAPTCHA_SOLVER_CAPTCHA_DIR"

export CAPTCHA_BUNDLE_URL CAPTCHA_META_URL CAPTCHA_SYNC_TOKEN CAPTCHA_SOLVER_CAPTCHA_DIR

node <<'NODE'
const fs = require('fs');
const path = require('path');

const bundleUrl = process.env.CAPTCHA_BUNDLE_URL;
const metaUrl = process.env.CAPTCHA_META_URL;
const token = process.env.CAPTCHA_SYNC_TOKEN;
const dir = process.env.CAPTCHA_SOLVER_CAPTCHA_DIR;
const retries = Number(process.env.CAPTCHA_SYNC_RETRIES || 30);
const retrySeconds = Number(process.env.CAPTCHA_SYNC_RETRY_SECONDS || 5);

async function fetchAsset(url, destination, label) {
  const response = await fetch(url, {
    headers: { 'X-Captcha-Sync-Token': token, Accept: '*/*' },
    signal: AbortSignal.timeout(15000),
  });
  if (!response.ok) throw new Error(`${label}: HTTP ${response.status}`);
  const body = Buffer.from(await response.arrayBuffer());
  if (body.length < 2) throw new Error(`${label}: empty response`);
  if (label === 'metadata') {
    try { JSON.parse(body.toString('utf8')); }
    catch { throw new Error('metadata: response is not valid JSON'); }
  }
  const temporary = `${destination}.tmp-${process.pid}`;
  fs.writeFileSync(temporary, body, { mode: 0o600 });
  fs.renameSync(temporary, destination);
  console.log(`synced ${label}: ${body.length} bytes`);
}

(async () => {
  for (let attempt = 1; attempt <= retries; attempt += 1) {
    try {
      await fetchAsset(bundleUrl, path.join(dir, 'ivac-bundle.js'), 'bundle');
      await fetchAsset(metaUrl, path.join(dir, 'encrypt_meta.json'), 'metadata');
      process.exit(0);
    } catch (error) {
      console.error(`bundle sync attempt ${attempt}/${retries} failed: ${error.message}`);
      if (attempt < retries) await new Promise(resolve => setTimeout(resolve, retrySeconds * 1000));
    }
  }
  console.error('captcha bundle sync exhausted all retries; refusing to start an unhealthy solver');
  process.exit(1);
})().catch(error => { console.error(error); process.exit(1); });
NODE

exec node /app/app/Scripts/in_house_captcha_solver.cjs
