// Persistent in-house Cloudflare Turnstile solver.
//
// Mints real Turnstile tokens locally instead of paying a third-party provider.
// Headless Chrome navigates to the REAL page URL, but the top-level document
// response is swapped for a synthetic page containing only the widget + Cloudflare's
// own api.js. Only the response body is substituted — the URL itself is never
// changed — so location.origin, document.domain and the widget's referrer all still
// report the real hostname. The browser/Cloudflare handshake then happens for real,
// which is what binds the token to (site key, hostname): exactly the pair
// siteverify checks. IVAC uptime and its notice-page state are irrelevant, because
// no request ever reaches IVAC.
//
// Runs as a long-lived process (systemd: ipms-in-house-captcha) for the same reason
// the encrypt sidecar does: launching Chrome costs ~350ms plus a cold profile, but a
// warm browser solves in ~2.5-3s, and warm contexts can solve in parallel.
//
// Solve latency is ~93% Cloudflare's own dwell time: profiling a solve shows ~170ms of
// local setup (context + page + document swap) and ~2.4s waiting on the challenge
// sequence, whose last request lands at a near-constant wall-clock offset regardless of
// how quickly the earlier ones finish. Latency is therefore not meaningfully reducible
// and throughput comes from concurrency alone. Measured knee on a 16-vCPU host:
//
//   concurrency 4  / 1 browser  -> 1.12 solves/s,  96% success, p50 2.8s
//   concurrency 9  / 4 browsers -> 2.08 solves/s,  94% success, p50 4.4s  <- default
//   concurrency 16 / 1 browser  -> 2.04 solves/s,  98% success, p50 6.1s
//   concurrency 16 / 4 browsers -> 3.00 solves/s,  95% success, p50 4.4s  <- efficient shape
//   concurrency 24 / 8 browsers -> 2.32 solves/s,  95% success, p50 6.4s
//
// A solve costs ~4 CPU-seconds, so throughput is roughly cores/4: 16 in flight took ~13.5
// of the host's 16 vCPU.
//
// The shipped configuration is deliberately NOT any of those: it is tuned for a light
// footprint, because this host also runs the Java bot and the solver is idle most of the day.
// The unit runs concurrency 3 over 1 browser at CPUQuota=200%, and the pool is REAPED after
// CAPTCHA_SOLVER_IDLE_MS with nothing in flight. Measured effect:
//
//   idle, pool warm (old)   -> 1842 MB, 61 chrome processes, 712 tasks
//   idle, pool reaped (now) ->   21 MB,  0 chrome processes,  11 tasks
//   warm, solving (now)     ->  172 MB, 11 chrome processes
//
// The cost is one ~350ms cold start on the first solve after an idle window: 3.3s against a
// 2.5s warm solve. Raise CPUQuota before CONCURRENCY, and keep captcha:in_house_slots in step.
//
// More browsers is not monotonic — each carries a full browser-main/gpu/zygote/utility
// process set, so 24 over 8 throughputs worse than 16 over 4. Spreading a FIXED concurrency
// over more browsers does help p50, because one browser serialises its own CDP/IPC dispatch.
//
// Per-attempt success is a flat ~94-95% at every concurrency AND every source IP: it is the
// silent challenge-JS stall, and a fresh context recovers it. There is no Cloudflare per-IP
// throttle — a matched A/B at concurrency 24, once from the default egress address and once
// spread over 8 distinct source IPv6, scored 90% in both arms, and raising ATTEMPT_MS to 25s
// took the single-IP arm to 94%. What looks like a "collapse" at high concurrency is local
// queueing pushing latency into the ATTEMPT_MS cap. Do not add IP rotation for this.
//
// Endpoints (bind 127.0.0.1 only):
//   GET  /health              -> { ok, chrome, pool, stats }
//   POST /solve {siteKey,     -> { token, ms, attempts }
//                pageUrl,
//                timeoutMs?}
//   POST /trace {siteKey,     -> { file, calls[], token_length }
//                pageUrl,
//                timeoutMs?}
//   POST /restart             -> relaunch every browser, reset counters
//
// Config via env: CAPTCHA_SOLVER_HOST (127.0.0.1), CAPTCHA_SOLVER_PORT (8788),
//                 CAPTCHA_SOLVER_CONCURRENCY, CAPTCHA_SOLVER_BROWSERS,
//                 CAPTCHA_SOLVER_MAX_QUEUE, CAPTCHA_SOLVER_ATTEMPT_MS,
//                 CAPTCHA_SOLVER_MAX_ATTEMPTS, CAPTCHA_SOLVER_RECYCLE_AFTER,
//                 CAPTCHA_SOLVER_IDLE_MS (0 disables reaping), CAPTCHA_SOLVER_PREWARM=1,
//                 PUPPETEER_CACHE_DIR.

const { execFile } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');

// On the portal these resolve inside the repo's storage tree. A standalone solver node has
// no repo — it is just this file next to a node_modules — so every path is overridable and
// the installer points them at /opt/ipms-captcha.
const STORAGE = process.env.CAPTCHA_SOLVER_STORAGE_DIR
    || path.join(__dirname, '..', '..', 'storage', 'app', 'puppeteer');
const CAPTCHA_DIR = process.env.CAPTCHA_SOLVER_CAPTCHA_DIR
    || path.join(__dirname, '..', '..', 'storage', 'app', 'captcha');
const TRACE_DIR = path.join(CAPTCHA_DIR, 'turnstile_traces');
// Kept apart from the traces: pruneTraces() and the portal's trace list both glob that
// directory, and a bisect report has no `calls` to show.
const BISECT_DIR = path.join(CAPTCHA_DIR, 'turnstile_bisect');

// On a fresh node nothing has created this yet, and the HOME check below needs it to
// exist before it can be found writable.
try {
    fs.mkdirSync(STORAGE, { recursive: true });
} catch (e) {
    // Falls through to the HOME check, which reports the real problem.
}

// Chrome needs a writable HOME: it derives the crashpad database path from it, and
// when that resolution fails it spawns chrome_crashpad_handler with no --database
// and dies on a CHECK (SIGTRAP) before the DevTools port ever opens. www-data's
// home is /var/www, which is not writable, so every launch from PHP-FPM or a queue
// worker would fail. Fix it here rather than relying on the caller's environment.
if (!process.env.HOME || !isWritable(process.env.HOME)) {
    process.env.HOME = STORAGE;
}

// Resolve Chrome through puppeteer's own cache layout instead of a hardcoded version
// directory, so `npx puppeteer browsers install chrome` can bump the build without
// editing this file.
if (!process.env.PUPPETEER_CACHE_DIR) {
    process.env.PUPPETEER_CACHE_DIR = STORAGE;
}

const puppeteer = require('puppeteer');

const HOST = process.env.CAPTCHA_SOLVER_HOST || '127.0.0.1';
const PORT = parseInt(process.env.CAPTCHA_SOLVER_PORT || '8788', 10);
const API_TOKEN = process.env.CAPTCHA_SOLVER_API_TOKEN || '';
if (!API_TOKEN) {
    throw new Error('CAPTCHA_SOLVER_API_TOKEN is required; refusing to expose the solver without authentication');
}
const CONCURRENCY = Math.max(1, parseInt(process.env.CAPTCHA_SOLVER_CONCURRENCY || '9', 10));
// Concurrency is spread over this many Chrome processes. A single browser serialises
// CDP and IPC dispatch for every context it owns, which shows up as latency rather than
// errors: 16 in flight on one browser runs at p50 6.1s, on four at p50 4.4s.
const POOL_SIZE = Math.max(1, parseInt(process.env.CAPTCHA_SOLVER_BROWSERS || '4', 10));

// The live values. CONCURRENCY/POOL_SIZE stay as the boot-time defaults the systemd unit
// sized from core count; the portal can retune a node from the fleet console without an
// SSH session or a restart, and pushes its choice back on every heartbeat so it survives one.
let currentConcurrency = CONCURRENCY;
const MAX_QUEUE = Math.max(0, parseInt(process.env.CAPTCHA_SOLVER_MAX_QUEUE || '32', 10));
// A successful solve lands in ~2.5-3.6s even with the pool saturated, so 10s is ~3x
// the observed worst case: generous enough not to abandon a healthy attempt, tight
// enough that burning one stalled attempt still leaves room for two more retries
// inside the default 45s budget.
const ATTEMPT_MS = Math.max(3000, parseInt(process.env.CAPTCHA_SOLVER_ATTEMPT_MS || '10000', 10));
const MAX_ATTEMPTS = Math.max(1, parseInt(process.env.CAPTCHA_SOLVER_MAX_ATTEMPTS || '3', 10));
const DEFAULT_TIMEOUT_MS = Math.max(5000, parseInt(process.env.CAPTCHA_SOLVER_TIMEOUT_MS || '45000', 10));
const RECYCLE_AFTER = Math.max(0, parseInt(process.env.CAPTCHA_SOLVER_RECYCLE_AFTER || '400', 10));

// Idle reaping. A warm pool costs the same whether it is solving or not — measured at 1.84 GB
// across 61 Chrome processes with zero traffic — and this host also runs the Java bot, so
// holding that permanently is the single most wasteful thing the service does. Browsers are
// closed after this long with nothing in flight and relaunched on demand; the cost is one
// cold start (~350ms) on the next solve, which is small against a ~2.5s solve and is only
// paid when traffic resumes. 0 disables reaping.
const IDLE_MS = Math.max(0, parseInt(process.env.CAPTCHA_SOLVER_IDLE_MS || '60000', 10));
// Warming at boot defeats the point of reaping — the pool would sit at full cost until the
// first idle window elapsed. Opt in only where first-solve latency matters more than memory.
const PREWARM = process.env.CAPTCHA_SOLVER_PREWARM === '1';

// Trace mode keeps whole response bodies because the challenge script IS the artefact
// step 4 of the emulation plan has to run. The orchestrate script is ~200-400 KB, so the
// cap is set well above it and only guards against a runaway.
const TRACE_BODY_LIMIT = Math.max(64_000, parseInt(process.env.CAPTCHA_TRACE_BODY_LIMIT || '2000000', 10));
// How long to keep recording after the token lands. Cloudflare fires trailing beacons
// after the callback, and a trace that stops at the token would miss them.
const TRACE_SETTLE_MS = Math.max(0, parseInt(process.env.CAPTCHA_TRACE_SETTLE_MS || '1500', 10));
const TRACE_KEEP = Math.max(1, parseInt(process.env.CAPTCHA_TRACE_KEEP || '20', 10));
// A trace takes one uninterrupted attempt rather than the hot path's three short ones, so
// it gets a budget wide enough that a slow-but-healthy sequence still completes.
const TRACE_DEFAULT_MS = Math.max(10_000, parseInt(process.env.CAPTCHA_TRACE_TIMEOUT_MS || '30000', 10));

// Chrome's own headless UA advertises "HeadlessChrome" and Blink sets
// navigator.webdriver under automation. Cloudflare rejects the widget's challenge
// request with 403 on either signal, so the widget never renders and no token is
// ever produced. Overriding the UA and disabling the AutomationControlled blink
// feature is what takes this from 0% to ~87% per attempt — do not remove.
const USER_AGENT = process.env.CAPTCHA_SOLVER_UA
    || 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

const CHROME_ARGS = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-blink-features=AutomationControlled',
    '--disable-background-networking',
    '--disable-backgrounding-occluded-windows',
    '--disable-renderer-backgrounding',
    '--disable-background-timer-throttling',
    '--no-first-run',
    '--no-default-browser-check',
    // Trim the machinery a solve never uses. Deliberately conservative: nothing here changes
    // a JS-observable signal, because step 3 of the emulation work measured that Cloudflare
    // checks those and rejects a mismatch. In particular do NOT add
    // --disable-software-rasterizer (it would remove the WebGL context a real browser has)
    // or touch AcceptCHFrame (client hints are part of the identity being checked).
    '--disable-extensions',
    '--disable-component-update',
    '--disable-default-apps',
    '--disable-sync',
    '--disable-domain-reliability',
    '--disable-client-side-phishing-detection',
    '--disable-breakpad',
    '--metrics-recording-only',
    '--disable-features=Translate,MediaRouter,OptimizationHints,BackForwardCache,InterestFeedContentSuggestions',
    '--window-size=1280,800',
    `--user-agent=${USER_AGENT}`,
];

const SITE_KEY_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;

const stats = {
    startedAt: Date.now(),
    solved: 0,
    failed: 0,
    attempts: 0,
    launches: 0,
    totalMs: 0,
    lastError: null,
    lastSolvedAt: null,
};

let shuttingDown = false;

function isWritable(dir) {
    try {
        fs.accessSync(dir, fs.constants.W_OK);

        return true;
    } catch (e) {
        return false;
    }
}

function log(msg) {
    process.stdout.write(`[in-house-captcha] ${new Date().toISOString()} ${msg}\n`);
}

function sendJson(res, code, obj) {
    const body = JSON.stringify(obj);
    res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
    res.end(body);
}

function isAuthorized(req) {
    const provided = req.headers['x-captcha-solver-token'];
    return typeof provided === 'string'
        && provided.length === API_TOKEN.length
        && crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(API_TOKEN));
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let data = '';
        let size = 0;
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > 64_000) {
                reject(new Error('body too large'));
                req.destroy();

                return;
            }
            data += chunk;
        });
        req.on('end', () => resolve(data));
        req.on('error', reject);
    });
}

/** Error carrying the HTTP status the request handler should answer with. */
class HttpError extends Error {
    constructor(status, message) {
        super(message);
        this.status = status;
    }
}

/**
 * Validate the {siteKey, pageUrl, timeoutMs} body shared by /solve and /trace.
 *
 * The site-key pattern is not cosmetic: the key is interpolated into a JS string literal
 * in buildPage(), and this is what stops it breaking out.
 *
 * @return {{siteKey: string, pageUrl: string, timeoutMs: number}}
 */
function parseTargetBody(body, defaultTimeoutMs) {
    const siteKey = typeof body.siteKey === 'string' ? body.siteKey.trim() : '';
    const pageUrl = typeof body.pageUrl === 'string' ? body.pageUrl.trim() : '';

    if (!SITE_KEY_PATTERN.test(siteKey)) {
        throw new HttpError(422, 'siteKey must match [A-Za-z0-9_-]{8,64}');
    }

    let parsed;

    try {
        parsed = new URL(pageUrl);
    } catch (e) {
        throw new HttpError(422, 'pageUrl must be an absolute URL');
    }

    if (parsed.protocol !== 'https:') {
        throw new HttpError(422, 'pageUrl must be https');
    }

    return {
        siteKey,
        pageUrl,
        timeoutMs: Number.isFinite(body.timeoutMs)
            ? Math.min(120_000, Math.max(5_000, body.timeoutMs))
            : defaultTimeoutMs,
    };
}

// ---------------------------------------------------------------------------
// Concurrency gate
// ---------------------------------------------------------------------------

let active = 0;
const waiters = [];
let lastActivityAt = Date.now();

/**
 * Claim one solve slot, queueing when the pool is saturated. Rejects immediately
 * once the queue is full so a burst sheds load instead of piling up behind a
 * browser that may be wedged.
 */
function acquire() {
    lastActivityAt = Date.now();

    if (active < currentConcurrency) {
        active++;

        return Promise.resolve();
    }

    if (waiters.length >= MAX_QUEUE) {
        return Promise.reject(new HttpError(503, `solver saturated (${currentConcurrency} active, ${waiters.length} queued)`));
    }

    return new Promise((resolve) => waiters.push(resolve));
}

/** Hand the slot straight to the next waiter, so `active` stays accurate. */
function release() {
    lastActivityAt = Date.now();

    // Only pass the slot on while we are still inside the limit. After a shrink `active`
    // can exceed it, and handing the slot over would pin it there instead of letting it
    // drain down to the new value.
    if (active <= currentConcurrency) {
        const next = waiters.shift();

        if (next) {
            next();

            return;
        }
    }

    active--;
}

/**
 * Retune concurrency without a restart.
 *
 * Growing releases queued waiters straight away; shrinking lets `active` fall naturally as
 * in-flight solves finish, because cancelling one would waste a Cloudflare round trip that
 * is already most of the way through.
 *
 * The browser pool is resized to match, because concurrency alone is only a semaphore:
 * raising it from 4 to 16 against a single browser buys queueing, not throughput (p50 6.1s
 * on one browser vs 4.4s on four at the same 16 in flight).
 */
function setConcurrency(value) {
    const next = Math.max(1, Math.min(64, parseInt(value, 10) || 0));

    if (next === currentConcurrency) {
        return currentConcurrency;
    }

    const previous = currentConcurrency;
    currentConcurrency = next;

    while (active < currentConcurrency && waiters.length > 0) {
        active++;
        waiters.shift()();
    }

    log(`concurrency ${previous} -> ${currentConcurrency}`);
    resizePool(idealBrowsers(currentConcurrency));

    return currentConcurrency;
}

/** The measured efficient shape: roughly four concurrent solves per Chrome process. */
function idealBrowsers(concurrency) {
    return Math.max(1, Math.ceil(concurrency / 4));
}

/**
 * Grow or shrink the Chrome pool in place.
 *
 * New slots are added cold — they launch on first use like any other, so growing costs
 * nothing until traffic arrives. Removed slots are taken out of the rotation first so no
 * further solve can lease one, then closed once their in-flight solves have drained;
 * closing one outright would kill a solve that is already most of the way through.
 */
function resizePool(target) {
    if (target === pool.length) {
        return pool.length;
    }

    const previous = pool.length;

    if (target > pool.length) {
        let nextIndex = pool.reduce((max, s) => Math.max(max, s.index), 0);

        while (pool.length < target) {
            pool.push(new BrowserSlot(++nextIndex));
        }

        log(`chrome pool ${previous} -> ${pool.length} browser(s)`);

        return pool.length;
    }

    const retired = pool.splice(target);
    retired.forEach((slot) => {
        slot.draining = true;
    });

    log(`chrome pool ${previous} -> ${pool.length} browser(s), draining ${retired.length}`);

    retired.forEach((slot) => {
        const drain = async () => {
            while (slot.leases > 0) {
                await sleep(500);
            }

            await slot.close();
        };

        drain().catch((e) => log(`chrome #${slot.index} drain failed: ${e.message}`));
    });

    return pool.length;
}

// ---------------------------------------------------------------------------
// Browser lifecycle
// ---------------------------------------------------------------------------

/**
 * One Chrome process in the pool, tracking its own in-flight solves so it can be
 * recycled independently of the others.
 */
class BrowserSlot {
    constructor(index) {
        this.index = index;
        this.browser = null;
        this.launching = null;
        this.solves = 0;
        this.leases = 0;
        this.draining = false;
    }

    /**
     * Return this slot's warm browser, launching it if needed. Concurrent callers
     * during a cold start share one launch promise instead of racing N processes.
     */
    get() {
        if (this.browser && this.browser.connected) {
            return Promise.resolve(this.browser);
        }

        if (!this.launching) {
            this.launching = puppeteer
                .launch({
                    headless: true,
                    args: CHROME_ARGS,
                    // The window-size arg already gives every page 1280x800, so letting
                    // puppeteer skip its own viewport override saves a CDP round trip per
                    // page and leaves the page reporting unmodified metrics.
                    defaultViewport: null,
                })
                .then((launched) => {
                    this.browser = launched;
                    this.solves = 0;
                    this.draining = false;
                    stats.launches++;
                    launched.on('disconnected', () => {
                        if (this.browser === launched) {
                            this.browser = null;
                        }
                        if (!shuttingDown) {
                            log(`chrome #${this.index} disconnected — will relaunch on next solve`);
                        }
                    });
                    log(`chrome #${this.index} launched (pid ${launched.process() ? launched.process().pid : '?'})`);

                    return launched;
                })
                .finally(() => {
                    this.launching = null;
                });
        }

        return this.launching;
    }

    /**
     * Close this browser once it has served RECYCLE_AFTER solves, reclaiming the memory
     * a long-lived renderer accumulates.
     *
     * The slot stops accepting new leases as soon as it is over the threshold and closes
     * when the last in-flight solve drains, so recycling still happens under sustained
     * load. Waiting for the whole pool to fall idle instead — as this did while there was
     * only one browser — means it never runs at all once a queue forms.
     */
    async maybeRecycle() {
        if (RECYCLE_AFTER === 0 || this.solves < RECYCLE_AFTER || !this.browser) {
            return;
        }

        this.draining = true;

        if (this.leases > 0) {
            return;
        }

        log(`recycling chrome #${this.index} after ${this.solves} solves`);
        const stale = this.browser;
        this.browser = null;
        await stale.close().catch(() => {});
    }

    async close() {
        const stale = this.browser;
        this.browser = null;

        if (stale) {
            // A close that fails leaks the temp profile puppeteer created for this launch, so
            // it is logged rather than swallowed — silence here is what let 46 orphaned
            // directories accumulate unnoticed.
            await stale.close().catch((e) => log(`chrome #${this.index} close failed: ${e.message}`));
        }
    }
}

const pool = Array.from({ length: POOL_SIZE }, (_, i) => new BrowserSlot(i + 1));

/**
 * Delete temp Chrome profiles left behind by browsers that are no longer running.
 *
 * Puppeteer creates one throwaway profile per launch and removes it on a clean close, so
 * anything still on disk belongs to a browser that died without one — which is every browser
 * whenever systemd has to SIGKILL a shutdown that overran its timeout. Nothing else ever
 * removes them, and with idle reaping plus recycle-after-N the launch rate is high enough that
 * they accumulate indefinitely (46 directories, 31 MB, when this was found).
 *
 * A directory is only removed when no live process references it, so this is safe to run at
 * startup while another solver instance is up.
 */
function sweepOrphanProfiles() {
    let candidates;

    try {
        candidates = fs.readdirSync(os.tmpdir())
            .filter((name) => name.startsWith('puppeteer_dev_chrome_profile-'))
            .map((name) => path.join(os.tmpdir(), name));
    } catch (e) {
        return { removed: 0, kept: 0, bytes: 0 };
    }

    if (candidates.length === 0) {
        return { removed: 0, kept: 0, bytes: 0 };
    }

    // One pass over /proc rather than one per directory: a profile is in use if any live
    // process names it on its command line.
    const inUse = new Set();

    try {
        for (const entry of fs.readdirSync('/proc')) {
            if (!/^\d+$/.test(entry)) {
                continue;
            }

            let cmdline;

            try {
                cmdline = fs.readFileSync(`/proc/${entry}/cmdline`, 'utf8');
            } catch (e) {
                continue; // the process exited between readdir and read
            }

            for (const dir of candidates) {
                if (cmdline.includes(dir)) {
                    inUse.add(dir);
                }
            }
        }
    } catch (e) {
        // Without /proc we cannot prove a profile is unused, so remove nothing.
        return { removed: 0, kept: candidates.length, bytes: 0 };
    }

    let removed = 0;
    let bytes = 0;

    for (const dir of candidates) {
        if (inUse.has(dir)) {
            continue;
        }

        try {
            bytes += directorySize(dir);
            fs.rmSync(dir, { recursive: true, force: true });
            removed++;
        } catch (e) {
            // Another instance may have won the race, or it is not ours to delete.
        }
    }

    return { removed, kept: inUse.size, bytes };
}

/** Recursive size on disk, best-effort — this only ever feeds a log line. */
function directorySize(dir) {
    let total = 0;

    try {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);

            total += entry.isDirectory() ? directorySize(full) : fs.statSync(full).size;
        }
    } catch (e) {
        // Unreadable entries contribute nothing.
    }

    return total;
}

/**
 * Close the whole pool once it has been idle long enough, so an unused solver costs nothing.
 *
 * Skips while anything is in flight, queued, or mid-launch — closing a browser out from under
 * a launch in progress leaves the slot believing it has one.
 */
/**
 * Whether the pool can be closed right now.
 *
 * Every clause is a way to destroy a live solve, which is why this is a pure predicate rather
 * than inline conditions: a slot mid-launch has no `browser` yet but is about to, and closing
 * around it leaves the slot holding a handle to a dead process.
 *
 * @param {{active: number, queued: number, idleForMs: number, idleMs: number, slots: Array}} state
 */
function isReapable({ active: inFlight, queued, idleForMs, idleMs, slots }) {
    if (idleMs === 0 || inFlight > 0 || queued > 0 || idleForMs < idleMs) {
        return false;
    }

    if (slots.some((s) => s.launching || s.leases > 0)) {
        return false;
    }

    return slots.some((s) => s.browser);
}

/** Close the whole pool once it has been idle long enough, so an unused solver costs nothing. */
function startIdleReaper() {
    if (IDLE_MS === 0) {
        return;
    }

    const timer = setInterval(() => {
        const idleForMs = Date.now() - lastActivityAt;

        if (!isReapable({ active, queued: waiters.length, idleForMs, idleMs: IDLE_MS, slots: pool })) {
            return;
        }

        log(`idle ${Math.round(idleForMs / 1000)}s — closing ${pool.filter((s) => s.browser).length} browser(s)`);
        Promise.all(pool.map((s) => s.close())).catch((e) => log(`idle reap failed: ${e.message}`));
    }, Math.min(15_000, Math.max(5_000, Math.floor(IDLE_MS / 4))));

    // Never hold the process open just to run the reaper.
    timer.unref();
}

/**
 * Pick the least-loaded browser, skipping any that is draining toward a recycle. When
 * every slot is draining the least-loaded one is used anyway rather than failing the
 * solve — the recycle is an optimisation, not a correctness requirement.
 */
function leaseSlot() {
    const candidates = pool.filter((s) => !s.draining);
    const from = candidates.length > 0 ? candidates : pool;
    const slot = from.reduce((best, s) => (s.leases < best.leases ? s : best), from[0]);

    slot.leases++;

    return slot;
}

// ---------------------------------------------------------------------------
// Solving
// ---------------------------------------------------------------------------

/**
 * The synthetic document served in place of the real one. The site key is
 * validated against SITE_KEY_PATTERN before it reaches here, so it cannot break
 * out of the JS string literal.
 *
 * The data: favicon is load-bearing. Without it Chrome falls back to /favicon.ico on
 * the page's own origin, which is the one request in the whole solve that is NOT
 * intercepted and therefore actually reaches IVAC — once per solve, for a file we
 * never use.
 *
 * The token is published on window and polled for. It is tempting to have the callback
 * push it out through page.exposeFunction() instead and drop the polling entirely, but
 * Cloudflare detects the binding puppeteer injects and answers with a widget that never
 * renders an iframe and never fires either callback: measured 0/2 solves against 2/2 for
 * every other variant here. Do not reintroduce it.
 */
function buildPage(siteKey) {
    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>&nbsp;</title>
<link rel="icon" href="data:,"></head><body>
<div id="widget"></div>
<script>
window.__ihcToken = null;
window.__ihcError = null;
window.__ihcOnload = function () {
    turnstile.render('#widget', {
        sitekey: '${siteKey}',
        callback: function (token) { window.__ihcToken = token; },
        'error-callback': function (code) { window.__ihcError = String(code || 'unknown'); },
    });
};
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__ihcOnload" async defer></script>
</body></html>`;
}

/** Escape the CDP URL-pattern wildcards so a page URL is matched literally. */
function cdpEscapeUrl(url) {
    return url.replace(/[\\*?]/g, (c) => `\\${c}`);
}

/**
 * One solve attempt in a throwaway incognito context.
 *
 * A fresh context per attempt matters twice over: it keeps Cloudflare's per-context
 * challenge state from leaking between solves, and it is the recovery path for the
 * ~13% of attempts where the widget silently never fires its callback despite an
 * identical, all-2xx network trace.
 */
async function solveOnce(activeBrowser, siteKey, pageUrl, budgetMs) {
    // Navigate to and match on the SAME normalized href. Chrome normalizes a bare
    // origin ("https://host") to "https://host/", so comparing against the raw
    // setting would miss the navigation, fall through to the real site, and quietly
    // time out on a page that has no widget.
    const target = new URL(pageUrl).href;
    const context = await activeBrowser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        // Pause only the top-level document. page.setRequestInterception(true) would pause
        // every request instead, and the challenge sequence issues eight or nine of them —
        // each one then costs a CDP round trip out to this process and back just to be
        // waved through. Narrowing the pattern lets them go straight out at native speed,
        // which is what keeps p50 flat as concurrency rises.
        const cdp = await page.createCDPSession();
        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: cdpEscapeUrl(target), requestStage: 'Request', resourceType: 'Document' }],
        });
        cdp.on('Fetch.requestPaused', ({ requestId }) => {
            cdp
                .send('Fetch.fulfillRequest', {
                    requestId,
                    responseCode: 200,
                    responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                    body: html,
                })
                .catch(() => {});
        });

        const startedAt = Date.now();
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: budgetMs });

        const remaining = Math.max(1000, budgetMs - (Date.now() - startedAt));
        const handle = await page.waitForFunction(
            () => {
                if (window.__ihcToken) {
                    return { token: window.__ihcToken };
                }

                return window.__ihcError ? { error: window.__ihcError } : false;
            },
            { timeout: remaining, polling: 100 },
        );

        const result = await handle.jsonValue();

        if (result.error) {
            throw new Error(`turnstile error-callback: ${result.error}`);
        }

        return result.token;
    } finally {
        await context.close().catch(() => {});
    }
}

/**
 * Solve with retries inside an overall deadline. Each attempt is capped well below
 * the deadline so a stalled widget is abandoned and retried rather than consuming
 * the whole budget.
 */
async function solve(siteKey, pageUrl, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    let lastError = null;

    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        const budget = Math.min(ATTEMPT_MS, deadline - Date.now());

        if (budget < 1500) {
            break;
        }

        stats.attempts++;
        const slot = leaseSlot();

        try {
            const activeBrowser = await slot.get();
            const token = await solveOnce(activeBrowser, siteKey, pageUrl, budget);
            slot.solves++;

            return { token, attempts: attempt };
        } catch (e) {
            lastError = e;
            log(`attempt ${attempt}/${MAX_ATTEMPTS} failed: ${e.message}`);
        } finally {
            slot.leases--;
            slot.maybeRecycle().catch((e) => log(`recycle failed: ${e.message}`));
        }
    }

    throw new HttpError(504, lastError ? lastError.message : 'no attempt completed within the deadline');
}

// ---------------------------------------------------------------------------
// Tracing
//
// Step 1 of the protocol-emulation plan: record exactly what a successful solve does,
// so the sequence can be replayed without a browser. The trace is the specification for
// every later step, and re-capturing it is how a future breakage gets diagnosed.
// ---------------------------------------------------------------------------

const CHALLENGE_PATH = '/cdn-cgi/challenge-platform/';
const TURNSTILE_HOST = 'challenges.cloudflare.com';

/**
 * Tag a recorded URL by its role in the flow, so a trace can be read — and asserted on —
 * without re-deriving Cloudflare's URL layout at every call site. The challenge check
 * comes first because those requests are served from the Turnstile host too.
 */
function classifyUrl(url, target) {
    if (url === target) {
        return 'document';
    }

    // The widget builds its workers from blob: URLs. They never touch the network, so
    // they carry no response and an emulator has nothing to reproduce for them — but they
    // must not be filed as unexplained 'other' traffic either.
    if (url.startsWith('blob:')) {
        return 'blob';
    }

    let parsed;

    try {
        parsed = new URL(url);
    } catch (e) {
        return 'other';
    }

    if (parsed.pathname.startsWith(CHALLENGE_PATH)) {
        return 'challenge';
    }

    if (parsed.host === TURNSTILE_HOST || parsed.host.endsWith(`.${TURNSTILE_HOST}`)) {
        return 'turnstile';
    }

    return 'other';
}

/**
 * Reduce a captured sequence to the shape a human (or a later extraction step) reads
 * first: what was called, in what order, and how big each leg was.
 */
function summariseTrace(entries) {
    const byRole = {};

    for (const entry of entries) {
        byRole[entry.role] = (byRole[entry.role] || 0) + 1;
    }

    return {
        requests: entries.length,
        by_role: byRole,
        hosts: [...new Set(entries.map((e) => e.host).filter(Boolean))],
        challenge_sequence: entries
            .filter((e) => e.role === 'challenge')
            .map((e) => ({
                order: e.order,
                t_ms: e.t_ms,
                method: e.method,
                path: e.path,
                status: e.status,
                request_bytes: e.request_body ? e.request_body.length : 0,
                response_bytes: e.response_body ? e.response_body.length : 0,
            })),
    };
}

/** Keep only the newest TRACE_KEEP trace files, so repeated captures cannot fill the disk. */
function pruneTraces() {
    let files;

    try {
        files = fs.readdirSync(TRACE_DIR).filter((f) => f.endsWith('.json')).sort();
    } catch (e) {
        return;
    }

    for (const stale of files.slice(0, Math.max(0, files.length - TRACE_KEEP))) {
        try {
            fs.unlinkSync(path.join(TRACE_DIR, stale));
        } catch (e) {
            // A trace that cannot be removed is not worth failing the capture over.
        }
    }
}

/**
 * One instrumented solve, recording every request the challenge sequence issues.
 *
 * Recording rides the Network domain rather than widening the hot path's Fetch pattern to
 * every request. Fetch pauses each match for a CDP round trip, and this flow is timing
 * sensitive enough that success rate moves with it — a trace that perturbs the thing it
 * measures is worthless as a specification. Network is passive and yields strictly more:
 * requestWillBeSentExtraInfo carries the headers as actually put on the wire, cookies
 * included, and the initiator carries the call stack, so every entry records which script
 * issued it. The document swap stays on the same narrow Fetch pattern the hot path uses,
 * so what gets traced is the flow production actually drives.
 */
async function traceOnce(activeBrowser, siteKey, pageUrl, budgetMs) {
    const target = new URL(pageUrl).href;
    const context = await activeBrowser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();

        // Bodies recovered through the Fetch domain, keyed by URL, for requests
        // Network.getResponseBody cannot answer for.
        const fetchBodies = new Map();

        // Two patterns. The first is the synthetic document swap, identical to the hot
        // path. The second pauses the widget's own iframe document at Response stage: that
        // navigation is handed to an out-of-process frame, after which neither the parent
        // session nor the child can produce its bytes, yet it carries the inline bootstrap
        // an emulator has to reproduce. One extra pause on one request — narrow enough not
        // to disturb the timing the rest of the sequence is sensitive to.
        await cdp.send('Fetch.enable', {
            patterns: [
                { urlPattern: cdpEscapeUrl(target), requestStage: 'Request', resourceType: 'Document' },
                { urlPattern: `https://${TURNSTILE_HOST}${CHALLENGE_PATH}*`, requestStage: 'Response', resourceType: 'Document' },
            ],
        });
        cdp.on('Fetch.requestPaused', async (e) => {
            if (e.responseStatusCode === undefined) {
                await cdp
                    .send('Fetch.fulfillRequest', {
                        requestId: e.requestId,
                        responseCode: 200,
                        responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                        body: html,
                    })
                    .catch(() => {});

                return;
            }

            await cdp
                .send('Fetch.getResponseBody', { requestId: e.requestId })
                .then((body) => fetchBodies.set(e.request.url, body))
                .catch(() => {});

            await cdp.send('Fetch.continueResponse', { requestId: e.requestId }).catch(() => {});
        });

        const records = new Map();
        const pendingBodies = [];
        const sessions = [];
        const instrumented = new Set();
        let startedAt = Date.now();

        /**
         * Wire one CDP session for recording, then follow it down the target tree.
         *
         * The Turnstile widget renders in a cross-origin iframe, which Chrome puts in its
         * own process and therefore its own CDP target. A page session sees that iframe's
         * document request and nothing inside it — the first capture here recorded exactly
         * three requests for that reason, with the entire challenge sequence missing.
         * Auto-attaching to child targets, and holding each new one at
         * waitForDebuggerOnStart until its Network.enable has landed, is what makes the
         * sequence visible at all; without the hold, the iframe races ahead and its first
         * requests are lost.
         */
        const instrument = async (session, label) => {
            if (instrumented.has(session)) {
                return;
            }

            instrumented.add(session);
            sessions.push(session);

            /** Request ids are only unique per session, so records are keyed by both. */
            const track = (requestId) => {
                const key = `${label}#${requestId}`;
                let record = records.get(key);

                if (!record) {
                    record = {
                        order: records.size,
                        t_ms: Date.now() - startedAt,
                        request_id: requestId,
                        session: label,
                    };
                    records.set(key, record);
                }

                return record;
            };

            session.on('Network.requestWillBeSent', (e) => {
                const record = track(e.requestId);

                record.method = e.request.method;
                record.url = e.request.url;
                record.resource_type = e.type;
                record.initiator = {
                    type: e.initiator ? e.initiator.type : null,
                    url: e.initiator ? e.initiator.url || null : null,
                    stack: e.initiator && e.initiator.stack
                        ? e.initiator.stack.callFrames.slice(0, 4).map((f) => `${f.functionName || '(anonymous)'} @ ${f.url}:${f.lineNumber}`)
                        : null,
                };
                record.request_headers = e.request.headers;
                record.request_body = e.request.postData || null;
                record.request_body_truncated = Boolean(e.request.hasPostData && !e.request.postData);
            });

            // The planned headers on requestWillBeSent are not the wire headers: cookies and
            // several sec-* headers are attached later. This event is the authoritative set,
            // which is exactly what an emulator has to reproduce.
            session.on('Network.requestWillBeSentExtraInfo', (e) => {
                track(e.requestId).request_headers_sent = e.headers;
            });

            session.on('Network.responseReceived', (e) => {
                const record = track(e.requestId);

                record.status = e.response.status;
                record.mime_type = e.response.mimeType;
                record.protocol = e.response.protocol;
                record.remote_ip = e.response.remoteIPAddress || null;
                record.response_headers = e.response.headers;
            });

            session.on('Network.responseReceivedExtraInfo', (e) => {
                track(e.requestId).response_headers_raw = e.headers;
            });

            session.on('Network.loadingFailed', (e) => {
                const record = track(e.requestId);

                record.failed = true;
                record.error = e.errorText;
            });

            session.on('Network.loadingFinished', (e) => {
                const record = track(e.requestId);

                record.encoded_bytes = e.encodedDataLength;
                // Deferred rather than read here: a document that becomes an out-of-process
                // iframe finishes on the PARENT session while its bytes live in the child's
                // process, and that child target does not exist yet at this point. Reading
                // once the flow has settled means every session is available to ask.
                pendingBodies.push({ session, requestId: e.requestId, record });
            });

            session.on('Target.attachedToTarget', (e) => {
                const connection = session.connection();
                const child = connection ? connection.session(e.sessionId) : null;

                if (child) {
                    instrument(child, `${e.targetInfo.type}:${e.targetInfo.targetId.slice(0, 8)}`).catch(() => {});
                }
            });

            await session.send('Target.setAutoAttach', {
                autoAttach: true,
                waitForDebuggerOnStart: true,
                flatten: true,
            }).catch(() => {});

            await session.send('Network.enable', {
                maxTotalBufferSize: 64_000_000,
                maxResourceBufferSize: 32_000_000,
                maxPostDataSize: TRACE_BODY_LIMIT,
            }).catch(() => {});

            // No-op on a target that was not held; required on every one that was.
            await session.send('Runtime.runIfWaitingForDebugger').catch(() => {});
        };

        await instrument(cdp, 'page');

        startedAt = Date.now();
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: budgetMs });

        const remaining = Math.max(1000, budgetMs - (Date.now() - startedAt));
        const handle = await page.waitForFunction(
            () => {
                if (window.__ihcToken) {
                    return { token: window.__ihcToken };
                }

                return window.__ihcError ? { error: window.__ihcError } : false;
            },
            { timeout: remaining, polling: 100 },
        );

        const outcome = await handle.jsonValue();
        const tokenMs = Date.now() - startedAt;

        // Cloudflare fires trailing beacons after the callback; a trace that stopped at the
        // token would leave them out of the specification.
        await new Promise((resolve) => setTimeout(resolve, TRACE_SETTLE_MS));

        // Ask the owning session first, then every other one. The iframe bootstrap HTML —
        // the body an emulator most needs — is only retrievable from the child target that
        // took the document over, and the parent answers "no resource with given identifier".
        await Promise.all(
            pendingBodies.map(async ({ session, requestId, record }) => {
                for (const candidate of [session, ...sessions.filter((s) => s !== session)]) {
                    try {
                        const body = await candidate.send('Network.getResponseBody', { requestId });

                        record.response_body = body.body.length > TRACE_BODY_LIMIT
                            ? body.body.slice(0, TRACE_BODY_LIMIT)
                            : body.body;
                        record.response_body_truncated = body.body.length > TRACE_BODY_LIMIT;
                        record.response_body_base64 = Boolean(body.base64Encoded);

                        return;
                    } catch (e) {
                        // Try the next session; redirects and 204s have no body anywhere.
                    }
                }
            }),
        );

        // Fill from the Fetch domain last. The iframe navigation never reaches
        // loadingFinished on the session that issued it — the out-of-process frame takes it
        // over mid-flight — so it has no pending read to satisfy above and is only
        // recoverable from the Response-stage pause.
        for (const record of records.values()) {
            const viaFetch = record.response_body === undefined ? fetchBodies.get(record.url) : null;

            if (viaFetch) {
                record.response_body = viaFetch.body.slice(0, TRACE_BODY_LIMIT);
                record.response_body_truncated = viaFetch.body.length > TRACE_BODY_LIMIT;
                record.response_body_base64 = Boolean(viaFetch.base64Encoded);
            }
        }

        const entries = [...records.values()]
            .filter((r) => r.url)
            .sort((a, b) => a.order - b.order)
            .map((r) => {
                let parsed = null;

                try {
                    parsed = new URL(r.url);
                } catch (e) {
                    parsed = null;
                }

                return {
                    ...r,
                    host: parsed ? parsed.host : null,
                    path: parsed ? parsed.pathname + parsed.search : null,
                    role: classifyUrl(r.url, target),
                };
            });

        return {
            captured_at: new Date().toISOString(),
            site_key: siteKey,
            page_url: target,
            user_agent: USER_AGENT,
            outcome: {
                solved: Boolean(outcome.token),
                error: outcome.error || null,
                token_length: outcome.token ? outcome.token.length : 0,
                token_prefix: outcome.token ? outcome.token.slice(0, 24) : null,
                ms: tokenMs,
            },
            summary: summariseTrace(entries),
            calls: entries,
        };
    } finally {
        await context.close().catch(() => {});
    }
}

/**
 * Capture one trace and persist it. Unlike solve() this does not retry: a trace is a
 * specification of a SUCCESSFUL flow, and silently returning the second attempt would
 * hide that the first was rejected — which is itself the signal worth seeing.
 */
async function trace(siteKey, pageUrl, timeoutMs) {
    const slot = leaseSlot();

    try {
        const activeBrowser = await slot.get();
        const captured = await traceOnce(activeBrowser, siteKey, pageUrl, timeoutMs);

        fs.mkdirSync(TRACE_DIR, { recursive: true });
        const name = `${captured.captured_at.replace(/[:.]/g, '-')}.json`;
        fs.writeFileSync(path.join(TRACE_DIR, name), JSON.stringify(captured, null, 2));
        pruneTraces();

        log(`trace captured: ${captured.summary.requests} requests, ${captured.summary.challenge_sequence.length} on the challenge path -> ${name}`);

        return { file: name, ...captured };
    } finally {
        slot.leases--;
    }
}

// ---------------------------------------------------------------------------
// Fingerprint bisect
//
// Step 3 of the protocol-emulation plan: find which browser signals Cloudflare actually
// checks. The blob the first flow POST uploads carries far more than the challenge cares
// about, and an emulator only has to reproduce the fields that, when wrong, get the widget
// rejected. That set is measured here rather than guessed — one mutation at a time,
// against the real widget, counting solves.
// ---------------------------------------------------------------------------

const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

/**
 * Each mutation changes exactly one signal, applied through the Emulation domain so it
 * lands the way a real browser would report it rather than as a detectable JS patch.
 *
 * webdriver-on is the positive control: it is already known to make Cloudflare answer 403
 * and render no widget, so an arm that does NOT collapse means the harness is not actually
 * reaching the challenge and every other result in the run is meaningless.
 */
const MUTATIONS = {
    // The primary control. Chrome's own headless UA is documented to make Cloudflare answer
    // the widget's challenge request with 403, and setUserAgentOverride applies immediately
    // to the session it is sent to — unlike an injected script, which only affects the NEXT
    // document and so left the first version of this control silently inert at 100%.
    'ua-headless': (s) => s.send('Emulation.setUserAgentOverride', {
        userAgent: USER_AGENT.replace('Chrome/', 'HeadlessChrome/'),
    }),
    'webdriver-on': async (s) => {
        await s.send('Page.enable').catch(() => {});
        await s.send('Page.addScriptToEvaluateOnNewDocument', {
            source: 'Object.defineProperty(navigator, "webdriver", { get: () => true });',
        });
    },
    'timezone-utc': (s) => s.send('Emulation.setTimezoneOverride', { timezoneId: 'UTC' }),
    'timezone-tokyo': (s) => s.send('Emulation.setTimezoneOverride', { timezoneId: 'Asia/Tokyo' }),
    'locale-ja': (s) => s.send('Emulation.setLocaleOverride', { locale: 'ja-JP' }),
    'screen-800x600': (s) => s.send('Emulation.setDeviceMetricsOverride', {
        width: 800, height: 600, deviceScaleFactor: 1, mobile: false,
    }),
    'screen-mobile': (s) => s.send('Emulation.setDeviceMetricsOverride', {
        width: 390, height: 844, deviceScaleFactor: 3, mobile: true,
    }),
    'cores-1': (s) => s.send('Emulation.setHardwareConcurrencyOverride', { hardwareConcurrency: 1 }),
    'ua-platform-mismatch': (s) => s.send('Emulation.setUserAgentOverride', {
        userAgent: USER_AGENT, platform: 'Win32',
    }),
    'ua-windows': (s) => s.send('Emulation.setUserAgentOverride', {
        userAgent: WINDOWS_UA, platform: 'Win32',
    }),
    // The same Windows identity, but with the client hints moved to match. ua-windows keeps
    // sec-ch-ua-platform on "Linux" while claiming Windows, so its rejection could be either
    // the hint mismatch or something deeper the override cannot reach. This arm isolates it:
    // if this passes, Cloudflare is reading the hints and a non-browser client only has to
    // keep its headers self-consistent. If it still fails, the check is below the HTTP layer
    // — the TLS fingerprint — and no header work can satisfy it.
    'ua-windows-full': (s) => s.send('Emulation.setUserAgentOverride', {
        userAgent: WINDOWS_UA,
        platform: 'Win32',
        acceptLanguage: 'en-US,en;q=0.9',
        userAgentMetadata: {
            brands: [
                { brand: 'Not/A)Brand', version: '99' },
                { brand: 'Chromium', version: '148' },
            ],
            fullVersionList: [
                { brand: 'Not/A)Brand', version: '99.0.0.0' },
                { brand: 'Chromium', version: '148.0.0.0' },
            ],
            fullVersion: '148.0.0.0',
            platform: 'Windows',
            platformVersion: '10.0.0',
            architecture: 'x86',
            bitness: '64',
            model: '',
            mobile: false,
            wow64: false,
        },
    }),
    'touch-enabled': (s) => s.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }),
};

/**
 * One solve with a mutation applied to every target the flow creates.
 *
 * This deliberately does not reuse solveOnce(). The hot path runs with no auto-attach and
 * no extra domains, and that shape is what its measured throughput depends on; bisecting
 * needs to reach the widget's own out-of-process iframe, because that is where the
 * fingerprint is collected and therefore the only place a mutation counts.
 */
async function solveMutated(activeBrowser, siteKey, pageUrl, budgetMs, mutate) {
    const target = new URL(pageUrl).href;
    const context = await activeBrowser.createBrowserContext();

    try {
        const page = await context.newPage();
        const html = Buffer.from(buildPage(siteKey)).toString('base64');

        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

        const cdp = await page.createCDPSession();
        const seen = new Set();

        const apply = async (session) => {
            if (seen.has(session)) {
                return;
            }

            seen.add(session);

            session.on('Target.attachedToTarget', (e) => {
                const connection = session.connection();
                const child = connection ? connection.session(e.sessionId) : null;

                if (child) {
                    apply(child).catch(() => {});
                }
            });

            await session.send('Target.setAutoAttach', {
                autoAttach: true, waitForDebuggerOnStart: true, flatten: true,
            }).catch(() => {});

            // The target is held here, so the mutation is guaranteed to be in place before
            // a single line of Cloudflare's script runs in it.
            await mutate(session).catch(() => {});
            await session.send('Runtime.runIfWaitingForDebugger').catch(() => {});
        };

        await apply(cdp);

        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: cdpEscapeUrl(target), requestStage: 'Request', resourceType: 'Document' }],
        });
        cdp.on('Fetch.requestPaused', ({ requestId }) => {
            cdp
                .send('Fetch.fulfillRequest', {
                    requestId,
                    responseCode: 200,
                    responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
                    body: html,
                })
                .catch(() => {});
        });

        const startedAt = Date.now();
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: budgetMs });

        const handle = await page.waitForFunction(
            () => {
                if (window.__ihcToken) {
                    return { token: window.__ihcToken };
                }

                return window.__ihcError ? { error: window.__ihcError } : false;
            },
            { timeout: Math.max(1000, budgetMs - (Date.now() - startedAt)), polling: 100 },
        );

        const result = await handle.jsonValue();

        if (result.error) {
            throw new Error(`turnstile error-callback: ${result.error}`);
        }

        return result.token;
    } finally {
        await context.close().catch(() => {});
    }
}

/**
 * Run one arm: `samples` independent solves under a single mutation.
 *
 * Samples run sequentially. The success rate being measured sits around 94% and moves with
 * local queueing, so overlapping arms would confound a real rejection with contention.
 */
async function runArm(name, mutate, siteKey, pageUrl, samples) {
    let solved = 0;
    const errors = [];

    for (let i = 0; i < samples; i++) {
        const slot = leaseSlot();

        try {
            const activeBrowser = await slot.get();
            await solveMutated(activeBrowser, siteKey, pageUrl, ATTEMPT_MS, mutate);
            solved++;
        } catch (e) {
            errors.push(String(e.message).slice(0, 120));
        } finally {
            slot.leases--;
        }
    }

    return {
        arm: name,
        samples,
        solved,
        rate: Math.round((solved / samples) * 100),
        errors: [...new Set(errors)].slice(0, 3),
    };
}

/**
 * Bisect the fingerprint: a baseline arm plus one arm per mutation, all against the live
 * widget. The verdict is relative to the baseline measured in the same run, because the
 * unmutated rate itself drifts with host load.
 */
async function bisect(siteKey, pageUrl, samples, names) {
    const selected = names.filter((name) => MUTATIONS[name]);
    const baseline = await runArm('baseline', async () => {}, siteKey, pageUrl, samples);
    const arms = [];

    for (const name of selected) {
        const arm = await runArm(name, MUTATIONS[name], siteKey, pageUrl, samples);

        arm.delta = arm.rate - baseline.rate;
        // A signal Cloudflare checks does not degrade the rate, it collapses it — the
        // positive control goes to zero. Half the baseline is well clear of the ~5% noise
        // an unmutated arm shows.
        arm.checked = arm.rate <= Math.max(0, baseline.rate / 2);
        arms.push(arm);
        log(`bisect ${name}: ${arm.solved}/${samples} (${arm.rate}%, baseline ${baseline.rate}%)`);
    }

    return {
        captured_at: new Date().toISOString(),
        site_key: siteKey,
        page_url: pageUrl,
        samples_per_arm: samples,
        baseline,
        arms,
        checked: arms.filter((a) => a.checked).map((a) => a.arm),
        ignored: arms.filter((a) => !a.checked).map((a) => a.arm),
    };
}

// ---------------------------------------------------------------------------
// Fleet mode — pull work from the portal
// ---------------------------------------------------------------------------
//
// Enabled by CAPTCHA_NODE_KEY, mirroring how the Java bot enters distributed mode on
// args[0]. The node polls the portal for work rather than exposing a port, so a solver VPS
// needs nothing inbound and this server can stay bound to loopback.
//
// The loopback HTTP surface keeps running either way: /health, /trace and /bisect are how
// the portal inspects a node, and they must not depend on fleet mode being on.

const NODE_KEY = process.env.CAPTCHA_NODE_KEY || '';
const PORTAL_URL = (process.env.CAPTCHA_PORTAL_URL || 'https://ipms.senda.fit').replace(/\/+$/, '');
const NODE_SERVICE = process.env.CAPTCHA_NODE_SERVICE || 'ipms-captcha-node';
// The portal host runs this file straight out of the repo, where it is the source of truth.
// Only an installed copy under /opt may overwrite itself, so the installer opts in.
const SELF_UPDATE = process.env.CAPTCHA_NODE_SELF_UPDATE === '1';

const HEARTBEAT_INTERVAL_MS = 10_000;
// Poll cadence when the queue is empty. Deliberately a short poll rather than a long one:
// N nodes each holding a PHP-FPM child open for seconds would compete with the bot's own
// captcha polling during the booking window, and 200ms is nothing against a ~2.5s solve.
const LEASE_IDLE_MS = 200;
const LEASE_QUIET_MS = 1000;
const LEASE_QUIET_AFTER_MS = 5000;
const LEASE_ERROR_MS = 2000;

/** Content hash of this exact file — the portal compares it to what it ships. */
const SCRIPT_VERSION = (() => {
    try {
        return crypto.createHash('sha256').update(fs.readFileSync(__filename)).digest('hex').slice(0, 12);
    } catch (e) {
        return 'unknown';
    }
})();

const fleet = {
    paused: false,
    inFlight: 0,
    lastWorkAt: 0,
    pendingResults: [],
    flushing: false,
    stopped: false,
};

function portalUrl(pathname) {
    return `${PORTAL_URL}${pathname}`;
}

/**
 * One JSON call to the portal. Returns the parsed body, or throws with the status so the
 * caller can distinguish "portal said no" from "portal is unreachable".
 */
async function portalCall(method, pathname, body, timeoutMs = 15_000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const res = await fetch(portalUrl(pathname), {
            method,
            headers: {
                Authorization: `Bearer ${NODE_KEY}`,
                Accept: 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });

        const text = await res.text();

        if (!res.ok) {
            throw new HttpError(res.status, `portal ${method} ${pathname} -> ${res.status} ${text.slice(0, 200)}`);
        }

        return text ? JSON.parse(text) : {};
    } finally {
        clearTimeout(timer);
    }
}

/**
 * Take a solve slot, run it, and keep the lifetime counters the portal reports on.
 * Shared by the loopback /solve endpoint and the fleet loop so both obey the same gate.
 */
async function performSolve(siteKey, pageUrl, timeoutMs) {
    await acquire();
    const startedAt = Date.now();

    try {
        const { token, attempts } = await solve(siteKey, pageUrl, timeoutMs);
        const ms = Date.now() - startedAt;

        stats.solved++;
        stats.totalMs += ms;
        stats.lastSolvedAt = new Date().toISOString();

        return { token, ms, attempts };
    } catch (e) {
        stats.failed++;
        stats.lastError = { at: new Date().toISOString(), message: String(e.message).slice(0, 300) };

        throw e;
    } finally {
        release();
    }
}

/**
 * Ship completed work back. Results are queued and coalesced rather than blocking a solve
 * slot on the POST, and a flush already in flight absorbs whatever landed meanwhile.
 */
async function flushResults() {
    if (fleet.flushing || fleet.pendingResults.length === 0) {
        return;
    }

    fleet.flushing = true;

    try {
        while (fleet.pendingResults.length > 0) {
            const batch = fleet.pendingResults.splice(0, 32);

            try {
                await portalCall('POST', '/api/captcha-nodes/result', { results: batch });
            } catch (e) {
                // Put the batch back and let the next flush retry. The portal's lease reaper
                // requeues anything we never manage to report, so nothing is lost either way.
                fleet.pendingResults.unshift(...batch);
                log(`result flush failed: ${e.message}`);

                return;
            }
        }
    } finally {
        fleet.flushing = false;
    }
}

function reportResult(result) {
    fleet.pendingResults.push(result);
    flushResults().catch((e) => log(`result flush error: ${e.message}`));
}

/** Solve one leased item and report it. Never throws — a failure is a reportable outcome. */
async function runLeasedItem(item) {
    fleet.inFlight++;

    try {
        const { token, ms, attempts } = await performSolve(item.site_key, item.page_url, item.timeout_ms);

        reportResult({ request_id: item.request_id, token, ms, attempts });
    } catch (e) {
        reportResult({ request_id: item.request_id, error: String(e && e.message ? e.message : e).slice(0, 300) });
    } finally {
        fleet.inFlight--;
    }
}

/**
 * Ask the portal for as much work as this node can actually start right now.
 *
 * Capacity is measured against in-flight fleet work rather than the gate's `active`, so we
 * never lease more than we can run and leave it ageing towards its lease expiry in a queue.
 *
 * @return {number} how many items were taken.
 */
async function leaseOnce() {
    if (fleet.paused) {
        return 0;
    }

    const capacity = currentConcurrency - fleet.inFlight;

    if (capacity < 1) {
        return 0;
    }

    const response = await portalCall('POST', '/api/captcha-nodes/lease', { capacity });
    const work = Array.isArray(response.work) ? response.work : [];

    for (const item of work) {
        if (!item || typeof item.request_id !== 'string' || !SITE_KEY_PATTERN.test(String(item.site_key || ''))) {
            log(`skipping malformed work item: ${JSON.stringify(item).slice(0, 120)}`);

            continue;
        }

        // Deliberately not awaited: each item runs concurrently up to the gate's limit.
        runLeasedItem({
            request_id: item.request_id,
            site_key: item.site_key,
            page_url: item.page_url,
            timeout_ms: item.timeout_ms || DEFAULT_TIMEOUT_MS,
        });
    }

    if (work.length > 0) {
        fleet.lastWorkAt = Date.now();
    }

    return work.length;
}

async function applyCommand(command) {
    if (!command) {
        return;
    }

    log(`command received: ${command}`);

    if (command.startsWith('set_concurrency:')) {
        setConcurrency(command.split(':')[1]);

        return;
    }

    if (command === 'pause') {
        fleet.paused = true;

        return;
    }

    if (command === 'resume') {
        fleet.paused = false;

        return;
    }

    if (command === 'restart_browsers') {
        await Promise.all(pool.map((s) => s.close()));
        log('chrome pool restarted by portal command');

        return;
    }

    // Counters are process-lifetime, so a portal-side wipe alone is undone by the next
    // heartbeat re-reporting these numbers. Deliberately leaves startedAt alone — uptime is
    // not a solve statistic.
    if (command === 'reset_stats') {
        stats.solved = 0;
        stats.failed = 0;
        stats.attempts = 0;
        stats.totalMs = 0;
        stats.lastError = null;
        stats.lastSolvedAt = null;
        log('solve counters reset by portal command');

        return;
    }

    if (command === 'update') {
        await selfUpdate();
    }
}

/**
 * Re-download the solver from the portal and restart the unit.
 *
 * Written to a temp file and sanity-checked before it replaces this one: a truncated or
 * error-page download that overwrote the script would take the node off the fleet with no
 * way to push a fix back to it.
 */
/**
 * Hide /dev/dri from this service if the host exposes a real GPU.
 *
 * On a GPU host, Chrome running as root wedges its renderer on the first canvas draw and
 * Cloudflare's fingerprint never completes — the node reports zero solves with no error
 * and no 403, which is indistinguishable from IP reputation until you look for the device
 * nodes. --disable-gpu does not prevent it; only hiding the devices does.
 *
 * Written as a drop-in rather than an edit to the unit so a later installer run, which
 * rewrites the unit wholesale, cannot silently drop it. Applied at startup so a node that
 * was installed before this existed heals itself on its next update instead of needing SSH.
 *
 * Returns true when a restart was issued, meaning the caller should stop starting up.
 */
function ensureDevicesHidden() {
    // The portal's own service is managed from the checkout's drop-in directory, and it is
    // not the thing this repairs.
    if (!SELF_UPDATE) {
        return false;
    }

    let hasGpu = false;

    try {
        hasGpu = fs.existsSync('/dev/dri') && fs.readdirSync('/dev/dri').length > 0;
    } catch {
        return false;
    }

    if (!hasGpu) {
        return false;
    }

    const dir = `/etc/systemd/system/${NODE_SERVICE}.service.d`;
    const file = path.join(dir, 'private-devices.conf');

    // Once the drop-in is active the devices are invisible to this process, so the check
    // above stops firing on its own. The file test is still the guard that matters: it is
    // what makes a failed restart not turn into a loop.
    if (fs.existsSync(file)) {
        return false;
    }

    try {
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(file, '[Service]\nPrivateDevices=yes\n');
    } catch (e) {
        log(`gpu guard: could not write ${file}: ${e.message}`);

        return false;
    }

    log(`gpu guard: /dev/dri present, applied PrivateDevices=yes and restarting ${NODE_SERVICE}`);

    execFile('systemctl', ['daemon-reload'], () => {
        execFile('systemctl', ['restart', NODE_SERVICE], (e) => {
            if (e) {
                log(`gpu guard: restart failed: ${e.message}`);
            }
        });
    });

    return true;
}

async function selfUpdate() {
    if (!SELF_UPDATE) {
        log('update ignored: this node runs from the portal checkout, which is the source of truth');

        return;
    }

    const res = await fetch(portalUrl('/api/captcha-nodes/script'), {
        headers: { Authorization: `Bearer ${NODE_KEY}` },
    });

    if (!res.ok) {
        log(`update failed: portal returned ${res.status}`);

        return;
    }

    const body = await res.text();

    if (body.length < 10_000 || !body.includes('CAPTCHA_NODE_KEY')) {
        log(`update rejected: downloaded script looks wrong (${body.length} bytes)`);

        return;
    }

    const version = crypto.createHash('sha256').update(body).digest('hex').slice(0, 12);

    if (version === SCRIPT_VERSION) {
        log('update skipped: already running this version');

        return;
    }

    const tmp = `${__filename}.tmp`;
    fs.writeFileSync(tmp, body);
    fs.renameSync(tmp, __filename);

    log(`updated ${SCRIPT_VERSION} -> ${version}, restarting ${NODE_SERVICE}`);

    execFile('systemctl', ['restart', NODE_SERVICE], (e) => {
        if (e) {
            log(`restart failed: ${e.message}`);
        }
    });
}

function workerState() {
    if (fleet.paused) {
        return 'paused';
    }

    return fleet.inFlight > 0 || active > 0 ? 'solving' : 'idle';
}

async function heartbeatOnce() {
    const response = await portalCall('POST', '/api/captcha-nodes/heartbeat', {
        worker_state: workerState(),
        hostname: os.hostname(),
        script_version: SCRIPT_VERSION,
        cpu_cores: os.cpus().length,
        reported_concurrency: currentConcurrency,
        active: fleet.inFlight,
        queued: waiters.length,
        solved: stats.solved,
        failed: stats.failed,
        avg_ms: stats.solved > 0 ? Math.round(stats.totalMs / stats.solved) : 0,
        last_error: stats.lastError ? stats.lastError.message : null,
    });

    // The portal's stored value wins so a retune survives a node restart, which would
    // otherwise silently revert to whatever the systemd unit sized it to.
    if (response.desired_concurrency) {
        setConcurrency(response.desired_concurrency);
    }

    await applyCommand(response.pending_command);
}

function startFleetMode() {
    log(`fleet mode: portal ${PORTAL_URL}, script ${SCRIPT_VERSION}, concurrency ${currentConcurrency}`);

    const heartbeatLoop = async () => {
        while (!fleet.stopped) {
            try {
                await heartbeatOnce();
            } catch (e) {
                log(`heartbeat failed: ${e.message}`);
            }

            await sleep(HEARTBEAT_INTERVAL_MS);
        }
    };

    const leaseLoop = async () => {
        while (!fleet.stopped) {
            let delay = LEASE_IDLE_MS;

            try {
                const taken = await leaseOnce();

                if (taken > 0) {
                    // Work is flowing — come straight back for more rather than sleeping
                    // while nodes elsewhere pick up the rest of the queue.
                    delay = 0;
                } else if (Date.now() - fleet.lastWorkAt > LEASE_QUIET_AFTER_MS) {
                    delay = LEASE_QUIET_MS;
                }
            } catch (e) {
                log(`lease failed: ${e.message}`);
                delay = LEASE_ERROR_MS;
            }

            if (delay > 0) {
                await sleep(delay);
            }
        }
    };

    heartbeatLoop();
    leaseLoop();
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// ---------------------------------------------------------------------------
// HTTP surface
// ---------------------------------------------------------------------------

const server = http.createServer(async (req, res) => {
    if (!isAuthorized(req)) {
        return sendJson(res, 401, { error: 'unauthorized' });
    }
    try {
        if (req.method === 'GET' && req.url === '/health') {
            const live = pool.filter((s) => s.browser && s.browser.connected);

            return sendJson(res, 200, {
                ok: true,
                // An idle-reaped pool is healthy, not down: it relaunches on the next solve.
                // Reporting "down" here would have the portal show a red status for a service
                // that is simply costing nothing.
                chrome: live.length > 0 ? 'up' : (IDLE_MS > 0 ? 'idle' : 'down'),
                pool: {
                    concurrency: currentConcurrency,
                    idle_ms: IDLE_MS,
                    idle_for_s: Math.round((Date.now() - lastActivityAt) / 1000),
                    prewarm: PREWARM,
                    rss_mb: Math.round(process.memoryUsage().rss / 1048576),
                    active,
                    queued: waiters.length,
                    max_queue: MAX_QUEUE,
                    solves_since_launch: pool.reduce((sum, s) => sum + s.solves, 0),
                    recycle_after: RECYCLE_AFTER,
                    browsers: pool.length,
                    browsers_up: live.length,
                    per_browser: pool.map((s) => ({
                        index: s.index,
                        up: Boolean(s.browser && s.browser.connected),
                        leases: s.leases,
                        solves: s.solves,
                        draining: s.draining,
                    })),
                },
                stats: {
                    ...stats,
                    uptime_s: Math.round((Date.now() - stats.startedAt) / 1000),
                    avg_ms: stats.solved > 0 ? Math.round(stats.totalMs / stats.solved) : null,
                },
                fleet: {
                    enabled: NODE_KEY !== '',
                    portal: NODE_KEY !== '' ? PORTAL_URL : null,
                    paused: fleet.paused,
                    in_flight: fleet.inFlight,
                    pending_results: fleet.pendingResults.length,
                    script_version: SCRIPT_VERSION,
                    self_update: SELF_UPDATE,
                },
                host: os.hostname(),
            });
        }

        if (req.method === 'POST' && req.url === '/restart') {
            await Promise.all(pool.map((s) => s.close()));

            // Re-warm immediately so the pool is ready for the next solve rather than
            // making whichever request arrives first pay the cold-start cost.
            await Promise.all(pool.map((s) => s.get()));
            log(`chrome pool restarted on request (${pool.length} browsers)`);

            return sendJson(res, 200, { ok: true });
        }

        if (req.method === 'POST' && req.url === '/bisect') {
            const body = JSON.parse((await readBody(req)) || '{}');
            const { siteKey, pageUrl } = parseTargetBody(body, TRACE_DEFAULT_MS);
            const samples = Math.min(20, Math.max(2, parseInt(body.samples, 10) || 6));
            const names = Array.isArray(body.mutations) && body.mutations.length > 0
                ? body.mutations
                : Object.keys(MUTATIONS);

            // A run is (arms + 1) x samples sequential solves and can take minutes. It
            // holds no gate slot of its own — each individual solve leases a browser the
            // normal way — so a bisect degrades live throughput rather than blocking it.
            const report = await bisect(siteKey, pageUrl, samples, names);

            fs.mkdirSync(BISECT_DIR, { recursive: true });
            const name = `${report.captured_at.replace(/[:.]/g, '-')}.json`;
            fs.writeFileSync(path.join(BISECT_DIR, name), JSON.stringify(report, null, 2));

            return sendJson(res, 200, { file: name, ...report });
        }

        if (req.method === 'GET' && req.url === '/mutations') {
            return sendJson(res, 200, { mutations: Object.keys(MUTATIONS) });
        }

        if (req.method === 'POST' && req.url === '/trace') {
            const { siteKey, pageUrl, timeoutMs } = parseTargetBody(
                JSON.parse((await readBody(req)) || '{}'),
                TRACE_DEFAULT_MS,
            );

            await acquire();

            try {
                const captured = await trace(siteKey, pageUrl, timeoutMs);

                // The persisted file carries every body, including a few hundred KB of
                // challenge script. Answer with the shape and the filename; a caller that
                // wants the payload reads the file.
                return sendJson(res, 200, {
                    file: captured.file,
                    captured_at: captured.captured_at,
                    outcome: captured.outcome,
                    summary: captured.summary,
                });
            } finally {
                release();
            }
        }

        if (req.method === 'POST' && req.url === '/solve') {
            const { siteKey, pageUrl, timeoutMs } = parseTargetBody(
                JSON.parse((await readBody(req)) || '{}'),
                DEFAULT_TIMEOUT_MS,
            );

            const { token, ms, attempts } = await performSolve(siteKey, pageUrl, timeoutMs);

            return sendJson(res, 200, { token, ms, attempts });
        }

        return sendJson(res, 404, { error: 'not found' });
    } catch (e) {
        const status = e instanceof HttpError ? e.status : 500;

        return sendJson(res, status, { error: String(e && e.message ? e.message : e) });
    }
});

// Puppeteer surfaces some teardown races (a context closing under an in-flight CDP
// call) as unhandled rejections. They are already handled per-solve by the retry
// loop, so log and stay up rather than letting systemd bounce a healthy process.
process.on('unhandledRejection', (e) => {
    log(`ignored unhandled rejection: ${e && e.message ? e.message : e}`);
});

async function shutdown(signal) {
    if (shuttingDown) {
        return;
    }

    shuttingDown = true;
    log(`${signal} received — shutting down`);
    server.close();

    await Promise.all(pool.map((s) => s.close()));

    // Catches any profile whose close failed. The graceful path is the only one that can do
    // this — an overrunning shutdown gets SIGKILLed and leaves its profiles for the sweep on
    // the next startup.
    const swept = sweepOrphanProfiles();

    if (swept.removed > 0) {
        log(`swept ${swept.removed} orphaned chrome profile(s) on shutdown`);
    }

    process.exit(0);
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// Only bind the port and warm Chrome when run as a service. Requiring this file — which
// is how the test suite reaches the pool and page-building logic — must stay free of
// side effects.
if (require.main === module) {
    // Before anything warms Chrome: on a GPU host every solve below would time out, so a
    // restart now costs one startup instead of a shift of silent zero-token leases.
    if (ensureDevicesHidden()) {
        return;
    }

    server.listen(PORT, HOST, () => {
        log(`listening on http://${HOST}:${PORT} (concurrency ${CONCURRENCY} over ${POOL_SIZE} browsers, attempt cap ${ATTEMPT_MS}ms x${MAX_ATTEMPTS}, idle reap ${IDLE_MS ? `${IDLE_MS}ms` : 'off'}, prewarm ${PREWARM ? 'on' : 'off'})`);

        // Startup is the only reliable place for this: a SIGKILLed shutdown leaks its profiles
        // unconditionally, and by definition cannot clean up after itself.
        const swept = sweepOrphanProfiles();

        if (swept.removed > 0) {
            log(`swept ${swept.removed} orphaned chrome profile(s), ${Math.round(swept.bytes / 1048576)} MB (${swept.kept} still in use)`);
        }

        startIdleReaper();

        if (PREWARM) {
            // Pay the cold-start cost now so the first real request is served warm.
            pool.forEach((s) => s.get().catch((e) => log(`initial chrome #${s.index} launch failed: ${e.message}`)));
        }

        if (NODE_KEY) {
            startFleetMode();
        } else {
            log('fleet mode off (no CAPTCHA_NODE_KEY) — loopback /solve only');
        }
    });
}

module.exports = {
    BrowserSlot,
    buildPage,
    cdpEscapeUrl,
    classifyUrl,
    ensureDevicesHidden,
    fleet,
    isReapable,
    leaseSlot,
    parseTargetBody,
    idealBrowsers,
    pool,
    resizePool,
    setConcurrency,
    summariseTrace,
    sweepOrphanProfiles,
    workerState,
    CONCURRENCY,
    IDLE_MS,
    POOL_SIZE,
    SCRIPT_VERSION,
    TRACE_DIR,
    getConcurrency: () => currentConcurrency,
};
