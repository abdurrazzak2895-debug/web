---
name: kb-ivac-bundle-fetch
description: "Proxy-free, challenge-free way to discover AND download the live IVAC captcha JS bundle — /.well-known/ leaks index.html (name+version), .js assets are un-challenged via any CF edge IP"
metadata: 
  node_type: memory
  type: reference
  originSessionId: e4561b16-035c-4286-89df-de2042c803f7
---

How to grab the live `appointment.ivacbd.com` captcha bundle WITHOUT the BD proxy or a challenge-solver. Verified end-to-end Jun 25 2026 from the Khulna BD server (144.79.249.8), reproducible 5/5.

**Cloudflare's challenge is per request-TYPE, not per IP/geo:**
- HTML / non-static routes (`/`, `/signin`, `/index.html`, any `.json`, arbitrary paths) → **403 managed challenge** (`cf-mitigated: challenge`), even when you hit a CF edge IP directly. This is why discovery used to "need the proxy."
- `.js` / static-extension assets (`/assets/<hash>.js`) → **NO challenge**, edge-cached (`cache-control: immutable, max-age=1yr`, `cf-cache-status: HIT`). Downloadable by name from anywhere, any IP, no proxy. First request after a redeploy is a cache MISS (CF pulls origin once) but still un-challenged.

**UPDATE Sep 7 2026 — notice wording has drifted; matcher still holds:** live capture (403, 840 bytes on `/.well-known/`, `/signin`, AND `/assets/mqtdered-BRn12d0h.js`) shows the notice now carries **no "APPOINTMENT BOOKING GUIDELINES" heading and no "Appointment booking opens at" line** — the only surviving marker is the **"IMPORTANT NOTICE" badge** (`looksLikeBookingNotice()`'s second check). New hours text: "Appointment booking shall remain open from 12:00 Noon to 05:00 PM on Sunday to Thursday, and Saturday 10:00 AM to 12:00 PM." The `/signin` variant also appends Cloudflare's `challenge-platform` bootstrap script, so that body matches BOTH `looksLikeBookingNotice()` and `looksLikeChallenge()` — the notice check runs first in `discoverEntry()`/`downloadEntry()`, which is correct (site-closed beats challenge). Verbatim body + regression cases live in `tests/Feature/Captcha/IvacEdgeBundleFetcherTest.php::bookingNoticeHtml()`.

**BUT — off-hours "booking-guidelines" notice blocks EVERYTHING (added Jun 25 2026):** outside the live booking window, IVAC turns on a **Cloudflare-edge notice** that returns **HTTP 403 + a ~1000-byte static HTML** ("IMPORTANT NOTICE / APPOINTMENT BOOKING GUIDELINES", brand colors `#004638`/`#FF671F`) for **every path — including `/.well-known/` AND `/assets/<hash>.js`**. It runs before cache/origin (`server: cloudflare`, no `cf-cache-status`), so it overrides even the immutable cached asset. **Confirmed site-wide, NOT an IP block:** the BD/Oxylabs proxy (different exit IP) gets the identical 403 notice. This was the user's "CF has that static notice page" intuition — correct: it IS Cloudflare-served, not the origin app (distinct from the in-SPA `/appointment/notice` React route, which only shows AFTER passing CF). While it's up, NOTHING is fetchable — no edge-IP/proxy trick helps. It's **time-gated**: same asset was `200 cache HIT` at 18:11 BDT, then `403 notice` at 21:11 BDT. Lifts during the live window (signup 2:00–4:30 PM, booking opens 5:00 PM BDT) when the real SPA + assets serve `200`. So you can only fetch a fresh bundle during the live window — which is also the only time you'd need one. `IvacBundleController` detects this body (`looksLikeBookingNotice`) and returns `{notice_active:true}` + a clear message (503); the page shows an amber "notice active" banner instead of a generic failure.

**Discovery channel (the breakthrough): `GET /.well-known/`** — Cloudflare always exempts `/.well-known/*` from the challenge (reserved for ACME/security.txt), and IVAC's SPA serves `index.html` for that unmatched route. So it returns, challenge-free:
`<script defer crossorigin src="/assets/mqtdered-BRn12d0h.js"></script>` + `<meta name="version" content="1.0.3">`.
- Needs a trailing slash or sub-path: `/.well-known/` and `/.well-known/anything` → 200; bare `/.well-known` → 403.
- An arbitrary path like `/zzz` is NOT exempt → 403 challenge. Only `/.well-known` works.

**Full proxy-free flow (works even during the "site opens at 5:0X" notice window — notice only changes HTML, not the asset):**
```bash
NAME=$(curl -s https://appointment.ivacbd.com/.well-known/ \
       | grep -o 'assets/mq[a-z0-9]*-[A-Za-z0-9_-]*\.js' | head -1)
curl -s -o "$(basename "$NAME")" "https://appointment.ivacbd.com/$NAME"
```
To bypass DNS entirely, add `--resolve appointment.ivacbd.com:443:104.26.14.90` (CF edge IPs: 104.26.14.90 / 104.26.15.90 / 172.67.68.164; SNI/Host must stay `appointment.ivacbd.com`).

**Why the user got stuck (Jun 25):** during the 5:00–5:04 window the backend was live but the public page showed a notice behind the CF challenge → couldn't read the new hash name. Download was never the blocker; discovery was. `/.well-known/` removes that blocker.

**Bundle name pattern across redeploys:** `mq` + 6 chars + `-` + 8-char hash (`mq94v8ib-…`, `mqnnw71d-…`, `mqtdered-…`). Single monolithic ~2.1MB bundle — no code-splitting, no PWA service worker, no manifest/sitemap that lists it, so `/.well-known/`→index.html is the ONLY static name leak. `name="version"` meta also bumps on redeploy → cheap redeploy detector.

Corrects the old assumption in [[kb_ivac_origin_topology]] that the bundle must be fetched via the BD proxy. Still no API origin IP exists (api.ivacbd.com is CF→ALB→EKS). Related: [[reference_bd_proxy]], [[kb_captcha_algorithm_verification]], [[project_captcha_live_js_engine]]. TODO worth doing: switch `analyze_captcha_algo.py` discovery to `/.well-known/` + edge-IP download instead of the proxied cloudscraper path.
