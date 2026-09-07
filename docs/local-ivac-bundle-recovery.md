# IVAC `ivac-bundle.js` সংগ্রহের লোকাল গাইড

এই গাইডটি **অনুমোদিত browser session** ব্যবহার করে IVAC-এর public JavaScript bundle সংগ্রহ, যাচাই এবং repository-র existing analyzer-এ দেওয়ার জন্য। Cloudflare CAPTCHA বা WAF bypass করার জন্য TLS fingerprint spoofing, CAPTCHA automation, proxy rotation বা challenge-solving code ব্যবহার করা যাবে না। CAPTCHA ধাপটি মানুষকে browser-এ manually সম্পন্ন করতে হবে।

## ১. আগে repository-এর recovery rules বুঝুন

প্রজেক্টে active files হলো:

```text
storage/app/captcha/ivac-bundle.js
storage/app/captcha/encrypt_meta.json
```

Versioned archive থাকে:

```text
storage/app/captcha/bundles/<sha256>.js
```

`encrypt_meta.json` হলো commit marker। নতুন bundle লিখে metadata সর্বশেষে লিখতে হয়, যাতে sidecar কখনো bundle ও metadata-এর mismatched pair না পড়ে। Existing analyzer এবং `CaptchaAlgorithmService::analyze()` এই invariant বজায় রাখে।

## ২. Browser DevTools পদ্ধতি — সবচেয়ে সহজ

### ধাপ ১: Official page খুলুন

Chrome/Chromium-এ খুলুন:

```text
https://appointment.ivacbd.com/signin
```

Cloudflare CAPTCHA দেখা গেলে সেটি **manually solve করুন**। CAPTCHA token, cookie বা browser profile কারও সঙ্গে share করবেন না।

### ধাপ ২: Bundle request খুঁজুন

1. `F12` চাপুন এবং **Network** tab খুলুন।
2. **Preserve log** এবং **Disable cache** চালু করুন।
3. Filter-এ লিখুন:

```text
js
```

4. Page reload করুন।
5. `assets/` path-এর বড় hashed JavaScript file খুঁজুন। সাধারণত নামের ধরন এমন:

```text
/assets/mqxxxxxx-XXXXXXXX.js
```

6. Request-টি খুলে **Response** tab থেকে content যাচাই করুন। এটি IVAC application-এর JavaScript bundle হওয়া উচিত; Cloudflare error HTML হওয়া উচিত নয়।
7. Request-এ right-click করে **Copy → Copy URL** নিন।
8. **Save all as HAR with content** করলে request-এর authenticated browser context সংরক্ষণ করা যায়। HAR ফাইল public repository-তে commit করবেন না।

### ধাপ ৩: File save করুন

Bundle response-এর content একটি নতুন local file হিসেবে save করুন:

```text
storage/app/captcha/ivac-bundle.js
```

শুধু browser-এর Response panel-এর content কপি করুন। HTML error page, `403`, `503`, বা `cf-mitigated: challenge` response কখনো bundle হিসেবে save করবেন না।

## ৩. Browser console দিয়ে public asset URL যাচাই

CAPTCHA manually solve করার পর একই page origin-এর DevTools Console-এ এই read-only check চালানো যায়:

```js
const scripts = [...performance.getEntriesByType('resource')]
  .map(x => x.name)
  .filter(x => /\/assets\/.*\.js(?:\?|$)/.test(x));
console.table(scripts);
```

Bundle URL পাওয়ার পর browser session থেকেই status check:

```js
const url = scripts.sort((a, b) => b.length - a.length)[0];
const response = await fetch(url, { credentials: 'same-origin' });
console.log({ url, status: response.status, type: response.headers.get('content-type') });
```

`status` অবশ্যই `200` এবং `content-type` JavaScript হওয়া উচিত। Response-এর প্রথম অংশে HTML থাকলে এটি bundle নয়।

## ৪. Playwright persistent browser পদ্ধতি

এটি CAPTCHA solve করে না। এটি কেবল manual verification-এর পরে একই local browser profile ব্যবহার করে public resource save করে। প্রথম run-এ browser খুলে CAPTCHA নিজে solve করতে হবে।

একটি local project-এ:

```bash
mkdir -p ~/ivac-bundle-capture
cd ~/ivac-bundle-capture
npm init -y
npm install playwright
npx playwright install chromium
```

`capture-bundle.mjs` নামে ফাইল তৈরি করুন:

```js
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const profile = process.env.IVAC_BROWSER_PROFILE || `${process.env.HOME}/.ivac-browser-profile`;
const output = process.env.IVAC_BUNDLE_OUT || `${process.cwd()}/ivac-bundle.js`;

const browser = await chromium.launchPersistentContext(profile, {
  headless: false,
  viewport: { width: 1365, height: 900 },
});

const page = await browser.newPage();
const candidates = [];

page.on('response', async (response) => {
  const url = response.url();
  const type = response.headers()['content-type'] || '';
  if (!/\/assets\/.*\.js(?:\?|$)/.test(url) || !type.includes('javascript')) return;
  if (response.status() !== 200) return;
  const body = await response.body();
  if (body.length > 500_000) candidates.push({ url, body });
});

await page.goto('https://appointment.ivacbd.com/signin', { waitUntil: 'domcontentloaded' });
console.log('If Cloudflare asks for a CAPTCHA, solve it manually in the opened browser.');
console.log('Press Enter in this terminal after the page is usable.');
await new Promise(resolve => process.stdin.once('data', resolve));
await page.reload({ waitUntil: 'networkidle', timeout: 120_000 }).catch(() => {});
await page.waitForTimeout(5_000);

if (!candidates.length) {
  throw new Error('No large public JavaScript asset was captured. Check the page, live window, and Network tab.');
}

candidates.sort((a, b) => b.body.length - a.body.length);
const selected = candidates[0];
await fs.writeFile(output, selected.body, { mode: 0o600 });
console.log(JSON.stringify({ output, url: selected.url, bytes: selected.body.length }));
await browser.close();
```

Run it:

```bash
node capture-bundle.mjs
```

The script selects only a successful, large JavaScript asset. It does not solve or bypass the CAPTCHA.

## ৫. Local integrity checks

Run these checks before using the file:

```bash
file ivac-bundle.js
wc -c ivac-bundle.js
head -c 120 ivac-bundle.js; printf '\n'
sha256sum ivac-bundle.js
```

A valid bundle should not begin with:

```text
<!DOCTYPE html>
<html
Sorry, you have been blocked
```

You can also reject obvious error pages:

```bash
! grep -qiE 'sorry, you have been blocked|attention required|cf-mitigated|captcha' ivac-bundle.js
```

## ৬. Run the repository analyzer locally

From the repository root, place the file at the expected path:

```bash
mkdir -p storage/app/captcha
cp ivac-bundle.js storage/app/captcha/ivac-bundle.js
python3 app/Scripts/analyze_captcha_algo.py - --bundle storage/app/captcha/ivac-bundle.js
```

The analyzer's local `--bundle` path is intended for recovery and regression analysis. It should produce a clean extraction for both required configurations. Do not copy a failed or incomplete result into production.

For production activation, use the application service rather than manually editing `encrypt_meta.json`:

```bash
php artisan tinker --execute='
$r = app(\\App\\Services\\CaptchaAlgorithmService::class)->analyze("");
echo json_encode([
  "error" => $r["error"] ?? null,
  "applied" => $r["auto_applied"]["applied"] ?? false,
  "reason" => $r["auto_applied"]["reason"] ?? null,
]);
'
```

The application must have the verified bundle available in its own `storage/app/captcha` directory before this command is run. The command performs extraction, version registration, atomic activation, and sidecar coordination.

## ৭. Railway-specific note

Railway service filesystems are deployment/container-specific and should not be treated as a permanent manual upload location. Do not commit `ivac-bundle.js`, `encrypt_meta.json`, browser profiles, HAR files, or cookies to GitHub.

Use one of the repository's supported release paths to put the verified file into the web service, then run the application import/analysis command. If the current project does not have a secure file-upload/release path, add one-time authenticated operational tooling rather than exposing the bundle directory publicly.

After activation, verify:

```bash
curl -fsS https://ivacbd.up.railway.app/login >/dev/null
railway ssh --service web -- sh -lc 'find storage/app/captcha -maxdepth 2 -type f -printf "%p %s bytes\\n" | sort'
```

Do not expose `storage/app/captcha` through a public unauthenticated route. The solver sync endpoints must remain protected by `CAPTCHA_SYNC_TOKEN`.

## ৮. Rollback

If extraction is unclean or IVAC starts returning captcha `400` errors, do not activate the new pair. Use the Algorithm Monitor's **Bundle Versions** panel to activate the last known-good version. The versioning system keeps bundle and metadata activation atomic.

## সংক্ষিপ্ত operational checklist

| Check | Expected result |
|---|---|
| Manual CAPTCHA | Completed in official browser only |
| Captured resource | Large `.js` asset, HTTP 200 |
| Content type | JavaScript, not HTML |
| Local hash | Recorded for traceability |
| Analyzer | Clean extraction for login and reserve |
| Metadata | Written only by application service |
| Production storage | Bundle and metadata in same active pair |
| Solver sync | Protected endpoint, token required |
| Rollback | Last-known-good version available |
| Secrets | No cookies, HAR, token, or password committed |

> The browser session is for authorized access and manual verification. It is not a Cloudflare bypass mechanism.
