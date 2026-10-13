<?php

use App\Http\Controllers\Api\AccountBotSetupController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountSessionController;
use App\Http\Controllers\Api\AgentSlotController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotLogIngestController;
use App\Http\Controllers\Api\BypassIpController;
use App\Http\Controllers\Api\CaptchaAlgorithmController;
use App\Http\Controllers\Api\CaptchaControlController;
use App\Http\Controllers\Api\CaptchaNodeController;
use App\Http\Controllers\Api\CaptchaProviderController;
use App\Http\Controllers\Api\CaptchaRequestController;
use App\Http\Controllers\Api\CaptchaTokenController;
use App\Http\Controllers\Api\ConfigExportController;
use App\Http\Controllers\Api\DbBotLogsController;
use App\Http\Controllers\Api\DocumentationController;
use App\Http\Controllers\Api\GmailController;
use App\Http\Controllers\Api\GmailWebhookController;
use App\Http\Controllers\Api\InHouseCaptchaController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PaymentLinkController;
use App\Http\Controllers\Api\PreStagedSessionController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VpsManagerController;
use Illuminate\Support\Facades\Route;

// Gmail Pub/Sub webhook — no auth, verified by Google token in body
Route::post('/webhooks/gmail', [GmailWebhookController::class, 'handle']);

// Public read-only — bypass IPs monitor page (no auth required)
Route::get('/bypass-ips-public', [BypassIpController::class, 'publicIndex']);

// JAR download — authenticated by slot api_key Bearer token, no session auth needed.
Route::get('/bot/jar', [BotController::class, 'downloadJar']);

Route::post('/login', [AuthController::class, 'login']);
Route::get('/captcha/get', [CaptchaTokenController::class, 'consume']);
Route::get('/documentation', [DocumentationController::class, 'index']);
Route::get('/documentation/{filename}', [DocumentationController::class, 'show'])->where('filename', '[^/]+');
Route::get('/documentation/{filename}/download', [DocumentationController::class, 'download'])->where('filename', '[^/]+');
Route::get('/captcha/bot-providers', [CaptchaTokenController::class, 'botProviders']);
Route::post('/captcha/bot-store', [CaptchaTokenController::class, 'botStore']);
Route::get('/config', \App\Http\Controllers\Api\PublicConfigController::class);
Route::get('/server-time', fn () => response()->json([
    'serverTimeMs' => (int) (microtime(true) * 1000),
]));

// Window fire signal — bots poll this every 100-200ms starting ~30s before window_start_time.
// Returns { fire: true } exactly at window_start_time; portal clock is authoritative.
Route::get('/window/status', function () {
    $setting = \App\Models\Setting::instance();
    $windowStartTime = $setting->window_start_time ?? '00:00:00';
    $now = now();
    $windowToday = \Carbon\Carbon::createFromFormat('H:i:s', $windowStartTime, $now->timezone)->setDateFrom($now);
    $fire = $now->greaterThanOrEqualTo($windowToday);

    return response()->json([
        'fire' => $fire,
        'serverTimeMs' => (int) (microtime(true) * 1000),
        'windowStartTime' => $windowStartTime,
    ]);
});

// Centralized captcha solving — open API, no auth required
// Centralized captcha solving — machine callers authenticate with
// `Authorization: Bearer <CAPTCHA_API_TOKEN>`; a signed-in admin reaches the same
// endpoints through the session, which is how the in-app API testers use them.
Route::middleware('captcha_api')->group(function () {
    Route::post('/captcha/request', [CaptchaRequestController::class, 'store']);
    Route::get('/captcha/request/{requestId}', [CaptchaRequestController::class, 'show']);
});

// Captcha solver fleet — Bearer node api_key, no session middleware. Nodes pull work from
// here instead of exposing a port, so a solver VPS needs nothing inbound.
Route::post('/captcha-nodes/heartbeat', [CaptchaNodeController::class, 'heartbeat']);
Route::post('/captcha-nodes/lease', [CaptchaNodeController::class, 'lease']);
Route::post('/captcha-nodes/result', [CaptchaNodeController::class, 'result']);
Route::get('/captcha-nodes/script', [CaptchaNodeController::class, 'script']);
Route::get('/captcha-nodes/provisioning', [CaptchaNodeController::class, 'provisioning']);

// Slot-authenticated public endpoints — Bearer slot api_key, no session middleware
Route::post('/slots/heartbeat', [AgentSlotController::class, 'heartbeat']);
Route::get('/slots/command', [AgentSlotController::class, 'getCommand']);
Route::post('/slots/logs', [BotLogIngestController::class, 'store']);
Route::get('/otp/{phone}', [OtpController::class, 'show']);
Route::get('/captcha/control/status', [CaptchaControlController::class, 'status'])
    ->middleware(['web', 'auth', 'can:bot.manage']);

// Config export — accessible via slot api_key Bearer token OR authenticated user session
Route::get('/config/export', ConfigExportController::class);

Route::post('/payment-links', [PaymentLinkController::class, 'store']);
Route::post('/payment-links/list', [PaymentLinkController::class, 'publicList']);
Route::get('/payment-callback', [PaymentLinkController::class, 'pollCallback']);
Route::post('/payment-callback/result', [PaymentLinkController::class, 'reportCallbackResult']);
Route::match(['get', 'post'], '/payment-links/redirect-url', [PaymentLinkController::class, 'ingestCallbackUrl']);
// Auto-payment driver OTP feed — authenticated by a per-run, per-wallet ticket, not session auth.
Route::get('/payment-otp/{phone}', [\App\Http\Controllers\Api\PaymentOtpController::class, 'show']);
Route::post('/account-sessions', [AccountSessionController::class, 'store']);
Route::get('/account-sessions/request-id', [AccountSessionController::class, 'latestRequestId']);

// Pre-stage OTP state for the Java bot. Init and OTP updates are bot callbacks and do not
// use session authentication; listing and deletion remain in the authenticated routes below.
Route::post('/pre-staged-sessions/init', [PreStagedSessionController::class, 'init']);
Route::patch('/pre-staged-sessions/otp', [PreStagedSessionController::class, 'updateOtp']);
Route::patch('/pre-staged-sessions/clear-otp', [PreStagedSessionController::class, 'clearOtp']);

// Bot account setup — slot api_key Bearer auth. PDFs delivered here (not in /api/config);
// one-time setup flags reported back by the bot.
Route::get('/accounts/{account}/pdfs', [AccountBotSetupController::class, 'pdfs']);
Route::post('/accounts/setup-state', [AccountBotSetupController::class, 'setupState']);
Route::post('/accounts/appointment-dates', [AccountBotSetupController::class, 'appointmentDates']);
Route::post('/accounts/pdf-profile-sync', [AccountBotSetupController::class, 'pdfProfileSync']);

// The admin SPA calls these session-authenticated endpoints with Axios. Include the web
// middleware explicitly because the API group does not start the Laravel session by default.
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/settings', [SettingController::class, 'update']);

    Route::get('/otps/count', [\App\Http\Controllers\Api\OtpsController::class, 'count']);
    Route::get('/otps', [\App\Http\Controllers\Api\OtpsController::class, 'index']);
    Route::post('/otps', [\App\Http\Controllers\Api\OtpsController::class, 'store']);
    Route::delete('/otps', [\App\Http\Controllers\Api\OtpsController::class, 'destroy']);
    Route::delete('/otps/non-ivacbd', [\App\Http\Controllers\Api\OtpsController::class, 'destroyNonIvacbd']);

    Route::get('captcha-providers/{captchaProvider}/balance', [CaptchaProviderController::class, 'balance']);
    Route::post('captcha-providers/bulk-status', [CaptchaProviderController::class, 'bulkStatus']);
    Route::apiResource('captcha-providers', CaptchaProviderController::class);
    Route::get('captcha-tokens/count', [CaptchaTokenController::class, 'count']);
    Route::post('captcha-tokens/store-solved', [CaptchaTokenController::class, 'storeSolved']);
    Route::apiResource('captcha-tokens', CaptchaTokenController::class);
    Route::put('accounts/bulk-assign', [AccountController::class, 'bulkAssign']);
    Route::put('accounts/bulk-setup-state', [AccountController::class, 'bulkSetupState']);
    Route::put('accounts/bulk-appointment-dates', [AccountController::class, 'bulkAppointmentDates']);
    Route::put('accounts/bulk-rearm-auto-payment', [AccountController::class, 'bulkRearmAutoPayment']);
    // Registered before the resource so PUT/POST accounts/{account} cannot swallow it.
    Route::post('accounts/{account}/rearm-auto-payment', [AccountController::class, 'rearmAutoPayment']);
    Route::post('accounts/pdfs/inspect', [AccountController::class, 'inspectPdf']);
    // Registered before the resource so GET accounts/{account} cannot swallow it.
    Route::get('accounts/live-states', [AccountController::class, 'liveStates']);
    Route::apiResource('accounts', AccountController::class);

    Route::get('/payment-links', [PaymentLinkController::class, 'index']);
    Route::get('/payment-links/phones', [PaymentLinkController::class, 'phones']);
    Route::get('/payment-links/phone-links', [PaymentLinkController::class, 'phoneLinks']);
    Route::get('/payment-links/phone-invoices', [PaymentLinkController::class, 'phoneInvoices']);
    Route::get('/payment-links/visa-types', [PaymentLinkController::class, 'visaTypes']);
    Route::get('/payment-links/invoice', [PaymentLinkController::class, 'invoiceDownload']);
    Route::get('/payment-links/invoices-zip', [PaymentLinkController::class, 'invoicesZip']);
    Route::patch('/payment-links/{payment_link}/clicked', [PaymentLinkController::class, 'markClicked']);
    Route::patch('/payment-links/{payment_link}/review-status', [PaymentLinkController::class, 'updateReviewStatus']);
    Route::patch('/payment-links/{payment_link}/visa-info', [PaymentLinkController::class, 'updateVisaInfo']);
    Route::post('/payment-links/callback-url', [PaymentLinkController::class, 'submitCallbackUrl']);
    Route::get('/payment-links/recent-callbacks', [PaymentLinkController::class, 'recentCallbacks']);
    Route::delete('/payment-links/{payment_link}', [PaymentLinkController::class, 'destroy']);

    Route::post('/captcha-proxy/create', [\App\Http\Controllers\Api\CaptchaProxyController::class, 'createTask']);
    Route::post('/captcha-proxy/result', [\App\Http\Controllers\Api\CaptchaProxyController::class, 'getTaskResult']);

    // Must precede the apiResource so it is not swallowed by /users/{user}.
    Route::get('/users/options', [UserController::class, 'options']);
    Route::post('/users/{user}/approve', [UserController::class, 'approve']);
    Route::post('/users/{user}/unapprove', [UserController::class, 'unapprove']);
    Route::delete('/users/{user}/reject', [UserController::class, 'reject']);
    Route::apiResource('users', UserController::class)->except(['show']);

    Route::get('/account-sessions', [AccountSessionController::class, 'index']);
    Route::delete('/account-sessions/{account_session}', [AccountSessionController::class, 'destroy']);
    Route::get('/pre-staged-sessions', [PreStagedSessionController::class, 'index']);
    Route::delete('/pre-staged-sessions/{pre_staged_session}', [PreStagedSessionController::class, 'destroy']);

    // Personal wallet book (Rocket/Nagad numbers + PIN) — scoped to the requesting user inside
    // WalletController itself, not a Gate: nobody but the owner ever reads or writes a row.
    Route::apiResource('wallets', \App\Http\Controllers\Api\WalletController::class);

    // The header notices are read by everyone off the shared Inertia prop; only the
    // authors need these endpoints.
    Route::middleware('can:notice.write')->group(function () {
        Route::get('/notices', [\App\Http\Controllers\Api\NoticeController::class, 'index']);
        Route::post('/notices', [\App\Http\Controllers\Api\NoticeController::class, 'store']);
        Route::put('/notices/{notice}', [\App\Http\Controllers\Api\NoticeController::class, 'update']);
        Route::delete('/notices/{notice}', [\App\Http\Controllers\Api\NoticeController::class, 'destroy']);
    });

    Route::middleware('can:api-tester.access')->group(function () {
        Route::get('/api-tester/context', [\App\Http\Controllers\Api\ApiTesterController::class, 'context']);
        Route::post('/api-tester/parse-signup-pdf', [\App\Http\Controllers\Api\ApiTesterController::class, 'parseSignupPdf']);
        Route::post('/api-tester/signup-send-otp', [\App\Http\Controllers\Api\ApiTesterController::class, 'signupSendOtp']);
        Route::post('/api-tester/signup-verify-otp', [\App\Http\Controllers\Api\ApiTesterController::class, 'signupVerifyOtp']);
        Route::post('/api-tester/signup', [\App\Http\Controllers\Api\ApiTesterController::class, 'signup']);
        Route::get('/api-tester/fetch-otp-by-phone', [\App\Http\Controllers\Api\ApiTesterController::class, 'fetchOtpForPhone']);
        Route::post('/api-tester/signin', [\App\Http\Controllers\Api\ApiTesterController::class, 'signin']);
        Route::post('/api-tester/send-otp', [\App\Http\Controllers\Api\ApiTesterController::class, 'sendOtp']);
        Route::post('/api-tester/verify-otp', [\App\Http\Controllers\Api\ApiTesterController::class, 'verifyOtp']);
        Route::post('/api-tester/signin-manual', [\App\Http\Controllers\Api\ApiTesterController::class, 'signinManual']);
        Route::post('/api-tester/signin-and-verify-otp', [\App\Http\Controllers\Api\ApiTesterController::class, 'signinAndVerifyOtp']);
        Route::post('/api-tester/create-appointment', [\App\Http\Controllers\Api\ApiTesterController::class, 'createAppointment']);
        Route::post('/api-tester/booking-config', [\App\Http\Controllers\Api\ApiTesterController::class, 'bookingConfig']);
        Route::post('/api-tester/reserve-slot', [\App\Http\Controllers\Api\ApiTesterController::class, 'reserveSlot']);
        Route::post('/api-tester/initiate-payment', [\App\Http\Controllers\Api\ApiTesterController::class, 'initiatePayment']);
        Route::post('/api-tester/payment-callback', [\App\Http\Controllers\Api\ApiTesterController::class, 'paymentCallback']);
        Route::get('/api-tester/fetch-otp/{accountId}', [\App\Http\Controllers\Api\ApiTesterController::class, 'fetchOtp']);
        Route::get('/api-tester/session/{accountId}', [\App\Http\Controllers\Api\ApiTesterController::class, 'getSession']);
        Route::put('/api-tester/session/{accountId}', [\App\Http\Controllers\Api\ApiTesterController::class, 'updateSession']);
        Route::post('/api-tester/file-overview', [\App\Http\Controllers\Api\ApiTesterController::class, 'fileOverview']);
        Route::post('/api-tester/edit-pdf', [\App\Http\Controllers\Api\ApiTesterController::class, 'editPdf']);
        Route::post('/api-tester/upload-pdf', [\App\Http\Controllers\Api\ApiTesterController::class, 'uploadPdf']);
        Route::post('/api-tester/upload-account-pdf', [\App\Http\Controllers\Api\ApiTesterController::class, 'uploadAccountPdf']);
        Route::post('/api-tester/set-booking-config', [\App\Http\Controllers\Api\ApiTesterController::class, 'setBookingConfig']);
        Route::put('/api-tester/pdf-profile', [\App\Http\Controllers\Api\ApiTesterController::class, 'savePdfProfile']);
        Route::post('/api-tester/worker-signin', [\App\Http\Controllers\Api\ApiTesterController::class, 'workerSignin']);
    });

    // Read-only worker slot list: available to managers via accounts.assign, unlike the
    // rest of this file's admin-only bot.manage surface.
    Route::middleware('can:accounts.assign')->group(function () {
        Route::get('agent-slots', [AgentSlotController::class, 'index']);
    });

    Route::middleware('can:bot.manage')->group(function () {
        Route::post('/captcha-algorithm/analyze', [CaptchaAlgorithmController::class, 'analyze']);
        Route::get('/captcha-algorithm/progress', [CaptchaAlgorithmController::class, 'progress']);
        Route::get('/captcha-algorithm/history', [CaptchaAlgorithmController::class, 'history']);
        Route::get('/captcha-algorithm/engine', [CaptchaAlgorithmController::class, 'engine']);
        Route::post('/captcha-algorithm/sidecar/reload', [CaptchaAlgorithmController::class, 'reloadSidecar']);
        Route::delete('/captcha-algorithm/snapshots', [CaptchaAlgorithmController::class, 'clearHistory']);
        Route::delete('/captcha-algorithm/snapshots/{id}', [CaptchaAlgorithmController::class, 'deleteSnapshot']);
        Route::get('/captcha-algorithm/versions', [CaptchaAlgorithmController::class, 'versions']);
        Route::post('/captcha-algorithm/versions/{id}/activate', [CaptchaAlgorithmController::class, 'activateVersion']);
        Route::patch('/captcha-algorithm/versions/{id}', [CaptchaAlgorithmController::class, 'labelVersion']);
        Route::delete('/captcha-algorithm/versions/{id}', [CaptchaAlgorithmController::class, 'deleteVersion']);
    });

    Route::middleware('can:bot.manage')->group(function () {
        Route::get('/apk/overview', [\App\Http\Controllers\Api\ApkManagerController::class, 'overview']);
        Route::post('/apk/info', [\App\Http\Controllers\Api\ApkManagerController::class, 'updateInfo']);
        Route::post('/apk/releases', [\App\Http\Controllers\Api\ApkManagerController::class, 'storeRelease']);
        Route::put('/apk/releases/{release}', [\App\Http\Controllers\Api\ApkManagerController::class, 'updateRelease']);
        Route::post('/apk/releases/{release}/activate', [\App\Http\Controllers\Api\ApkManagerController::class, 'activateRelease']);
        Route::delete('/apk/releases/{release}', [\App\Http\Controllers\Api\ApkManagerController::class, 'destroyRelease']);
        Route::post('/apk/screenshots', [\App\Http\Controllers\Api\ApkManagerController::class, 'storeScreenshots']);
        Route::put('/apk/screenshots/reorder', [\App\Http\Controllers\Api\ApkManagerController::class, 'reorderScreenshots']);
        Route::put('/apk/screenshots/{screenshot}', [\App\Http\Controllers\Api\ApkManagerController::class, 'updateScreenshot']);
        Route::delete('/apk/screenshots/{screenshot}', [\App\Http\Controllers\Api\ApkManagerController::class, 'destroyScreenshot']);

        Route::post('agent-slots/command/all', [AgentSlotController::class, 'sendCommandToAll']);
        Route::post('agent-slots/{agentSlot}/command', [AgentSlotController::class, 'sendCommand']);
        Route::delete('agent-slots/offline', [AgentSlotController::class, 'destroyOffline']);
        Route::apiResource('agent-slots', AgentSlotController::class)->except(['index']);
        Route::post('agent-slots/{agentSlot}/regenerate-key', [AgentSlotController::class, 'regenerateKey']);

        Route::get('bypass-ips/count', [BypassIpController::class, 'count']);
        Route::apiResource('bypass-ips', BypassIpController::class);
        Route::post('bypass-ips/{bypassIp}/ping', [BypassIpController::class, 'ping']);
        Route::post('bypass-ips/{bypassIp}/assign', [BypassIpController::class, 'assignToSlot']);
        Route::post('bypass-ips/unassign', [BypassIpController::class, 'unassignFromSlot']);
        Route::post('bypass-ips/batch-check', [BypassIpController::class, 'batchCheck']);
        Route::post('bypass-ips/cleanup', [BypassIpController::class, 'cleanup']);
        Route::post('bypass-ips/cleanup/restore', [BypassIpController::class, 'restoreCleanup']);
        Route::post('bypass-ips/scan/start', [BypassIpController::class, 'startScan']);
        Route::get('bypass-ips/censys/config', [BypassIpController::class, 'censysConfig']);
        Route::post('bypass-ips/censys/config', [BypassIpController::class, 'saveCensysConfig']);
        Route::post('bypass-ips/scan/censys', [BypassIpController::class, 'censysLookup']);
        Route::post('bypass-ips/scan/aws', [BypassIpController::class, 'startAwsScan']);
        Route::post('bypass-ips/scan/aws-ipv6', [BypassIpController::class, 'startAwsIpv6Scan']);
        Route::get('bypass-ips/scan/status', [BypassIpController::class, 'scanStatus']);
        Route::post('bypass-ips/scan/stop', [BypassIpController::class, 'stopScan']);
        Route::delete('bypass-ips/scan/{ipScanSession}/dismiss', [BypassIpController::class, 'dismissScan']);
        Route::post('bypass-ips/scan/pause', [BypassIpController::class, 'pauseScan']);
        Route::post('bypass-ips/scan/resume', [BypassIpController::class, 'resumeScan']);
        Route::post('bypass-ips/scan/frontend', [BypassIpController::class, 'startFrontendScan']);
        Route::post('bypass-ips/batch-probe-frontend', [BypassIpController::class, 'batchProbeFrontend']);
        Route::post('bypass-ips/scan/approve-all', [BypassIpController::class, 'approveAllResults']);
        Route::post('bypass-ips/scan-results/{ipScanResult}/approve', [BypassIpController::class, 'approveResult']);
        Route::post('bypass-ips/scan-results/{ipScanResult}/reject', [BypassIpController::class, 'rejectResult']);

        Route::get('/ivac-endpoints', [\App\Http\Controllers\Api\IvacEndpointController::class, 'index']);
        Route::post('/ivac-endpoints', [\App\Http\Controllers\Api\IvacEndpointController::class, 'update']);
        Route::post('/ivac-endpoints/reset', [\App\Http\Controllers\Api\IvacEndpointController::class, 'reset']);

        Route::delete('/db-bot-logs/purge', [DbBotLogsController::class, 'purge']);
        Route::post('/db-bot-logs/purge-noise', [DbBotLogsController::class, 'purgeNoise']);
        Route::delete('/db-bot-logs/clear', [DbBotLogsController::class, 'clear']);
        Route::delete('/db-bot-logs/destroy-all', [DbBotLogsController::class, 'destroyAll']);
        Route::delete('/db-bot-logs/{id}', [DbBotLogsController::class, 'destroy']);

        // Throttled: each solve occupies a headless-Chrome slot for ~3s, so a stuck
        // UI or an impatient operator should not be able to saturate the pool.
        Route::post('/in-house-captcha/generate', [InHouseCaptchaController::class, 'generate'])
            ->middleware('throttle:30,1');
        Route::get('/in-house-captcha/health', [InHouseCaptchaController::class, 'health']);
        Route::post('/in-house-captcha/restart', [InHouseCaptchaController::class, 'restart']);

        // A trace holds a browser for the whole sequence and writes a ~1 MB file, so it is
        // throttled harder than a plain solve. It is a diagnostic, not a hot path.
        Route::post('/in-house-captcha/trace', [InHouseCaptchaController::class, 'trace'])
            ->middleware('throttle:6,1');
        Route::get('/in-house-captcha/traces', [InHouseCaptchaController::class, 'traces']);
        Route::get('/in-house-captcha/traces/{file}', [InHouseCaptchaController::class, 'showTrace']);
        Route::get('/in-house-captcha/flow', [InHouseCaptchaController::class, 'flow']);

        // A bisect is minutes of live solving against Cloudflare, so it is rate limited to
        // near-manual use and never scheduled.
        Route::post('/in-house-captcha/bisect', [InHouseCaptchaController::class, 'bisect'])
            ->middleware('throttle:2,10');
        Route::get('/in-house-captcha/bisect', [InHouseCaptchaController::class, 'bisectReport']);

        // Solver fleet console. The literal paths must be declared before {captchaNode},
        // otherwise 'command/all' and 'offline' get swallowed by the wildcard — the same
        // ordering trap as agent-slots.
        Route::get('/captcha-nodes', [CaptchaNodeController::class, 'index']);
        Route::post('/captcha-nodes', [CaptchaNodeController::class, 'store']);
        Route::post('/captcha-nodes/command/all', [CaptchaNodeController::class, 'sendCommandToAll']);
        Route::delete('/captcha-nodes/offline', [CaptchaNodeController::class, 'destroyOffline']);
        Route::delete('/captcha-nodes/stats', [CaptchaNodeController::class, 'resetStats']);
        Route::get('/captcha-nodes/test-solve/{requestId}', [CaptchaNodeController::class, 'testSolveStatus']);
        Route::patch('/captcha-nodes/{captchaNode}', [CaptchaNodeController::class, 'update']);
        Route::delete('/captcha-nodes/{captchaNode}', [CaptchaNodeController::class, 'destroy']);
        Route::post('/captcha-nodes/{captchaNode}/command', [CaptchaNodeController::class, 'sendCommand']);
        Route::post('/captcha-nodes/{captchaNode}/regenerate-key', [CaptchaNodeController::class, 'regenerateKey']);
        Route::post('/captcha-nodes/{captchaNode}/test-solve', [CaptchaNodeController::class, 'testSolve']);

        Route::post('/captcha/control/start', [CaptchaControlController::class, 'start']);
        Route::post('/captcha/control/stop', [CaptchaControlController::class, 'stop']);
        Route::put('/captcha/pool-limit', [CaptchaControlController::class, 'poolLimit']);
        Route::put('/captcha/pool-expiry', [CaptchaControlController::class, 'poolExpiry']);
        Route::put('/captcha/slots-per-provider', [CaptchaControlController::class, 'slotsPerProvider']);
        Route::get('/captcha/pool-tokens', [CaptchaControlController::class, 'poolTokens']);
        Route::delete('/captcha/pool-tokens', [CaptchaControlController::class, 'deletePoolTokens']);
        Route::delete('/captcha/pool-tokens/expired', [CaptchaControlController::class, 'deleteExpiredPoolTokens']);

        Route::get('/vps/instances', [VpsManagerController::class, 'index']);
        Route::post('/vps/instances/manual', [VpsManagerController::class, 'storeManual']);
        Route::post('/vps/instances/update-all', [VpsManagerController::class, 'updateAllBots']);
        Route::post('/vps/instances/{vpsInstance}/update', [VpsManagerController::class, 'updateBot']);
        Route::put('/vps/instances/{vpsInstance}/credentials', [VpsManagerController::class, 'updateCredentials']);
        Route::post('/vps/provision', [VpsManagerController::class, 'provision']);

        // Captcha solver on an existing box. The literal 'captcha/...' paths come before
        // the {vpsInstance} ones so the wildcard cannot swallow them.
        Route::post('/vps/captcha/install-all', [VpsManagerController::class, 'installCaptchaAll']);
        Route::post('/vps/captcha/update-all', [VpsManagerController::class, 'updateCaptchaAll']);
        Route::post('/vps/instances/{vpsInstance}/captcha/install', [VpsManagerController::class, 'installCaptcha']);
        Route::post('/vps/instances/{vpsInstance}/captcha/update', [VpsManagerController::class, 'updateCaptcha']);
        Route::delete('/vps/instances/{vpsInstance}/captcha', [VpsManagerController::class, 'removeCaptcha']);
        Route::delete('/vps/instances/{vpsInstance}', [VpsManagerController::class, 'destroy']);
        Route::get('/vps/settings', [VpsManagerController::class, 'getSettings']);
        Route::post('/vps/settings', [VpsManagerController::class, 'saveSettings']);
        Route::get('/vps/discover/regions', [VpsManagerController::class, 'discoverSpecs']);
        Route::get('/vps/discover/plans', [VpsManagerController::class, 'discoverPlans']);
        Route::get('/vps/discover/images', [VpsManagerController::class, 'discoverImages']);

        Route::get('/bot/status', [BotController::class, 'status']);
        Route::get('/bot/commands', [BotController::class, 'commands']);
        Route::get('/bot/setup', [BotController::class, 'setup']);
        Route::get('/bot/compiled', [BotController::class, 'compiled']);
        Route::post('/bot/compile', [BotController::class, 'compile']);
        Route::post('/bot/package', [BotController::class, 'package']);
        Route::get('/bot/jar-status', [BotController::class, 'jarStatus']);
        Route::get('/bot/logs/download', [BotController::class, 'downloadLogs']);
        Route::get('/bot/logs', [BotController::class, 'logs']);
        Route::post('/bot/logs/clear', [BotController::class, 'clearLogs']);
        Route::post('/bot/start', [BotController::class, 'start']);
        Route::post('/bot/stop', [BotController::class, 'stop']);
        Route::post('/bot/kill', [BotController::class, 'kill']);
        Route::post('/bot/release-threads', [BotController::class, 'releaseThreads']);
        Route::get('/bot/processes', [BotController::class, 'processes']);
        Route::post('/bot/processes/{pid}/kill', [BotController::class, 'killProcess']);
    });

    Route::get('/db-bot-logs', [DbBotLogsController::class, 'index']);
    Route::get('/db-bot-logs/phones', [DbBotLogsController::class, 'phones']);
    Route::get('/db-bot-logs/summary', [DbBotLogsController::class, 'summary']);
    Route::get('/db-bot-logs/endpoints', [DbBotLogsController::class, 'endpoints']);
    Route::get('/db-bot-logs/latest-per-account', [DbBotLogsController::class, 'latestPerAccount']);

    Route::get('/gmail/messages', [GmailController::class, 'index']);
    Route::get('/gmail/messages/{id}', [GmailController::class, 'show']);
    Route::post('/gmail/messages/batch-trash', [GmailController::class, 'batchTrash']);
    Route::post('/gmail/messages/batch-delete', [GmailController::class, 'batchDelete']);
    Route::post('/gmail/messages/clear-all', [GmailController::class, 'clearAll']);
});
