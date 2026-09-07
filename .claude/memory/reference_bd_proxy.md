---
name: reference-bd-proxy
description: BD/HTTP proxy URL for running the captcha Algorithm Monitor / analyze_captcha_algo.py
metadata: 
  node_type: memory
  type: reference
  originSessionId: 32d426d1-0155-4fd3-8a88-fe36960a5091
---

Proxy for fetching the live IVAC bundle in the captcha Algorithm Monitor and `app/Scripts/analyze_captcha_algo.py`:

**UPDATE Sep 7 2026 — old proxy STILL VALID, re-verified live:** exit IP `37.111.194.200` = **Dhaka, Bangladesh, GrameenPhone (AS24389)**; `GET appointment.ivacbd.com/signin` through it returned the expected site-wide 403 booking notice (site closed, not a proxy fault). Still the working credential.

**NEW credential Sep 7 2026 (user-supplied) — DOES NOT AUTHENTICATE (407):** `abdurrazzak7395_lZjmx` / `pAa1m9jNTsXg=eYF`. Tried: raw on `bd-pr.oxylabs.io:30001`, password `%3D`-encoded, `customer-`-prefixed username on ports 30000+30001, and `customer-abdurrazzak7395_lZjmx-cc-bd` on `pr.oxylabs.io:7777` — all `CONNECT tunnel failed, response 407`. Suspect transcription error (username lacks the `customer-` prefix Oxylabs issues) or account not yet active. Do NOT switch settings.captcha_bd_proxy_url to it until it passes `curl -x ... https://api.ipify.org`.

**Current (June 13 2026 — verified valid, exit IP 163.47.157.57 BD):**
`http://customer-smensulaiman_0O1gd:OTUw=ks3N~8TUD@bd-pr.oxylabs.io:30001`

**Previous (replaced):**
`http://user1:Dhaka%40123@151.158.125.203:1282`

Set as default in `resources/js/pages/CaptchaAlgorithm/Index.vue` (`proxyUrl` ref).
Also persisted in browser `localStorage` key `captcha_monitor_proxy_v2` after first run.
Pass it as the script's first arg or paste into the monitor's proxy field. Used when re-deriving seeds on IVAC redeploy — see [[kb_captcha_algorithm_verification]].
