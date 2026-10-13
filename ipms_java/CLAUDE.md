# IVAC Booking Automation

Automated visa appointment booking system for ivacbd.com. Multi-account, multi-threaded, with OTP-based auth via Firebase. JDK 26, OkHttp3, GSON, Logback.

## Bot Logic & Architecture

### Core Flow
1. **Config Fetch**: Load accounts & settings from Laravel API (`/api/config`)
2. **HTTP Client Init**: Create primary OkHttpClient + one bypass client per `bypassIps` entry in config
3. **Pre-OTP**: Send OTP via forgot-password (primary client), poll Firebase until OTP arrives — before sign-in
4. **Sign In** at window open (captcha required) → OTP pre-loaded in RaceContext
5. **Parallel Race**: 3 OTP verify threads (primary client) + `numClients × 3` slot threads (round-robin across clients)
6. **Slot Reserved** → first winner claims payment via primary client; others abort
7. **Retry Strategy**: Exponential backoff with ±25% jitter; 429→20s shared backoff; 503→retry immediately

### HTTP Client Architecture (April 2026)

**OkHttp3** for all IVAC API calls. Two client types created after config fetch:

| Client | Factory | DNS | Used for |
|--------|---------|-----|---------|
| Primary | `IvacHttpClient.direct(phone)` | Normal (Cloudflare) | sendOtp, sign-in, OTP verify (T1), payment |
| Bypass-N | `IvacHttpClient.bypass(ip, phone)` | Override `api.ivacbd.com` → bypass IP | OTP verify (T2/T3) + slot reservation |

**Bypass mechanism**: Custom OkHttp `Dns` maps `api.ivacbd.com` → origin IP. URL stays `https://api.ivacbd.com/...` so TLS SNI is correct automatically — no URL rewriting or Host header tricks needed.

**Shared `ConnectionPool`** across all clients (10 connections, 5-min keepalive). ApiLogInterceptor on each client logs all requests to portal DB.

### Slot Parallel Architecture

After sign-in, threads race in parallel:
```
OTP verify:   allOtpServices × round-robin (3 tasks, distributed across primary + bypass)
Slot probe:   allSlotClients × 3 concurrent tasks each (round-robin)
```

Example with 2 bypass IPs (3 total clients):
```
OTP[PRIMARY-T1], OTP[BYPASS-0-T2], OTP[BYPASS-1-T3]   ← 3 tasks, round-robin across all clients
SLOT[PRIMARY-T1..T3]                                    ← 3 tasks, primary client
SLOT[BYPASS-0-T1..T3]                                   ← 3 tasks, bypass IP 0
SLOT[BYPASS-1-T1..T3]                                   ← 3 tasks, bypass IP 1
```
Total: 3 OTP + 9 slot = 12 parallel tasks with 2 bypass IPs. With no bypass IPs: 3 OTP (all primary) + 3 slot = 6 tasks.

- `sendOtp` and `pollFirebase` always use `primaryOtpService` (primary client, Cloudflare DNS) — Firebase is a different host so bypass is irrelevant
- `verifyOtp` uses bypass clients for tasks T2/T3 — same DNS override as slot reservation

- Slot threads fire at `raceStart + slotProbeDelayMs` (or immediately when OTP verifies, whichever comes first). If `slotProbeDelayMs=0`, all slot tasks fire immediately from race start (speculative). Server 401s during the speculative window retry every 500ms until OTP verifies.
- First OTP verify win → `RaceContext.setOtpVerified()` → any remaining blocked slot threads unblock immediately
- First slot reserve win → CAS `tryClaimPayment()` → one thread fires payment via primary client; others exit
- Race timeout: JWT validity (~899s)

### Key State Variables
- `RaceContext`: thread-safe phase signals (OTP verified, slot reserved, payment claimed/done), `raceStartedAtMs` anchor, CompletableFuture for payment result
- `SessionExpiryValidator`: JWT expiry check (899s from sign-in)
- `OtpPollingService`: Firebase polling shared across OTP threads

## Pre-OTP Flow (Runs Before Sign-In)

1. Wait until `windowStartTime - forgotPasswordLeadSeconds` (default 45s before window)
2. `OtpServiceImpl.sendOtp(email)` → POST `/forgot-password/sendOtp` via **primary client** (no auth)
3. Returns `requestId` (forgot-password) — stored in `RaceContext.currentRequestId`
4. `OtpServiceImpl.pollFirebase(otpTimeoutMs)` — wait until OTP appears in Firebase
5. Store pre-fetched OTP in `RaceContext.currentOtp`
6. 1 sign-in captcha queued in background while OTP polling runs

**Sign-in happens after OTP is in hand.**

### Dual RequestId Strategy
Two requestIds exist per cycle:
- `currentRequestId` — from forgot-password `sendOtp` call
- `signinRequestId` — from sign-in response (may differ)

OTP threads try `currentRequestId` first, then `signinRequestId` on mismatch, then poll Firebase 15s for a fresh OTP.

## Build & Run

```bash
# Always clean build (stale .class files cause phantom bugs)
JAVA_HOME=/usr/lib/jvm/jdk-26 mvn clean compile

# Run locally
JAVA_HOME=/usr/lib/jvm/jdk-26 mvn exec:java -Dexec.mainClass="com.ivac.booking.App" -q

# Debug: kill stale processes, clear logs
ps aux | grep java | grep -v grep
rm -f logs/*-api-debug.log
```

**Two run modes:**
- **Local (portal machine)**: portal runs `mvn exec:java` — no JAR needed, compile only
- **Distributed (VPS)**: systemd service `ipms-bot` runs `java -jar ivac-booking.jar <SLOT_API_KEY>` — fat JAR built by portal (`POST /api/bot/package`), never manually as root

**IMPORTANT**: Never run `mvn package` as root — leaves `target/` owned by root, breaks portal builds. Use the portal "Rebuild JAR" button instead.

## Key Classes & Responsibilities

| Class | Purpose |
|-------|---------|
| `App.java` | Entry point; loads config, spawns `AccountWorker` per account |
| `AccountWorker.java` | Creates HTTP clients, orchestrates pre-OTP→signin→parallel race |
| `RaceOrchestrator.java` | Launches OTP verify tasks + `numClients × 3` slot tasks, awaits payment result |
| `RaceContext.java` | Thread-safe shared state: OTP/slot/payment signals, CompletableFuture |
| `IvacHttpClient.java` | OkHttp3 wrapper; `direct(phone)` or `bypass(ip, phone)` factory methods |
| `OtpServiceImpl.java` | sendOtp (primary client), Firebase poll, OTP verify (both requestIds) |
| `SigninServiceImpl.java` | Sign-in with Turnstile captcha; probes from T-100ms, retries every 50ms for 2s |
| `PortalCaptchaClient.java` | Requests captcha tokens from portal API; polls every 250ms; 90s timeout |
| `SlotReservationServiceImpl.java` | Reserve slot with retry loop (429/503/401 handling) |
| `PaymentServiceImpl.java` | Initiate payment, extract gateway URL, validate response |
| `SessionExpiryValidator.java` | JWT expiry check (899s from sign-in); used for smart 401 detection |
| `OtpPollingService.java` | Firebase polling service (phone-only key `/otps/{phone}.json`) |
| `ApiLogInterceptor.java` | OkHttp interceptor that logs all requests/responses via ApiLogger |
| `RetryUtil.java` | Jitter, backoff tables, retry logic, socket timeout handling |
| `HttpUtil.java` | JSON serialization, request building, success flag validation |
| `TimeUtil.java` / `TimeWindowUtil.java` | Time conversions, deadline/window calculations |
| `ConsoleLogger.java` | Per-phone logging with timestamp and log level |

**Deleted (April 2026 — OkHttp rollback)**: `ForgotPasswordHttpClient.java`, `HttpClientFactory.java`, `BypassDnsResolverProvider.java`, `CachingDns.java`

**Deleted (April 2026 — centralized captcha)**: `FairCaptchaQueue.java`, `CapmonsterKeyFetcher.java`, `CaptchaTokenTransformer.java`, `CaptchaTransformSeedFetcher.java`

**Deleted (earlier)**: `PerLaneRecaptchaTokenService.java`, `LaneCoordinator.java`, `PipelineOrchestrator.java`, `AuthClient.java`

## API Log Analysis

Log files: `logs/{phone}-api-debug.log`
Format: `[timestamp] METHOD URL -> STATUS (duration)` followed by truncated REQ/RES bodies.

### When asked to check logs, follow this process:

1. Read `logs/api-debug.log`
2. Classify each response using the patterns below
3. For known patterns — report what happened and suggest the documented fix
4. For unknown/new patterns — **show the log entry to the user and ask**. Do NOT guess or refactor on your own.

### Known OK Patterns

- HTTP `200` on any endpoint with `successFlag: true` — normal, no action needed
- Firebase GET returning a JSON object with OTP data — normal OTP received

### Known Error Patterns

| Pattern | Meaning | Action |
|---------|---------|--------|
| `403 {"error":"invalid_referer"}` | `Referer` header leaked into request | Fix code: only send `Content-Type: application/json`, no Referer |
| `429` + `cf-mitigated: challenge` | Cloudflare rate-limit triggered | Increase retry delay; check for rapid retry loops |
| `401` on authenticated endpoint | Token expired | Auto re-auth should handle this; if recurring, check auth flow |
| `unexpected end of stream` | Cloudflare dropped the connection | Likely rate-limited; increase delay between requests |
| `successFlag: false` + message contains "limit" | OTP sending limit reached | Wait 5 minutes before retrying signin |
| Firebase GET returns literal `null` | No OTP exists yet | Keep polling; this is normal during OTP wait |

### Unknown/New Patterns

If the log contains a status code, error message, or response body not listed above:
- **Do NOT silently refactor or add error handling**
- Show the exact log entry to the user
- Ask what it means and what the expected behavior should be
- Only then make changes if the user confirms

## Captcha Strategy (Centralized, April 2026)

All captcha solving is centralized through the Laravel portal. The bot never calls CapMonster/2Captcha/CaptchaAI directly.

### Flow
1. Bot calls `PortalCaptchaClient.requestCaptcha(type)` → `POST /api/captcha/request` (open API, no auth)
2. Portal dispatches `SolveCaptchaJob` to Redis queue (`captcha`), picks a random enabled provider
3. Bot polls `GET /api/captcha/request/{id}` every 250ms until `status=ready` or `status=failed`
4. On `ready`: `token` returned with `solved_at_ms` (used as `CaptchaToken.createdAtMs`)
5. On `failed`: bot makes a fresh request

### Token Types
- `"turnstile"` — raw Turnstile token for **sign-in**
- `"turnstile_encrypted"` — Turnstile token with ft-function transform applied **by the portal** for **slot reservation**

### Pre-Fetch Per Cycle
- 1 signin captcha (`"turnstile"`) queued early — resolves while OTP polling runs in parallel
- Slot captchas fetched on-demand by each slot task via shared `CaptchaService`

### Key Class
`PortalCaptchaClient` — OkHttp3, 250ms poll, 90s timeout. No auth header needed.

### Token Transformation
- **Done on portal side** by `SolveCaptchaJob` using `CaptchaTokenTransformer::transform` (PHP, OFFSET=9, LENGTH=19)
- **WARNING**: Seed may change on IVAC site redeploy — symptom: 400 captcha rejected. Update seed in portal admin.

## Best Practices for Code Changes

### HTTP Client Rules
- Use `IvacHttpClient.direct(phone)` for all non-slot calls (sendOtp, signin, OTP verify, payment)
- Use `IvacHttpClient.bypass(ip, phone)` only for slot reservation with bypass IPs
- Never create ad-hoc `OkHttpClient` instances for IVAC calls — always go through `IvacHttpClient`
- The `postRawNoAuthRetry` method sends Bearer token only if `accessToken` is set; safe to call before sign-in

### Retry & Timing Strategy
- Use `RetryUtil` for all retry logic (jitter, backoff, socket timeout)
- Use `TimeUtil`/`TimeWindowUtil` for deadline/window calculations
- Use `HttpUtil` for JSON serialization and request building
- **Never hardcode delays** — use config values via `AppConfig` getter methods

### Error Handling
- **403 Forbidden**: Invalid referer or session issue → restart sign-in flow (`RestartSignInException`)
- **401 Unauthorized**: Check `SessionExpiryValidator.isSessionExpired()` → smart 401 detection
- **429 Rate Limit**: 20s flat shared backoff (`context.record429Backoff`); captcha prefetch during wait
- **503 Service Unavailable**: retry immediately for slot; exponential for payment
- **Socket Timeout**: Retry up to 5x with configurable delay
- **OTP Mismatch**: Try both requestIds; poll Firebase 15s for new code if both fail

### Thread Safety & State
- `RaceContext` is the single source of truth for all cross-thread signals
- `tryClaimPayment()` CAS gate prevents duplicate IVAC payment HTTP calls
- `setSlotReserved()` CAS — all slot loops check `context.isSlotReserved()` as exit condition
- Never modify `AccountConfig`/`AppConfig` after thread start

### Logging & Debugging
- Use `ConsoleLogger.log(phone, message, level)` for per-account logs
- Levels: `AUTH`, `OK`, `WARN`, `WAIT`, `RETRY`, `ERROR`, `FAIL`, `DONE`, `RACE`, `SLOT`, `PAYMENT`, `POLLING`
- Compact format: `[TS] METHOD URL -> STATUS (Xms)` in api-debug logs
- Clear logs before test: `rm -f logs/*-api-debug.log`

## OTP & Firebase Logic

### Firebase Setup
- One Firebase database per bot instance (URL in config)
- OTP stored under `/otps/{phone}.json` (phone-only key, no email)
- Response format: `{"code":"123456","expiresAt":"timestamp_or_seconds"}`
- No OTP = Firebase returns literal `null` — parse as `JsonElement`, check `isJsonNull()` before casting

### Pre-OTP Verification Flow (Per Cycle)
1. **Send OTP**: POST `/forgot-password/sendOtp` via primary `IvacHttpClient` (no auth) → returns `requestId`
2. **Pre-fetch**: Poll Firebase every `otpIntervalDelayMs` until OTP arrives (max `otpTimeoutMs`)
3. **Store**: `RaceContext.setCurrentOtp(otp)` and `setCurrentRequestId(requestId)`
4. Sign-in happens after this — `signinRequestId` also stored in `RaceContext`
5. **Race threads** use pre-fetched OTP immediately; skip Firebase round-trip

### OTP Verification in Race Threads
1. Thread reads `context.getCurrentOtp()` — already in hand from pre-fetch
2. Tries `verifyOtp(currentRequestId, otp)` — forgot-password requestId
3. On mismatch: tries `verifyOtp(signinRequestId, otp)` — sign-in requestId
4. Both mismatch: polls Firebase 15s → retries both requestIds
5. `OtpVerifyResult.Status.ALREADY_VERIFIED` (404) → treat as success
6. **Limit Error**: message contains "limit" → wait 5 min before retry

## Sign-In Strategy

- Probes from **T-100ms** (100ms before window open)
- **Aggressive phase**: retry every 50ms for 2s after window open
- **Normal phase**: retry every `signInRetryDelayMs` (from config) after 2s
- Captcha invalid (`CaptchaInvalidException`) → fetch fresh token, retry once
- Fatal auth error (`AuthenticationFatalException`) → stop worker permanently

## Critical Code Rules

1. **No `Referer` header** — API returns `403 {"error":"invalid_referer"}`. Only send `Content-Type: application/json`
2. **Pre-OTP before sign-in** — sendOtp and Firebase poll must complete before signing in
3. **OTP via forgot-password only** — always `otpChannel="PHONE"`, POST via primary client
4. **Firebase `null` parsing** — parse as `JsonElement`, check `isJsonNull()` before casting
5. **Always `mvn clean`** — stale `.class` files cause phantom bugs
6. **Cloudflare rate-limiting** — Use appropriate retry delays; rapid retries trigger 429/drops
7. **OTP limit** — "limit" in message → wait 5 min before retrying
8. **Never `mvn package` as root** — use portal "Rebuild JAR" button for VPS JAR builds

## Slot Reservation Logic

### Setup
1. Each slot task waits on `context.awaitOtpVerifiedUntil(raceStartedAtMs + slotProbeDelayMs)` — wakes early if OTP verifies, or fires speculatively at the deadline. `slotProbeDelayMs=0` → immediate.
2. Slot task fetches captcha via shared `CaptchaService` → portal solve → `turnstile_encrypted` token
3. Task calls `SlotReservationServiceImpl.probeSlot(context, null, label)` — no inner OTP-verified gate; loop handles speculative 401s

### Slot Reservation Retry (inside `probeSlot` loop)
- **503**: retry immediately (window not yet open)
- **401 (OTP not verified)**: speculative — sleep 500ms (`interruptibleSleep` wakes when OTP verifies), retry
- **401 (OTP verified)**: smart server-time comparison → `RestartSignInException` if session expired
- **400 captcha rejected**: fetch fresh captcha, retry
- **400 already paid**: skip to payment (`context.setSlotReserved()`)
- **403**: check session expiry → restart or sleep 20s
- **429**: `context.record429Backoff(20_000)` — all slot tasks share 20s backoff; async captcha prefetch during wait
- **200 + reservationId**: `context.setSlotReserved()` (CAS) → caller tries `tryClaimPayment()`
- **FULL**: async captcha prefetch; adaptive delay if server was slow
- **NOT_OPEN / SLOT_NOT_PREPARED**: sleep `slotRetryDelayMs`, retry
- Slot read timeout: `SLOT_REQUEST_TIMEOUT_MS = 90_000` (90s)

## Payment Logic

### Payment Initiation
- Endpoint: `POST /payment/{gateway}/initiate` — `gateway` is `dg-epay` (default; `PAYMENT_PATHS` has only `/payment/dg-epay/initiate`)
- Body: `{"appointmentId": "<id>"}` — `appointmentId` comes from booking config; if missing, `ensureAppointmentId()` fetches it from `GET /appointment/get-booking-config`
- **Captcha (June 2026)**: IVAC now requires a **raw Cloudflare Turnstile token** in the **`x-token` header**. The frontend bundle gets it straight from the Turnstile widget (no `encryptText`), so the bot requests portal captcha **type `raw`** (untransformed) via `PortalCaptchaClient.requestCaptcha("raw", phone)` and sends it as `x-token`. NOT the encrypted sign-in/slot token.
- Auth: `Authorization: Bearer <JWT>` (sent automatically by `postRawNoAuthRetry` when `accessToken` is set)
- **Tick-based firing**: `fireOneTick` fetches **one fresh raw token per tick** (single-use, shared across all shots in that tick — one wins); retries with a fresh token each tick. `postRawNoAuthRetry(path, body, timeoutMs, Map.of("x-token", token))`.
- On success (200/201): extract gateway URL via `extractGatewayUrl` (`webview_url` for dg-epay; also handles `redirectGatewayURL`/`GatewayPageURL`), post result to portal (`/api/payment-links`) async via `IPMS_WEB_CLIENT`

### Payment Retry Strategy
- **429**: flat backoff `PAYMENT_429_BASE_BACKOFF_MS = 20_000`; retry-after > 1800s → `FATAL_RATE_LIMIT` (`AuthenticationFatalException`, stop account)
- **401**: retry each tick until `PAYMENT_TTL_GUARD_MS = 900_000` (15 min) elapsed or session expired → then `RestartSignInException`
- **400 CONFIG_NOT_TODAY**: fatal → `ConfigNotTodayException`
- **404 "Appointment not found."**: `RestartSignInException` (restart)
- **Captcha rejected / other transient**: RETRY — next tick fetches a fresh raw `x-token`

## Constants (Reference)

| Constant | Value | Purpose |
|----------|-------|---------|
| `SLOT_REQUEST_TIMEOUT_MS` | 90,000 | Read timeout for slot probe |
| `RESERVATION_TTL_MS` | 270,000 | Max time to hold a reserved slot before payment |
| *(captcha shelf life)* | portal | **NOT a constant** — `settings.captcha_shelf_life_ms` → `/api/config` `captchaShelfLifeMs` → `AppConfig.getCaptchaShelfLifeMs()` (falls back to 20s). The old `Constants.TURNSTILE_SHELF_LIFE_MS` duplicate was removed in `blitz_v_8.1`; this table used to claim it was 270,000 when it was really 20,000 |
| `OTP_VERIFY_IO_RETRY_DELAY_MS` | 1,000 | Delay between OTP verify IO retry batches |
| `MAX_CONSECUTIVE_401S` | 3 | Trigger `RestartSignInException` after 3 × 401 |
| `PAYMENT_429_BASE_BACKOFF_MS` | 20,000 | Base payment 429 backoff |
| `CAPTCHA_400_RETRY_DELAY_MS` | 1,000 | Delay on captcha 400 before retry |
| `RACE_TIMEOUT_MS` | 300,000 | Max race duration (5 min) |

## Testing Strategy

### Unit Tests
- **RetryUtilTest** (16 tests): Jitter bounds, backoff sequence, signin delays, socket timeout
- **HttpUtilTest** (20 tests): JSON serialization, request building, response validation
- **TimeUtilTest** (27 tests): Time conversions, deadline/window calculations
- Run: `JAVA_HOME=/usr/lib/jvm/jdk-26 mvn test -Dtest=RetryUtilTest,HttpUtilTest,TimeUtilTest`

### Pre-Deployment Validation
- Compile cleanly: `JAVA_HOME=/usr/lib/jvm/jdk-26 mvn clean compile`
- All 63 tests pass: `JAVA_HOME=/usr/lib/jvm/jdk-26 mvn test -Dtest=RetryUtilTest,HttpUtilTest,TimeUtilTest`

### Known Broken Tests (Pre-existing — ignore)
`CaptchaProxyTest`, `BurstOrchestratorTest`, `SlotServiceTest`, `PaymentServiceTest` — reference removed packages.

## Configuration & Deployment

### Environment
- JDK 26 required (Adoptium Temurin, installed at `/usr/lib/jvm/jdk-26`)
- Config fetched from Laravel API: `GET /api/config` (with Bearer slot API key if distributed)
- `bypassIps` in config response → bot creates one additional OkHttpClient per IP for slot calls
- Logs: `logs/{phone}-api-debug.log` (rolling, 50MB per file, 7-day retention)

### Deployment Steps

**Local (portal machine):**
1. Compile: `JAVA_HOME=/usr/lib/jvm/jdk-26 mvn clean compile`
2. Start: `POST /api/bot/start` via portal UI
3. Monitor: portal log viewer or `storage/logs/bot.log`

**VPS / Distributed:**
1. Build JAR: `POST /api/bot/package` via portal "Rebuild JAR" button
2. Install/update VPS: `curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- <SLOT_API_KEY>`
3. Monitor: portal Slot Logs viewer (`/slot-logs/{slot_id}`)

## When to Refactor — Decision Tree

- **Known pattern with known fix** — suggest fix, ask before applying
- **Known pattern but recurring** — ask user if retry strategy needs tuning
- **Unknown status code/response** — show log entry to user, ask what it means
- **New API endpoint** — ask user, update model classes, validate parsing
- **Remote services (config/captcha)** — never change URLs or polling without approval

## Code Style

- **Comments**: Concise 1-2 line English (`// Retry signin with exponential backoff`)
- **Return types**: Always explicit; always explicit parameter types
- **Exceptions**: Custom checked exceptions for app logic; IOException for I/O errors
- **Logging**: Use `ConsoleLogger.log(phone, message, level)` per account; never sysout

## Repository Relationship

- `ipms_java`: Automation bot client (this repo)
- `ipms_web`: Laravel control plane, APIs, admin UI
- Integration: Config via `/api/config`, payment results via `/api/payment-links`, captcha via portal
- GitHub:
  - ipms_java: `https://github.com/smensulaiman/ipms_new.git`
  - ipms_web: `https://github.com/smensulaiman/ipms_web.git`
1. Bot calls `PortalCaptchaClient.requestCaptcha(type)` → `POST /api/captcha/request` (`Authorization: Bearer <CAPTCHA_API_TOKEN>`, falling back to the slot `api_key`)
`PortalCaptchaClient` — OkHttp3, 250ms poll, 90s timeout. Sends `Authorization: Bearer <CAPTCHA_API_TOKEN>` when the environment provides one, otherwise the slot `api_key` it already holds; the portal refuses anonymous callers and unknown keys with 401.
