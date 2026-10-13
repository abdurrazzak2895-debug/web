<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.12

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.
</laravel-boost-guidelines>

<project-context>

# IPMS Web — Project Context

This section contains project-specific knowledge for the IVAC booking automation system. Read this carefully before making changes. Deeper, change-tracked detail lives in the `.claude/memory/` files referenced inline (indexed by `.claude/memory/MEMORY.md`) — consult them for captcha rotations, IVAC infra recon, and incident history.

Current bot version: **`blitz_v_7.4`** (`ipms_java/.../BotVersion.java`). Bump it on every Java behaviour change.

---

## Project Overview

**IPMS Web** is a Laravel 12 (+ Inertia v2 + Vue 3 + Tailwind v4) portal that manages IVAC (Indian Visa Application Centre) appointment booking automation.

- **Laravel portal** (`/var/www/html/ipms_web`) — manages accounts, agent slots, settings, and distributes work to VPS bot workers
- **Java bot** (`ipms_java/` inside the same repo) — automates the actual visa appointment booking via IVAC's API (JDK 26 Temurin at `/usr/lib/jvm/jdk-26`)
- **DB**: `ipms_db` (production), `ipms_test` (tests) — MySQL only, no SQLite driver installed
- **phpunit.xml** must use `DB_CONNECTION=mysql` and `DB_DATABASE=ipms_test`
- **Web server**: Apache2 + PHP-FPM (php8.4), served via Laravel Herd at `https://ipms.senda.fit`
- **Portal system binaries** (not installed by any script in this repo — `public/install.sh` and `public/captcha-install.sh` provision bot workers and captcha nodes, NOT the portal): **`ghostscript`** (`apt-get install ghostscript`) for `App\Services\Pdf\PdfOptimizer`, plus `node`, `python3` and `mvn`. **Install ghostscript on every portal host.** `PdfOptimizer` treats a missing/failing `gs` as "keep the original" — a deliberately non-fatal fallback with no UI symptom — so without it applicant PDFs silently stay ~4x larger and the bot uploads them that way to IVAC on the booking critical path. The only signal is repeated `PdfOptimizer: ghostscript failed` WARNINGs in `storage/logs/laravel.log`. This is exactly how it went unnoticed for 12 days after the July 2026 server migration; recovery is `apt-get install ghostscript` then `sudo -u www-data php artisan pdfs:optimize`.

---

## Multi-Bot Distributed Architecture (April 2026)

### Agent Slots (`agent_slots` table)
Each VPS worker is an **agent slot** with a unique API key. The portal never SSH-in to workers — workers pull commands via heartbeat polling.

- `AgentSlot` model: `name`, `api_key`, `status` (online/offline), `worker_state` (idle/running), `pending_command`, `last_heartbeat_at`
- `Account` has `agent_slot_id` FK — each account is assigned to one slot
- `AgentSlot::publishCommand($cmd)` sets `pending_command` + publishes to Redis
- `AgentSlot::clearPendingCommand()` nulls `pending_command` after bot consumes it

### Bot Lifecycle (distributed mode)
1. VPS bot starts → checks for `SLOT_API_KEY` as `args[0]` → enters distributed mode
2. Heartbeat loop: POST `/api/slots/heartbeat` with Bearer token every 5s (idle) / 30s (running)
3. Heartbeat response includes `pending_command` — consumed atomically (cleared on read)
4. Commands: `start` → `startBookingAsync()`, `stop` → `stopCurrentBooking()`, `restart` → stop + start
5. Config fetch: GET `/api/config` with Bearer token → returns only accounts assigned to that slot

### VPS Install / Update
- Static install script: `public/install.sh` — served at `https://ipms.senda.fit/install.sh`
- One-liner: `curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- <SLOT_API_KEY>`
- Downloads JAR from `GET /api/bot/jar` (Bearer auth), installs systemd service `ipms-bot`
- systemd `ExecStart`: `java -jar /opt/ipms-bot/ivac-booking.jar <SLOT_API_KEY>` — **no .env file**
- Re-run same command to update after rebuilding JAR on portal

### JAR Build & Distribution
- Portal builds fat JAR: `POST /api/bot/package` → `mvn clean package -Dmaven.test.skip=true`
- JAR served at: `GET /api/bot/jar` — Bearer auth via any slot API key
- `www-data` runs the build; sudoers rule at `/etc/sudoers.d/ipms-bot-build` allows `sudo chown` on `ipms_java/`
- **NEVER** run `mvn package` manually as root — leaves root-owned files that break portal builds
- JAR status: `GET /api/bot/jar-status`

Both run Java 26 (OpenJDK Temurin), systemd service `ipms-bot`, polling `https://ipms.senda.fit`.
**IMPORTANT**: FAT JAR (compiled with JDK 26, class file version 70.0) requires JDK 26+ to run. Legacy Java versions will fail with `UnsupportedClassVersionError`.

### Java Distributed Mode — Key Classes
- `AppStartup.run(args)`: `args[0]` = SLOT_API_KEY; sets `System.setProperty("slot.api.key", ...)`
- `PortalClient`: heartbeat POST, returns `pending_command` string or null
- `Constants.PORTAL_URL = "https://ipms.senda.fit"` — hardcoded, no .env needed
- `ConfigUrlResolver`: if `slot.api.key` system property set → uses `Constants.PORTAL_URL + "/api/config"`
- `ConfigLoader`: reads `System.getProperty("slot.api.key")` for Bearer auth on config fetch

### Laravel Distributed Mode — Key Files
- `app/Http/Controllers/Api/AgentSlotController.php` — heartbeat clears `pending_command` after reading
- `app/Http/Controllers/Api/PublicConfigController.php` — filters accounts by `agent_slot_id` when Bearer token matches a slot
- `app/Http/Controllers/Api/BotController.php` — `package()`, `downloadJar()`, `jarStatus()`
- `app/Services/BotControl/ProcessBotController.php` — `package()` method builds fat JAR
- `routes/api.php` — `GET /api/bot/jar` outside auth (Bearer slot auth only); heartbeat/command routes outside auth

---

## Account Creation — Duplicate Phone Handling

If a duplicate phone exists on `store()`:
- Status is `running` → 422 "An account with this phone number is currently running."
- Status is anything else → delete old account, insert new one

---

## IVAC Origin / Cloudflare (CF bypass removed April 2026)

The bot reaches `api.ivacbd.com` **through Cloudflare** — there is no origin-IP bypass in production.

- **`bypass_ips` table is empty**; `AgentSlot.bypass_ip_id` and the Java bypass-client paths are legacy/unused. All IVAC calls go through the normal CF-fronted hostname.
- **No stable origin IP exists to find.** `api.ivacbd.com` is Cloudflare → AWS ALB (`awselb/2.0`) → **EKS** ingress → private-subnet app pods. ALB public IPs are shared and recycled across AWS tenants within hours-days, so any stored IP goes stale. The website tier (`ivacbd.com`) has a separate Bulgaria/Telehouse Apache origin that 404s the `/iams/api/v1` path. Full topology + recon methods in `kb_ivac_origin_topology.md`.
- **The 429s the bot hits are app-level per-account quotas, NOT CF/edge limits** — bodies are IVAC JSON (`"You can log in after X minute(s)…"`, reserveSlot "please wait", webfile block). An origin IP would not reduce them; the real fix is parsing the server-stated wait instead of the flat 20s backoff.
- `BypassIp` model, `BypassIps/Index.vue`, `BypassIpScanner`, and the `censys_api_*` settings are retained **only for origin/ELB-node recon** (Censys cert-search `*.ivacbd.com`), not for live traffic.

---

## DB Bot Logs (`db_bot_logs` table)

VPS workers ship API call logs in real-time to the portal.

- `BotLog` model: `agent_slot_id`, `account_phone`, `label`, `method`, `url`, `status_code`, `duration_ms`, `request_body`, `response_body`, `error_type`, `error_message`, `logged_at`
- Ingest: `POST /api/bot-logs/ingest` (Bearer slot auth, outside Laravel auth middleware)
- Java side: `PortalLogShipper` → `PortalLogAppender` (Logback appender)
- Console logs (`method=LOG`) shipped via `PortalLogShipper.enqueueConsole()`
- API call logs shipped via `PortalLogShipper.enqueueApiLog()` with request/response bodies

**Slot Logs Viewer** (`GET /slot-logs/{slot}`, `resources/js/pages/SlotLogs/Index.vue`) — Console tab (all LOG entries chronological) + per-phone tabs (API calls grouped by `account_phone`, expandable with timing + request/response bodies). A cross-slot **`/log-analysis`** page reads `GET /api/db-bot-logs` (`DbBotLogsController`) with a "Purge Noise" button.

- **Multipart upload bodies are not stored** (Jul 2026): `ApiLogInterceptor` logs `(multipart/form-data upload, N bytes)` instead of raw PDF bytes; `DbBotLogsController::stripMultipartBody` strips legacy rows so `/log-analysis` stays fast.

---

## Settings Table

Single-row columnar table. `Setting::instance()` returns the singleton row. `default_timeout_ms` and the Firebase OTP URL columns are **removed** — OTP is now portal-ingested (see OTP Strategy).

**Current fillable** (`app/Models/Setting.php` is the source of truth): `base_url`, `max_retries`, `captcha_fetch_seconds_before_window`, `sign_in_retry_delay_ms`, `otp_interval_delay_ms`, `otp_timeout_ms`, `otp_verify_retry_delay_ms`, `reserve_slot_retry_delay_ms`, `initiate_payment_retry_delay_ms`, `rate_limit_safe_seconds`, `window_start_time`, `window_end_time`, `reserve_slot_id`, `forgot_password_lead_seconds`, `captcha_site_key`, `captcha_page_url`, `recaptcha_site_key`, `recaptcha_page_url`, `use_java_captcha_generator`, `captcha_generator_interval_ms`, `captcha_generator_max_tokens`, `captcha_bot_secret`, `captcha_shelf_life_ms`, `captcha_daily_limit_per_account`, `captcha_bd_proxy_url`, `ivac_endpoints` (JSON), `lightnode_*` (VPS manager), `latest_jar_version`, `censys_api_id`, `censys_api_secret`.

- **`ivac_endpoints`** (July 2026, JSON `array` cast): the bundle-extracted IVAC endpoint paths + the two rotating request headers, so an IVAC redeploy that rotates a path/header is adopted via `/api/config` with **no Java edit or JAR rebuild** (see Dynamic IVAC Endpoints). Keys: `signin`, `sendOtp`, `verifyOtp`, `uploadFile`, `bookingConfig`, `getBookingConfig`, `reserveSlot`/`payment` (templates with `{reserveSlotId}`/`{paymentConfigId}` placeholders — the UUIDs stay synced separately), `signinNavState` (x-sec-navigation-state), `uploadRuntimeState` (x-sec-runtime-state). `CaptchaAlgorithmService::syncEndpoints()` keeps it in sync from the bundle (well-formed-only merge, last-known-good on failure); exported as `endpoints`.

- **`reserve_slot_id`** (July 2026, default `ccd3dd63-e781-48ba-a48d-c65eaa4fc663`): the fixed slot ID IVAC bakes into the reserve-slot URL (`POST /slots/{reserve_slot_id}/reserve-slot`). It is a **deployment-scoped constant in IVAC's frontend bundle**, NOT the account's `appointmentId`. IVAC rotates it on redeploy; `CaptchaAlgorithmService::analyze()` auto-syncs it from the downloaded bundle. Exported as `reserveSlotId`; Java reads `AppConfig.getReserveSlotId()`.
- **`captcha_bd_proxy_url`**: the BD (cloudscraper) proxy used by the Algorithm Monitor to fetch the IVAC bundle and by the api-tester as an exit-IP fallback. Persisted from the monitor UI.

Routes: `GET /api/settings` + `POST /api/settings`

---

## Config Export

**`GET /api/config`** — slot-aware: Bearer token → filters to slot's accounts only. `PublicConfigController` is the bot's real endpoint; `ConfigExportService` (`/api/config/export`) is the legacy mirror — keep both in sync. HTTP timeouts are fixed in Java at 180s (no `defaultTimeoutMs`). `slotProbeDelayMs` is **removed** — the race is tick-based and slots fire on `otpVerifyStarted` (see Bot Race Architecture).

Per-account fields: `phone`, `email`, `password`, `signinBaseUrl`, per-phase tick config (`signinTickShots`/`signinTickIntervalMs`, `otpTickShots`/…, `slotTickShots`/…, `paymentTickShots`/…), `lanes[]`, `appointmentDates[]`, and — from the JWT/OTP + PDF gates — `isOtpVerified`, `signinRequestId`, `signinServerTimeMs`, `pdfUploaded`, `bookingConfigured`, `bookingMission`, `bookingIvacCenter` (never the PDF base64 — kept lean).

Global fields: `maxRetries`, `captchaFetchSecondsBeforeWindow`, `signInRetryDelayMs`, `otpIntervalDelayMs`, `otpTimeoutMs`, `reserveSlotRetryDelayMs`, `initiatePaymentRetryDelayMs`, `slot429BackoffMs`, `windowStartTime`, `windowEndTime`, `reserveSlotId`, `paymentConfigId`, `reserveRequestMeta`, `endpoints` (bundle-extracted IVAC paths + headers; see Dynamic IVAC Endpoints)

### Appointment Dates (July 2026)

- Per-account `accounts.appointment_dates` (JSON array). On `/accounts` the user enters a **From date + To date** range; the Vue `expandDateRange()` expands it to every date in between (inclusive, UTC-safe, capped 366) and stores the full array.
- Config exports `appointmentDates: []` per account.
- **Reserve body sends ONE date as a STRING, not the array.** IVAC returns `{code:2001, message:invalid.json}` (400) if `appointmentDate` is a JSON array. `AccountConfig.nextAppointmentDate()` round-robins the list (thread-safe `AtomicInteger` cursor, empty → `""`); reserve body is `{"c": <encrypted captcha>, "appointmentDate": "2026-07-15"}` — a different date each attempt.
- **IVAC's own available dates take priority (Jul 2026, `blitz_v_5.2`).** `AccountSetupService` fires `GET /appointment/get-booking-config` on a concurrent virtual thread at setup start (re-fetched after booking-config posts) and captures the returned `appointmentDate[]` into `AccountConfig.ivacAppointmentDates`. `nextAppointmentDate()` rotates **those** when present (they are the dates the server will actually accept), falling back to the portal from/to range only when IVAC returns nothing. The same call still captures `appointmentId` for payment.
- Bot sign-in endpoint is `/auth/v23-sign-in` (`SigninServiceImpl`); body `{phone,password,c}` (no email).

---

## PDF Upload & Booking Config (gates slot reserve — July 2026)

IVAC's `POST /slots/{reserveSlotId}/reserve-slot` fails unless, per account: (1) all applicant PDFs are uploaded via `POST /file/upload_file_v23` and (2) `POST /appointment/appointment-booking-config` has run. The bot does both **once per account**, inline after OTP verify and before the slot race. See `project_pdf_upload_booking_config.md`.

- **Portal**: applicant PDFs live in a dedicated **`account_pdfs`** table (`account_id` FK cascade, `name`, `base64` longText, `is_primary`) — split out of the old `accounts.pdfs` JSON column (Jul 2026) so the heavy base64 never loads on account list/config queries. `Account::pdfs()` HasMany; `pdfs_count` accessor prefers a `withCount('pdfs')` value (used by `index()`) and falls back to a count query. `AccountService::syncPdfs()` replaces the child rows in a transaction on store/update. Other `accounts` columns: `pdf_uploaded`, `booking_configured`, `booking_city`. Exactly one PDF must be primary. Editing pdfs (content/primary/order) or city resets the matching flag so the bot re-runs setup. `App\Support\IvacBookingCities` maps the 5 cities → `{mission, ivacCenter}`. Slot-auth endpoints (`AccountBotSetupController`): `GET /api/accounts/{account}/pdfs` (delivers base64, 403 unless slot owns account), `POST /api/accounts/setup-state` (writes flags back so restarts skip). `AccountController::show` attaches the base64 payload on demand for the edit modal.
- **Ordering — setup MUST run after OTP verify.** Both endpoints require an OTP-verified JWT (they 401 on the pre-OTP sign-in JWT; OTP verify does not mint a new token). `AccountWorker.launchSetupTask()` runs setup on a background virtual thread that `awaitOtpVerifiedUntil(jwtDeadline)`; a `RaceContext` setup gate holds all slot reserves until setup completes (only when required — later windows skip it). Restart-resume launches setup immediately on a still-valid OTP-verified JWT, no re-login.
- **Upload details** (Java): the **primary** applicant PDF is uploaded **first and awaited**, THEN the secondaries upload in parallel — IVAC attaches secondaries to the primary's record and 404s them with `"Primary application not found."` if they race ahead (fixed Jul 2026, `blitz_v_5.1`; `AccountSetupService.findPrimary` + primary-first sequencing). Each PDF fetches its own **raw** captcha (`x-token` header) via `IvacHttpClient.postMultipartFile()`; a process-wide `UPLOADED_CACHE` keyed by `accountId:sha256(base64)` makes retries re-upload only failed PDFs. `409 "File already exists."` is treated as success. Multipart headers are Authorization + `x-token` only (**no** `Content-Type: application/json`, **no** `X-Device-ID` — see API Tester note).

---

## Java Bot — Build & Run

Two modes depending on `args[0]`:

| Mode | Condition | Behaviour |
|------|-----------|-----------|
| **Distributed** | `args[0]` = SLOT_API_KEY | Poll portal heartbeat, wait for `start` command |
| **Local** | No args, no SLOT_API_KEY | Start booking immediately (portal machine) |

- **Compile only** (local dev): `mvn clean compile`
- **Fat JAR** (VPS distribution): built by portal via `POST /api/bot/package`; NEVER build manually as root
- **PID file**: `storage/app/bot.pid`; **Log**: `storage/logs/bot.log`; **Config**: `config/bot.php`
- **Logs dir**: `ipms_java/logs/` — `ivac-booking.log`, `api-debug.log`

### Java HTTP Clients — which client serves which path

**The IVAC path is OkHttp3, NOT `java.net.http.HttpClient`.** A April 2026 note here used to claim the opposite ("migrated to JDK HttpClient for HTTP/3"); that migration is not in the tree and the claim misled work more than once — corrected Jul 29 2026 against `IvacHttpClient.java`.

| Path | Client | Notes |
|------|--------|-------|
| **All IVAC API calls** (`IvacHttpClient`) | **OkHttp3** (`import okhttp3.*`, ~13 files) | No `.protocols()` call → **HTTP/2 via ALPN** (OkHttp has no HTTP/3). `SHARED_POOL = ConnectionPool(600, 15 min)` |
| Portal heartbeat (`PortalClient`), portal captcha (`PortalCaptchaClient`) | JDK `java.net.http` | The only two `java.net.http` users in the bot |
| Portal config fetch (`ConfigLoader`), time sync (`TimeSync`) | OkHttp3 | Separate short-lived clients, not `SHARED_POOL` |

- `pom.xml`: `maven.compiler.source/target = 26`; `com.squareup.okhttp3:okhttp` is a first-class dependency, shaded into the fat JAR.
- OkHttp is **load-bearing for per-account IPv6 source binding** — `BoundSocketFactory` + `Ipv6OnlyDns` plug into `OkHttpClient.Builder` (`SocketFactory`/`Dns`). JDK HttpClient only got immutable per-client `localAddress()` in JDK 19, which would not support this design.
- Protocol version is still captured and logged: `ApiLogInterceptor` reads `response.protocol()` → `ApiLogger` → `PortalLogShipper` → `bot_logs.protocol_version`.

**Cancellation** (OkHttp `Dispatcher`, one per `IvacHttpClient`):
- `cancelInFlightCalls()` — cancels every in-flight call on this client.
- `cancelCallsForPath(substring)` — cancels only calls whose URL path matches, so a slot win can abort pending OTP verifies without killing the winning reserve or the payment call.

**Utility helpers that actually exist**: `ConsoleLogger.retry(phone, reason, delayMs)` and `ConsoleLogger.wait(phone, delayMs)`; `HttpUtil.parseRetryAfterSeconds(body)`. There is **no** `ConsoleLogger.httpError`/`attempt` and **no** `HttpUtil.extractServerTimeMs()` — OTP server-time parsing lives in `OtpServiceImpl.parseServerTimeMs`.

**VPS Installation (JDK 26)**

Updated `public/install.sh` to download & install JDK 26 from Adoptium:
- URL: `https://github.com/adoptium/temurin26-binaries/.../OpenJDK26U-jdk_x64_linux_hotspot_26_35.tar.gz`
- Install path: `/usr/lib/jvm/jdk-26`
- Systemd service: Uses explicit `JAVA_HOME=/usr/lib/jvm/jdk-26/bin/java`

Bot control endpoints (portal machine / local mode):

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/bot/status` | GET | Check if running |
| `/api/bot/start` | POST | Start bot locally |
| `/api/bot/stop` | POST | Graceful stop (SIGTERM) |
| `/api/bot/kill` | POST | Force kill (SIGKILL) |
| `/api/bot/compile` | POST | `mvn clean compile` |
| `/api/bot/package` | POST | Build fat JAR for VPS |
| `/api/bot/jar` | GET | Download JAR (Bearer slot auth) |
| `/api/bot/logs?lines=N` | GET | Last N log lines |

---

## IVAC API

`BASE_URL = https://api.ivacbd.com/iams/api/v1` — all calls; IODP and CF bypass removed April 2026.

The Java bot calls **8 IVAC endpoints**. Versioned paths (`v23-sign-in`/`upload_file_v23`) and the two `x-sec-*` headers are baked into IVAC's frontend bundle and **rotate on redeploy** — but they are now **bundle-extracted + config-delivered** (see Dynamic IVAC Endpoints), so a rotation is picked up via `/api/config` with no Java edit. The Java literals below are the **compiled-in fallback defaults** used when config omits a key.

| Endpoint | Method | Notes |
|----------|--------|-------|
| `/auth/v23-sign-in` | POST | mints JWT (899s life); body `{phone,password,c}` (NO email; `c` = encrypted turnstile); header `x-sec-navigation-state` (`appConfig.getSigninNavState()`, default `Constants.SIGNIN_NAVIGATION_STATE`) |
| `/forgot-password/sendOtp` | POST | fires OTP; returns `requestId`. Fired pre-window at T-45s (`appConfig.getSendOtpPath()`) |
| `/otp/verifySigninOtp` | POST | field is `code`/`otp` not `otp`-only; 404 "not found" = already verified (idempotent) (`appConfig.getVerifyOtpPath()`) |
| `/file/upload_file_v23` | POST | multipart; PDF setup gate (see PDF Upload); header `x-sec-runtime-state` (`appConfig.getUploadRuntimeState()`, passed into `IvacHttpClient.postMultipartFile`) |
| `/appointment/appointment-booking-config` | POST | booking setup gate; body `{mission, ivacCenter}` (`appConfig.getBookingConfigPath()`) |
| `/appointment/get-booking-config` | GET | fetches `appointmentId` (for payment) + IVAC's own available `appointmentDate[]`; called concurrently at setup and as payment fallback (`appConfig.getGetBookingConfigPath()`) |
| `/slots/{reserveSlotId}/reserve-slot` | POST | body `{c, appointmentDate}` — one date STRING; path from `appConfig.getReserveSlotPathTemplate()` with `{reserveSlotId}` substituted |
| `/payment/{paymentConfigId}/dg-epay/initiate` | POST | only payment endpoint; `paymentConfigId` is a bundle constant (see Bundle-Synced Request Constants); path from `appConfig.getPaymentPathTemplate()` |

### Dynamic IVAC Endpoints (bundle-extracted, zero-rebuild rotation — July 2026)

All 8 endpoint paths + the two rotating headers are extracted from the live bundle and shipped to the bot via `/api/config`, mirroring the existing `reserveSlotId`/`paymentConfigId` pipeline. A redeploy that rotates a path/header no longer needs a Java edit or JAR rebuild. See `project_dynamic_ivac_endpoints.md`.

- **Extract** (`app/Scripts/extract_request_constants.cjs`): emits an `endpoints{}` object. Strategy A (recorded module-scope axios URLs) yields signin/bookingConfig/getBookingConfig/reserveSlot **and the `x-sec-navigation-state` header off the sign-in call**; plain-literal fallbacks cover verifyOtp/uploadFile (and back up the Strategy-A ones so extraction survives an axios-record miss); payment uses the fixed `dg-epay/initiate` template. Every path is well-formedness-gated (starts `/`, carries its stable anchor) before emit; a failing key is omitted → bot keeps its default. **`sendOtp` and `x-sec-runtime-state` are obfuscated component-local concats that are NOT headless-decodable** (the runtime-state value depends on a runtime var `t`), so they are never auto-extracted — they keep their seeded default / manual portal override.
- **Sync** (`CaptchaAlgorithmService::syncEndpoints()`, called from `analyze()` alongside `syncRequestConstants()`, sharing one memoized Node run): well-formed-only merge into `settings.ivac_endpoints`, last-known-good on failure.
- **Deliver**: `PublicConfigController` + `ConfigExportService` emit `endpoints` (keep both in sync).
- **Consume** (Java `AppConfig`): `endpoints` map + typed getters (`getSigninPath()`, …, `getSigninNavState()`, `getUploadRuntimeState()`) each falling back to the compiled-in default when a key is missing/blank. Call sites in `SigninServiceImpl`, `OtpServiceImpl`, `AccountSetupService`, `SlotReservationServiceImpl`, `PaymentServiceImpl` read the getters; `IvacHttpClient.postMultipartFile` takes the runtime-state as a param. `blitz_v_7.4`.
- **Recovery invariant**: a bad/empty extraction is never worse than today — the bot falls back to its compiled-in literals; the portal never writes a malformed value.

### API Tester `/file/upload_file` — header conformance (July 2026)

`ApiTesterController::ivacFileUpload()` sends **only** `Authorization: Bearer` + `x-token` (+ `Expect:`); curl sets the multipart boundary. Multipart fields: `file`, `isPrimary` (+ optional `fileNumber`).

- **Do NOT add `X-Device-ID` or `Accept: application/json`** — the string `X-Device-ID`/`device` appears **zero** times in IVAC's bundle; the site never sends a device header on any call. Sign-in/OTP tolerate extra headers, but the file service is stricter and rejects them with `404 "Appointment not found."` even when the JWT/appointment are valid.
- **General rule**: when an api-tester call 404s/400s but the browser works with the same token, diff our headers against the live bundle — an extra header the site never sends can break stricter IVAC services.

---

## Bot Race Architecture — tick-based

Sign-in, OTP verify, slot, and payment are each **multi-shot, tick-based** (`RaceOrchestrator.buildTickSchedule`). Each phase has per-account `<phase>TickShots` (default 10) + `<phase>TickIntervalMs` (default 1000ms); every tick fires N parallel requests on virtual threads, cycling round-robin. Details in `kb_race_architecture.md` + `kb_authentication.md`.

- **Sign-in**: fires from `windowStart - 100ms`; first success completes the winner and cancels in-flight calls. 401 = fatal (bad creds); 400 = captcha expired (refresh in main thread only); 429 respects the server-stated cooldown.
- **OTP verify** fires as soon as a pair has both `requestId` + `otpCode`. Three lanes (OTP pairs): `sms-fp`, `email-fp` (forgot-password), and `signin` (sign-in response). First `verified:true` wins via CAS.
- **Slot** polls `context.isOtpVerifyStarted()` every 100ms and starts probing the moment an OTP thread is about to call verify (NOT on verified) — so slots race with OTP in-flight. 401 (OTP not yet verified) → RETRY next tick.
- **Payment**: first slot reserve win → CAS claim → one thread fires `POST /payment/{paymentConfigId}/dg-epay/initiate`; extracts `webview_url`/`redirectGatewayURL`/`GatewayPageURL`.
- JWT reuse: sign-in skipped if a stored JWT is valid for >30s. Race timeout 300s.
- (The `IvacHttpClient.bypass(...)` client paths still exist but are unused — `bypass_ips` is empty; traffic goes through Cloudflare.)

---

## Booking Window

- Opens at the time in `window_start_time` (Bangladesh time; typically midnight)
- `forgot-password/sendOtp` fired pre-window at T-45s (`forgot_password_lead_seconds = 45`), dual-channel (SMS + EMAIL) in parallel

---

## OTP Strategy (portal-ingested — Firebase removed)

OTPs no longer come from Firebase. SMS and Gmail are ingested into the portal, and the bot polls the portal for them.

**Ingest**: the Android SMS forwarder (`ipms_sms_android/`, hardcoded to `https://ipms.senda.fit/`) POSTs every SMS to the public `/otp` endpoint (`{phone, msg}`, `OtpIngestController`); Gmail watch feeds the email channel. `OtpMessageParser` extracts the code into `otp_codes`.

**Bot poll**: `PortalOtpClient` → `GET /api/otp/{phone}?channel=sms|email&since={epoch_ms}&consume=1` (replaces the legacy Firebase path). `OtpCode::consumeForPhone` returns the newest unconsumed row matching phone + channel + `fetched_at >= since - 2s`, locked and marked consumed in one transaction. Background pollers (`RaceOrchestrator.backgroundPoll`) fill whichever OTP pair has a `requestId` + `serverTime` but no code yet.

**Dual OTP** (`kb_authentication.md`): three pairs race — `sms-fp`, `email-fp`, `signin`. On SMS two pairs share the channel → FIFO by `serverTime`. OTP expiry ~300s.

**Keep JWT, resend OTP** (`feedback_jwt_resend_otp.md`): a poll timeout / EXPIRED / CODE_MISMATCH triggers a **dual-channel resend** (keeps the JWT). Full sign-in restart happens **only** on JWT expiry (899s). JWT/OTP-verified state persists in `account_sessions` (`is_otp_verified`, `request_id`, `signed_in_server_time`) so a restart fast-paths straight to the slot phase when OTP is already verified — see `project_jwt_otp_session_state.md`.

---

## Captcha Strategy (Centralized — April 2026)

- All captcha is **Cloudflare Turnstile** (proxyless) — reCAPTCHA v2 removed April 2026
- **Centralized solving**: bot workers no longer call providers directly — all solving goes through portal
- Bot calls `POST /api/captcha/request` (open API, no auth) → polls `GET /api/captcha/request/{id}?type=...` every 250ms
- Portal dispatches `SolveCaptchaJob` (Redis queue `captcha`, 8 systemd workers) to CapMonster/2Captcha/CaptchaAI
- **Token types** (June 2026 — TWO distinct algorithms): `turnstile` (sign-in → **LOGIN** algorithm) | `turnstile_encrypted` (slot → **RESERVE** algorithm). Both encrypted by the portal; the raw Turnstile token is never sent to IVAC.
- **Payload key (Java)**: the encrypted token is sent under key **`"c"`** for both sign-in (`SigninRequest.c`) and slot reserve (`SlotReservationServiceImpl` → `Map.of("c", ...)`).
- `captcha_requests` table — stores solve requests with status (pending/processing/ready/failed), auto-purged after 5 min.
- **Generation mode** (`/captcha-control` has TWO start buttons): `App\Support\CaptchaGenerationMode` stores `captcha:generation_mode` in Redis — `all` (every provider row, the original behaviour) or `active` (only `enabled` rows). Both `FillCaptchaPoolCommand` and `SolveCaptchaJob::acquireSlot()` scope their provider query through it, so the choice reaches the filler, the queue workers and the on-demand path. **Defaults to `active`** — an unset key must never mean "spend a disabled paid provider's credit". In `active` mode a provider disabled *after* dispatch stops solving immediately, and `SolveCaptchaJob` fails the request rather than re-queueing (that branch means "slots full" and would otherwise spin every second forever).
- **After changing an enum/job/service the workers execute, restart them** — `optimize:clear` + FPM reload only refreshes the web tier; `ipms-captcha-worker@{1..4}` hold the old code and fail every job (adding `in_house` caused 739 `not a valid backing value` failures while the UI looked healthy).

### Encryption is sidecar-ONLY (the source of truth is the live bundle)

IVAC's bundle ships ~10 versioned encrypt modules and picks one per config `version`. **The version/algorithm rotates on nearly every redeploy**, so we no longer maintain a PHP port as the encryptor. Production encryption runs the **live bundle's own code** via a persistent Node sidecar, driven by `storage/app/captcha/encrypt_meta.json` (per-type `{module,version,skip,enc_len,secret,bundle_hash}`).

- **Sidecar** `app/Scripts/captcha_encrypt_server.cjs` — systemd **`ipms-captcha-encrypt`** (`deploy/ipms-captcha-encrypt.service`, `127.0.0.1:8787`). Keeps the 2 MB bundle loaded (cold ~1s), encrypts in ~0.3ms. `POST /encrypt {type,token}` (secret/skip/encLen optional — falls back to meta), `GET /health`, `POST /reload`, `POST /stage` + `POST /promote {bundle_hash}` (double-buffer, near-instant swap). Live runtime `captcha_live_runtime.cjs` (+ `captcha_dom_stub.cjs`) exposes every frozen `encryptText` module headless.
- **`App\Services\Captcha\CaptchaEncryptionService`** is now a thin wrapper calling ONLY `LiveBundleClient->encrypt('login'|'reserve', $token)` (sidecar). **There is NO php/live_js/auto engine selector and no `captcha_engine` setting** — both were removed June 2026. If the sidecar is down / meta not ready it returns null and the caller fails the request. Used by `CaptchaRequestController::show` + `CaptchaTokenController::storeSolved`.
- **`captcha_transform_seeds` + `CaptchaTokenTransformer.php` are retained for MONITOR display / attribution / audit only** — they do NOT drive encryption. Don't chase re-porting the PHP transform on a rotation; a `login_impl_match:false` on the monitor is cosmetic (stale PHP port) when the sidecar is healthy.
- **Current live** (July 9 2026, bundle `mrd34y1o-KQMVIEeR.js`, raw `f1814fe5`): login AND reserve share ONE config → module `y0` version 5, skip 1 / enc_len 29, identical secret. Recent generations frequently make login==reserve. Both skip the first `SKIP` chars then transform the next `ENCLEN` chars; **skip=1 pulls the Turnstile `.` separator into the window — non-alphabet input chars must pass through unchanged** (a canary bug around this bit twice; see `project_captcha_live_js_engine.md`).

### Algorithm Monitor (`/captcha-algorithm-monitor`)

Detects both algorithms from the live bundle, verifies the sidecar output byte-for-byte against the live module, and self-heals via `encrypt_meta.json`.

- **Python** `app/Scripts/analyze_captcha_algo.py` — fetches the bundle via the BD proxy (cloudscraper) from `https://appointment.ivacbd.com/signin` (NOT `/` — that serves a time-gated notice page in the booking window), resolves each config's version/skip/enc_len/**secret** by running the bundle in Node, runs the live module on a fixed test token, and **atomically writes `encrypt_meta.json` only when BOTH types fully resolve, are well-formed, and are distinct** (else keeps last-known-good and raises needs-attention). Side-effect-free `--attribute <bundle>` mode for tests; content-addressed `analysis_cache/` (unchanged bundle ~205ms). Attribution is **marker-only** (`dateLabel` marks reserve) with an identical-configs fallback for login==reserve — no enc_len guessing.
- **`CaptchaAlgorithmService::analyze()`** compares PHP vs the live outputs, writes a `captcha_algorithm_snapshots` row, auto-applies seeds + reloads/promotes the sidecar on a clean extraction, and `syncReserveSlotId()` from the bundle. Registers the bundle via `CaptchaBundleVersionService` (see below).
- **UI** `resources/js/pages/CaptchaAlgorithm/Index.vue` — Login/Reserve panels (5-cell status grid; the old "Update Needed" cell is gone), Detected Constants, per-type Apply Seed (hidden during a mid-rollout), Bundle Versions panel, snapshot history. Banner classifies failures **amber = mid-rollout** (config picks a version with no module yet) vs **red = structural**.
- Routes: `POST /api/captcha-algorithm/analyze`, `GET .../history`, `POST .../update-seed`, `DELETE .../snapshots[/{id}]`, `GET .../engine` (sidecar health), `POST .../sidecar/reload`, `GET/POST/PATCH/DELETE .../versions[/{id}/activate]`.
- **`captcha-algorithm:auto-refresh`** (scheduled every 5 min, `withoutOverlapping`, **skips the booking window**) does a cheap `--head-only` probe and re-analyzes only on a bundle-asset change.

### Recovery after an IVAC redeploy (captcha 400)

1. Open the monitor and click **Run Analysis** with a BD proxy (or run `php artisan captcha-algorithm:auto-refresh`). A clean run re-extracts meta, activates the bundle atomically, and reloads/promotes the sidecar — no PHP work.
2. If extraction fails (structural), the analyzer keeps last-known-good and alarms; follow the manual sidecar-first recipe in `project_captcha_live_js_engine.md` / `kb_captcha_algorithm_verification.md` (extract the live module, write `encrypt_meta.json` via **Python** — secrets can contain backticks/`}` that break bash defaults — `systemctl restart ipms-captcha-encrypt`, verify `/encrypt` byte-matches).
3. If encryption is byte-correct and it still 400s, it's the **raw Turnstile solve** (expired/invalid solver token or sitekey), not the transform.
- **Bundle versioning** (`captcha_bundle_versions`, content-addressed `storage/app/captcha/bundles/<sha256>.js`): activate any archived bundle to roll back. `CaptchaBundleVersionService::activate()` mirrors bundle+meta atomically and promotes the sidecar. Footgun: an unclean analyze reconciles disk back to the DB-active row — if the true-live bundle was applied out-of-band, that silently downgrades; always `activate()` the just-fetched bundle. After any forced disk change, clear the `captcha:last_bundle_asset` marker.
- Storage perms: `storage/app/captcha` must be `www-data:www-data 775` — a root-owned meta/bundle breaks the web/sidecar path (`feedback_captcha_storage_perms.md`).

---

## Error Handling (approved, do not change)

| Status | Phase | Behavior |
|--------|-------|----------|
| **503** | Slot | Retry immediately (no sleep) |
| **401** | Slot (OTP not verified) | Speculative mode — sleep 500ms, retry; wakes immediately when `otpVerified` fires |
| **401** (OTP verified) | Slot | Smart serverTime comparison → restart if JWT expired |
| **403** | Any | Check token validity → restart if expired, retry if valid |
| **429** | Slot | Flat **20s** hard block all lanes (`backoffSleep` — does NOT wake on OTP verified); captcha prefetch during backoff |
| **SocketTimeout** | OTP | Retry same code immediately (idempotent — 404 = already verified) |
| **SocketTimeout** | Slot | Null captcha, retry after delay |
| **400 "already made payment"** | Slot | Skip to payment phase |
| **CONFIG_NOT_TODAY** | Payment | Fatal — stop worker |
| **401 ×3** | Payment | `RestartSignInException` → full re-auth |

---

## IvacHttpClient

- **OkHttp3**, one `OkHttpClient` per `IvacHttpClient` instance off a shared `baseBuilder`; `shortTimeoutClient` removed March 2026
- **Timeouts are 180s connect/read/write** (not 60s — that figure was stale), all set in `baseBuilder`
- `postRawNoAuthRetry(path, body, int readTimeoutMs)` overrides the read timeout per call via `okHttpClient.newBuilder()` — cheap, since the derived client shares the connection pool and dispatcher
- Factories: `direct(phone)` (+ IPv6 source binding when the pool is enabled), `proxy(proxyUrl, phone)`, and the unused legacy `bypass(bypassIp, phone)`

---

## Routes — Important Notes

- `PUT /api/accounts/bulk-assign` must be registered **before** `Route::apiResource('accounts', ...)` to avoid being swallowed by `PUT /accounts/{account}`
- `GET /api/bot/jar` is outside auth middleware (Bearer slot auth only)
- `POST /api/slots/heartbeat` is outside auth middleware (Bearer slot auth only)

---

## Proxy Table — DataImpulse

- Host: `gw.dataimpulse.com`, provider: `dataimpulse`, behavior: `sticky`
- Ports: 10000–10045 (with gaps at 10006, 10009, 10015, 10032, 10033, 10036, 10042, 10044)
- IDs 1–55: original entries (March 12, 2026)
- IDs 56–93: duplicates deleted (April 1, 2026) — were re-seeded accidentally

---

## Other Portal Systems

- **VPS Manager** (`/vps-manager`): auto-provisions/destroys **LightNode** VPS and SSH-installs the bot. `LightNodeClient` (`x-open-token`, `https://openapi.lightnode.com`), `ProvisionVpsJob`/`DestroyVpsJob` (Redis queue `vps`, systemd `ipms-vps-worker`), `VpsInstance` model (`root_password` encrypted). Create returns a UUID immediately but IP requires polling `GET /instance/detail` until `ecsStatus=STARTED`; SSH via phpseclib. See `project_vps_manager.md`.
- **Reverb WebSocket** (`/gmail` page only, private channel `gmail.<userId>`): systemd `ipms-reverb` on `127.0.0.1:8080`; Apache proxies `/app` → `ws://…:8080` so the browser connects to `wss://ipms.senda.fit/app`. Restart with `systemctl start ipms-reverb`. See `project_reverb_websocket.md`.
- **API Tester** (`/api-tester`, `ApiTesterController`): step-through of the IVAC flow with `AccountSession` JWT persistence per phone. OTP verify payload is `{requestId, phone, code, otpChannel}` (field is `code`, not `otp`). PDF upload sends `x-token` + only `Authorization` (**no `X-Device-ID`/`Accept` — the file service 404s on them**). With no bypass IP it tunnels through the BD proxy. Right sidebar shows Booking Config + File Overview. See `project_api_tester.md`.
- **Scheduler**: driven by a **root** crontab `* * * * * cd /var/www/html/ipms_web && /usr/bin/php8.4 artisan schedule:run`. The `cd` is load-bearing and has silently gone missing before (symptom: stale rows that should purge each minute). Tasks in `routes/console.php`: captcha_tokens/captcha_requests cleanup (every min), bot_logs noise purge (every 5 min), `captcha-algorithm:auto-refresh` (5 min), `bypass-ips:scan-subnets` (daily), `gmail:renew-watch` (daily) + `gmail:sync-fallback` (10 min). See `reference_scheduler.md`.
- **In-House Captcha** (`/in-house-captcha`, super_admin): self-hosted Turnstile solving, run by a **distributed fleet of solver nodes**. Node + headless-Chrome sidecar `app/Scripts/in_house_captcha_solver.cjs` (systemd `ipms-in-house-captcha` on the portal, `ipms-captcha-node` on a worker; loopback `127.0.0.1:8788`). Navigates the REAL `captcha_page_url` but swaps only the document body for a synthetic widget page, so the token binds to the real (site key, hostname) and IVAC is never contacted. **Three load-bearing settings — any one alone yields zero tokens: a writable `HOME` (www-data's `/var/www` is root-owned → crashpad CHECK/SIGTRAP), no automation markers (CF 403s the `HeadlessChrome` UA / `navigator.webdriver`), and NO `page.exposeFunction()` (CF detects the injected binding and renders no iframe at all — 0/2 vs 2/2 in a bisect; the token must stay published on `window` for the poller).** Solve latency is ~93% Cloudflare dwell time (~170ms local setup vs ~2.4s challenge sequence) and a solve costs ~4 CPU-seconds, so **capacity is bought in cores and throughput scales by adding machines**. There is **NO CF per-IP throttle** (matched A/B over 8 source IPv6 scored 90% in both arms) — do not build IP rotation. The synthetic page declares `<link rel="icon" href="data:,">`; without it Chrome's `/favicon.ico` fallback was the one request per solve that actually reached IVAC.
  - **Fleet (Jul 29 2026)**: nodes **pull** work like the Java bot instead of being called, so a solver VPS exposes nothing inbound. `SolveCaptchaJob` marks the request Processing and `LPUSH`es it to `captcha:fleet:queue` (returns in ms — it no longer blocks a queue worker inside a ~5s solve, which was the only reason `ipms-captcha-worker@{1..16}` existed). A node leases via `POST /api/captcha-nodes/lease`, solves locally, and returns the **raw** token via `POST /api/captcha-nodes/result`. Login/reserve encryption stays on the portal (`CaptchaRequestController::show`) — no bundle state ever ships to a node.
  - **Registry** `captcha_nodes` / `CaptchaNode` (shaped like `AgentSlot`: 64-char key, 90s staleness, `pending_command` consumed on read). `App\Services\Captcha\CaptchaNodeFleet` owns capacity/queue/lease/complete/reap. **In-house is preferred over the paid providers** (`acquireSlot()` partitions in-house first; `FillCaptchaPoolCommand` fills the fleet budget before round-robining vendors); with zero nodes online capacity is 0, in-house is skipped, and vendors take over.
  - **`captcha:in_house_slots` is GONE** — the fleet's ceiling is `CaptchaNodeFleet::queueLimit()` = aggregate reported concurrency × `captcha:fleet_queue_factor` (1.5). `syncProviderSlots()` **no longer skips in-house** (a fleet solve IS Processing for its whole lease, so the row count is now the truth; the old skip existed only because a local synchronous solve never was).
  - **Install** `curl -fsSL https://ipms.senda.fit/captcha-install.sh | sudo bash -s -- <NODE_KEY> [--profile shared]` (`public/captcha-install.sh`): Chrome libs (`libasound2t64` with a `libasound2` fallback), pinned Node 22 LTS, puppeteer+Chrome into `/opt/ipms-captcha`, solver from `GET /api/captcha-nodes/script`, then sizes concurrency/CPUQuota from `nproc` (clamped by cgroup) — dedicated = cores @ 90%, shared = cores/2 @ 40% with `CPUWeight=50` so `ipms-bot`'s 200 wins. **The portal host is a node too**, keyed by a drop-in at `/etc/systemd/system/ipms-in-house-captcha.service.d/node.conf`; it must NOT set `CAPTCHA_NODE_SELF_UPDATE` (its checkout is the source of truth for the script).
  - **Script version** = sha256 prefix of the solver file, computed portal-side and by each node self-hashing `__filename` — no constant to bump. Heartbeat **commands**: `update`, `pause`/`resume`, `set_concurrency:<n>` (live resize), `restart_browsers`. **Recovery**: 40s lease TTL, deliberately inside `timeoutStale()`'s 60s so the reaper requeues onto a healthy node instead of the blanket timeout failing it; retried once then failed.
  - VPS Manager provisions captcha-role boxes (`vps_instances.role`/`captcha_node_id`, `ProvisionCaptchaNodeJob`/`UpdateCaptchaNodeJob`). `CaptchaSolverService` + `PollCaptchaTasksCommand`'s vendor path still throw/skip on this type. `CaptchaProviderController` refuses `in_house` to non-admins, since `/captcha-providers` is open to managers/agents. See `project_in_house_captcha_solver.md` and `deploy/README-in-house-captcha.md`.
- **Header**: persistent, shows current Dhaka (BDT) time + booking window (`window_start_time`/`window_end_time`) on all pages.
- **Worker deletion**: deleting an agent slot in `/bot-control` nulls `agent_slot_id` on all its accounts (cascade unassign).

---

## Key File Paths

**Laravel:**
- `app/Models/Account.php`, `app/Models/AgentSlot.php`, `app/Models/Setting.php`, `app/Models/AccountSession.php` (JWT/OTP-verified state), `app/Models/BotLog.php`
- `app/Http/Controllers/Api/AccountController.php` — store (duplicate phone handling), bulkAssign, PDF/booking validation
- `app/Http/Controllers/Api/AgentSlotController.php` — heartbeat (clears pending_command), sendCommand
- `app/Http/Controllers/Api/PublicConfigController.php` — `/api/config` (slot-filtered; OTP-verified + PDF/booking fields)
- `app/Http/Controllers/Api/BotController.php` — package, downloadJar, jarStatus
- `app/Http/Controllers/Api/BotLogIngestController.php` / `DbBotLogsController.php` — ingest + query/purge DB bot logs
- `app/Services/BotControl/ProcessBotController.php` — package() builds fat JAR
- `public/install.sh` — static VPS install script
- `resources/js/pages/BotControl/Index.vue` — Operations, Account Assignment, VPS Setup tabs
- `resources/js/pages/SlotLogs/Index.vue` — per-slot log viewer
- `app/Models/BypassIp.php` + `resources/js/pages/BypassIps/Index.vue` — origin recon only (pool empty)

**Laravel (captcha):**
- `app/Http/Controllers/Api/CaptchaRequestController.php` — `POST /api/captcha/request`, `GET /api/captcha/request/{id}` (open API); encrypts via the sidecar at poll time
- `app/Jobs/SolveCaptchaJob.php` — Redis queue `captcha`, picks random enabled provider
- `app/Services/CaptchaSolverService.php` — provider HTTP logic for CapMonster/2Captcha/CaptchaAI
- `app/Services/Captcha/CaptchaEncryptionService.php` + `LiveBundleClient.php` — sidecar-only encryptor (no engine selector)
- `app/Scripts/captcha_encrypt_server.cjs` / `captcha_live_runtime.cjs` / `analyze_captcha_algo.py` — sidecar, live runtime, bundle analyzer
- `app/Services/CaptchaAlgorithmService.php` + `CaptchaBundleVersionService.php` — Algorithm Monitor, self-heal, bundle versioning
- `app/Support/CaptchaTokenTransformer.php` + `captcha_transform_seeds` — **monitor/audit only, not the encryptor**
- `resources/js/pages/CaptchaAlgorithm/Index.vue` — monitor UI
- Systemd: `ipms-captcha-worker@{1..8}.service` (8 solve workers), `ipms-captcha-encrypt` (encrypt sidecar)

**Laravel (setup / VPS / OTP):**
- `app/Http/Controllers/Api/AccountBotSetupController.php` — slot-auth `GET /pdfs`, `POST /setup-state`
- `app/Support/IvacBookingCities.php` — city → `{mission, ivacCenter}`
- `app/Http/Controllers/OtpIngestController.php` (`/otp`) + `app/Support/OtpMessageParser.php` + `app/Models/OtpCode.php` (`consumeForPhone`)
- `app/Services/LightNodeClient.php`, `app/Jobs/{Provision,Destroy}VpsJob.php`, `app/Models/VpsInstance.php`

**Java Bot:**
- `AppStartup.java` — distributed vs local mode, command poll loop
- `PortalClient.java` — heartbeat + JWT/OTP-verified state store; `Constants.java` — `PORTAL_URL`
- `config/ConfigUrlResolver.java` / `config/ConfigLoader.java` — slot.api.key → portal URL + Bearer config fetch
- `service/captcha/PortalCaptchaClient.java` — centralized captcha client (polls portal)
- `service/PortalOtpClient.java` — portal OTP poll (replaces Firebase)
- `service/setup/{PortalSetupClient,AccountSetupService}.java` — PDF upload + booking-config gate
- `worker/RaceOrchestrator.java` — tick-based race; `worker/AccountWorker.java` — per-account lifecycle

---

## Pre-existing Test Failures (unrelated to recent work — verify against a clean branch before chasing)

- PHP: `ConfigExportTest` (stale vs service), `BypassSlotParallelShotsTest` (missing column on some branches), `CaptchaAlgorithmGroundTruthTest` "enables disabled captcha providers" (mocked service), `RbacTest` (some pages 404 on clean branch).
- Java: `CaptchaProxyTest`, `BurstOrchestratorTest`, `SlotServiceTest`, `PaymentServiceTest` — reference removed packages.

---

## Code Style

- English-only comments in all code and logs (no Hindi/Urdu)
- Never run `mvn package` manually as root — use portal "Rebuild JAR" button
- `agent_slot` relationship serializes as `agent_slot` (snake_case) in API JSON — Vue must use `account.agent_slot`, not `account.agentSlot`

</project-context>
- Bot calls `POST /api/captcha/request` (Bearer `CAPTCHA_API_TOKEN`) → polls `GET /api/captcha/request/{id}?type=...` every 250ms
- `app/Http/Controllers/Api/CaptchaRequestController.php` — `POST /api/captcha/request`, `GET /api/captcha/request/{id}` (Bearer `CAPTCHA_API_TOKEN`, or an admin session for the in-app testers); encrypts via the sidecar at poll time
