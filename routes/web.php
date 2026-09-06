<?php

use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\OtpIngestController;
use App\Http\Controllers\PagePasswordController;
use App\Models\Account;
use App\Models\AgentSlot;
use App\Models\CaptchaProvider;
use App\Models\CaptchaToken;
use App\Models\PaymentLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

/*
 * Private Railway service endpoints. The solver runs in a separate container, so it
 * cannot read the web container's storage volume directly. Keep these routes outside
 * the browser auth middleware and protect them with a shared service secret instead.
 */
$captchaSync = static function (Illuminate\Http\Request $request, string $file) {
    $expected = (string) config('captcha.sync_token');
    $provided = (string) $request->header('X-Captcha-Sync-Token');

    abort_if($expected === '' || $provided === '' || ! hash_equals($expected, $provided), 404);

    $path = storage_path('app/captcha/'.$file);
    abort_unless(is_file($path) && is_readable($path), 503, 'Captcha asset is not ready.');

    return response()->file($path, [
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
    ]);
};

Route::get('internal/captcha/ivac-bundle.js', fn (Illuminate\Http\Request $request) => $captchaSync($request, 'ivac-bundle.js'));
Route::get('internal/captcha/encrypt_meta.json', fn (Illuminate\Http\Request $request) => $captchaSync($request, 'encrypt_meta.json'));

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::get('otp', OtpIngestController::class)->name('otp.ingest');
Route::post('otp', OtpIngestController::class)->name('otp.ingest.post');

Route::get('apk', [\App\Http\Controllers\ApkLandingController::class, 'show'])->name('apk.landing');
Route::get('apk/download', [\App\Http\Controllers\ApkLandingController::class, 'download'])->name('apk.download');

Route::get('install/{apiKey}', [\App\Http\Controllers\InstallScriptController::class, 'show'])->name('install.show');

Route::get('payment-helper', [\App\Http\Controllers\PaymentHelperController::class, 'show'])->name('payment-helper.landing');
Route::get('payment-helper/download', [\App\Http\Controllers\PaymentHelperController::class, 'download'])->name('payment-helper.download');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Illuminate\Http\Request $request) {
        $user = $request->user();
        $today = now()->startOfDay();
        $weekStart = now()->subDays(6)->startOfDay();

        if ($user->isSuperAdmin()) {
            $accountQuery = Account::query();
            $captchaProviderQuery = CaptchaProvider::query();
            $paymentQuery = PaymentLink::query();
            $paymentRaw = DB::table('payment_links as pl');
        } else {
            $accountQuery = Account::where('user_id', $user->id);
            $captchaProviderQuery = CaptchaProvider::where('user_id', $user->id);
            $userPhones = $user->accounts()->pluck('phone');
            $paymentQuery = PaymentLink::whereIn('account_phone', $userPhones);
            $paymentRaw = DB::table('payment_links as pl')->whereIn('pl.account_phone', $userPhones);
        }

        // Counts mirror the /payment-links grouping: each booking is one (phone + date) card,
        // counted once regardless of how many retry links it has. "total" = cards; "declined" =
        // cards whose earliest link is declined (the page's representative status); "applicants"
        // (BGD) = sum of each card's applicant count across ALL statuses.
        $perCardRows = function ($base, bool $byDate) {
            // The account join is aggregated only because the outer GROUP BY demands it: phone is
            // unique on accounts, so each card has exactly one status and MAX() returns that value.
            $isSqlite = DB::getDriverName() === 'sqlite';

            $cardStatusExpr = $isSqlite
                ? '(SELECT pl2.review_status FROM payment_links pl2 WHERE pl2.account_phone = pl.account_phone AND DATE(pl2.created_at) = DATE(pl.created_at) ORDER BY pl2.id ASC LIMIT 1)'
                : "SUBSTRING_INDEX(GROUP_CONCAT(pl.review_status ORDER BY pl.id), ',', 1)";

            $dateExpr = $isSqlite ? "DATE(pl.created_at)" : "DATE(pl.created_at)";

            $sub = $base
                ->leftJoin('account_sessions as s', 's.phone', '=', 'pl.account_phone')
                ->leftJoin('accounts as a', 'a.phone', '=', 'pl.account_phone')
                ->selectRaw("pl.account_phone,"
                    ." {$dateExpr} as date,"
                    ." COALESCE(s.reserved_visa_type, 'UNKNOWN') as visa_type,"
                    ." MAX(s.reserved_applicants) as applicants,"
                    ." MAX(a.status) as account_status,"
                    ." {$cardStatusExpr} as card_status")
                ->groupBy('pl.account_phone', DB::raw("{$dateExpr}"), 'visa_type');

            $agg = "COUNT(*) as total,"
                ." SUM(CASE WHEN card_status = 'declined' THEN 1 ELSE 0 END) as declined,"
                ." SUM(CASE WHEN account_status = 'completed' THEN 1 ELSE 0 END) as completed,"
                ." COALESCE(SUM(applicants), 0) as applicants";

            if ($byDate) {
                return DB::query()->fromSub($sub, 'c')
                    ->selectRaw("date, visa_type, {$agg}")
                    ->groupBy('date', 'visa_type')
                    ->orderBy('date')->orderBy('visa_type')
                    ->get();
            }

            return DB::query()->fromSub($sub, 'c')
                ->selectRaw("visa_type, {$agg}")
                ->groupBy('visa_type')
                ->get();
        };

        $todayByType = $perCardRows((clone $paymentRaw)->where('pl.created_at', '>=', $today), false)
            ->map(fn ($row) => [
                'visa_type' => $row->visa_type,
                'total' => (int) $row->total,
                'declined' => (int) $row->declined,
                'completed' => (int) $row->completed,
                'bookings' => (int) $row->total - (int) $row->declined,
                'applicants' => (int) $row->applicants,
            ]);

        $monthStart = now()->startOfMonth();

        $monthRows = $perCardRows((clone $paymentRaw)->where('pl.created_at', '>=', $monthStart), true)
            ->map(fn ($row) => [
                'date' => $row->date,
                'visa_type' => $row->visa_type,
                'total' => (int) $row->total,
                'declined' => (int) $row->declined,
                'completed' => (int) $row->completed,
                'success' => (int) $row->total - (int) $row->declined,
                'applicants' => (int) $row->applicants,
            ]);

        $allTimeByType = $perCardRows((clone $paymentRaw), false)
            ->map(fn ($row) => [
                'visa_type' => $row->visa_type,
                'total' => (int) $row->total,
                'declined' => (int) $row->declined,
                'completed' => (int) $row->completed,
                'bookings' => (int) $row->total - (int) $row->declined,
                'applicants' => (int) $row->applicants,
            ]);

        // Top-line payment stats are the footer totals of the all-time per-type table, so they must
        // sum the same per-card rows (booking = one phone+date card) instead of counting link rows.
        $allTimeTotalCards = (int) $allTimeByType->sum('total');
        $allTimeDeclined = (int) $allTimeByType->sum('declined');
        $allTimeCompleted = (int) $allTimeByType->sum('completed');
        $allTimeBookings = $allTimeTotalCards - $allTimeDeclined;
        $paymentLinksToday = (int) $todayByType->sum('total');

        return Inertia::render('Dashboard', [
            'stats' => [
                'accounts_count' => (clone $accountQuery)->count(),
                'accounts_active_count' => (clone $accountQuery)->where('is_active', true)->count(),
                'accounts_completed_count' => (clone $accountQuery)->where('status', 'completed')->count(),
                'captcha_providers_count' => $captchaProviderQuery->count(),
                'captcha_tokens_count' => CaptchaToken::count(),
                'payment_links_count' => $allTimeTotalCards,
                'payment_links_today' => $paymentLinksToday,
                'payment_links_total_success_count' => $allTimeTotalCards,
                'payment_links_declined_count' => $allTimeDeclined,
                'payment_links_completed_count' => $allTimeCompleted,
                'payment_links_success_count' => $allTimeBookings,
                'workers_count' => AgentSlot::count(),
                'workers_online_count' => AgentSlot::where('status', 'online')->count(),
            ],
            'todayByType' => $todayByType,
            'monthRows' => $monthRows,
            'allTimeByType' => $allTimeByType,
        ]);
    })->middleware('super_admin')->name('dashboard');

    Route::get('system-configuration', function () {
        return Inertia::render('settings/Index');
    })->middleware('super_admin')->name('settings.index');

    Route::get('captcha-providers', function () {
        return Inertia::render('captcha-providers/Index');
    })->middleware('can:captcha.read')->name('captcha-providers.page');

    Route::get('accounts', function () {
        return Inertia::render('Accounts/Index');
    })->middleware('can:accounts.read')->name('accounts.page');

    Route::get('payment-links', function () {
        return Inertia::render('PaymentLinks/Index');
    })->middleware('can:accounts.read')->name('payment-links.index');

    // Opened in a new tab from the payment-links list.
    Route::get('payment-links/{paymentLink}/automation-log', [\App\Http\Controllers\PaymentAutomationLogController::class, 'show'])
        ->middleware('can:accounts.read')
        ->name('payment-links.automation-log');

    // Manual OTP hand-off for a run whose wallet SIM is not on an SMS forwarder.
    Route::post('payment-links/{paymentLink}/automation-log/otp', [\App\Http\Controllers\PaymentAutomationLogController::class, 'submitOtp'])
        ->middleware('can:accounts.read')
        ->name('payment-links.automation-log.otp');

    Route::get('users', function () {
        return Inertia::render('Users/Index');
    })->middleware('can:users.manage')->name('users.page');

    Route::get('captcha-control', function () {
        return Inertia::render('CaptchaControl/Index');
    })->middleware('super_admin')->name('captcha-control.index');

    Route::get('in-house-captcha', function () {
        $setting = \App\Models\Setting::instance();

        return Inertia::render('InHouseCaptcha/Index', [
            'siteKey' => $setting->captcha_site_key,
            'pageUrl' => $setting->captcha_page_url,
        ]);
    })->middleware('super_admin')->name('in-house-captcha.index');

    Route::get('bot-control', function () {
        $setting = \App\Models\Setting::instance();

        return Inertia::render('BotControl/Index', [
            'serverTimezone' => config('app.timezone'),
            'allowedStartTime' => $setting->window_start_time ?? '00:00:00',
        ]);
    })->middleware('can:accounts.assign')->name('bot-control.index');

    Route::get('log-analysis', function () {
        return Inertia::render('LogAnalysis/Index');
    })->middleware('can:logs.read')->name('log-analysis.index');

    Route::get('monthly-report', function (Illuminate\Http\Request $request) {
        $user = $request->user();

        $month = $request->query('month');
        $monthStart = $month
            ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : now()->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();

        if ($user->isSuperAdmin()) {
            $paymentRaw = DB::table('payment_links as pl');
        } else {
            $userPhones = $user->accounts()->pluck('phone');
            $paymentRaw = DB::table('payment_links as pl')->whereIn('pl.account_phone', $userPhones);
        }

        // Counts mirror the /payment-links grouping: each booking is one (phone + date) card,
        // counted once regardless of retry links. "total" = cards; "declined" = cards whose
        // earliest link is declined (the page's representative status); "applicants" (BGD) = sum
        // of each card's applicant count across ALL statuses.
        //
        // The account join is aggregated only because the outer GROUP BY demands it: phone is
        // unique on accounts, so each card has exactly one status and MAX() returns that value.
        $sub = (clone $paymentRaw)
            ->whereBetween('pl.created_at', [$monthStart, $monthEnd])
            ->leftJoin('account_sessions as s', 's.phone', '=', 'pl.account_phone')
            ->leftJoin('accounts as a', 'a.phone', '=', 'pl.account_phone')
            ->selectRaw("pl.account_phone,"
                ." DATE(pl.created_at) as date,"
                ." COALESCE(s.reserved_visa_type, 'UNKNOWN') as visa_type,"
                ." MAX(s.reserved_applicants) as applicants,"
                ." MAX(a.status) as account_status,"
                ." SUBSTRING_INDEX(GROUP_CONCAT(pl.review_status ORDER BY pl.id), ',', 1) as card_status")
            ->groupBy('pl.account_phone', DB::raw('DATE(pl.created_at)'), 'visa_type');

        $monthRows = DB::query()->fromSub($sub, 'c')
            ->selectRaw("date, visa_type,"
                ." COUNT(*) as total,"
                ." SUM(CASE WHEN card_status = 'declined' THEN 1 ELSE 0 END) as declined,"
                ." SUM(CASE WHEN account_status = 'completed' THEN 1 ELSE 0 END) as completed,"
                ." COALESCE(SUM(applicants), 0) as applicants")
            ->groupBy('date', 'visa_type')
            ->orderBy('date')
            ->orderBy('visa_type')
            ->get();

        $overrides = \App\Models\MonthlyReportOverride::query()
            ->whereBetween('report_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->keyBy(fn ($o) => $o->report_date->toDateString() . '|' . $o->original_visa_type);

        $monthRows = $monthRows->map(function ($row) use ($overrides) {
            $override = $overrides->get($row->date . '|' . $row->visa_type);
            $declined = (int) $row->declined;
            $total = (int) $row->total;

            return [
                'date' => $row->date,
                'original_visa_type' => $row->visa_type,
                'visa_type' => $override->visa_type ?? $row->visa_type,
                'total' => $total,
                'declined' => $declined,
                'success' => $total - $declined,
                'completed' => (int) $row->completed,
                'applicants' => $override ? $override->applicants : (int) $row->applicants,
                'is_overridden' => (bool) $override,
                'has_declined' => $declined > 0,
            ];
        });

        $visaTypes = DB::table('account_sessions')
            ->whereNotNull('reserved_visa_type')
            ->distinct()
            ->orderBy('reserved_visa_type')
            ->pluck('reserved_visa_type');

        return Inertia::render('MonthlyReport/Index', [
            'monthRows' => $monthRows,
            'visaTypes' => $visaTypes,
            'month' => $monthStart->format('Y-m'),
            'prevMonth' => (clone $monthStart)->subMonth()->format('Y-m'),
            'nextMonth' => (clone $monthStart)->addMonth()->format('Y-m'),
            'isCurrentMonth' => $monthStart->isSameMonth(now()),
            'canEdit' => $user->isSuperAdmin(),
        ]);
    })->middleware('super_admin')->name('monthly-report.index');

    Route::post('monthly-report/overrides', function (Illuminate\Http\Request $request) {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.date' => ['required', 'date_format:Y-m-d'],
            'rows.*.original_visa_type' => ['required', 'string', 'max:64'],
            'rows.*.visa_type' => ['required', 'string', 'max:64'],
            'rows.*.applicants' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['rows'] as $row) {
            \App\Models\MonthlyReportOverride::updateOrCreate(
                ['report_date' => $row['date'], 'original_visa_type' => $row['original_visa_type']],
                [
                    'visa_type' => $row['visa_type'],
                    'applicants' => $row['applicants'],
                    'updated_by' => $request->user()->id,
                ]
            );
        }

        return back();
    })->middleware('super_admin')->name('monthly-report.overrides.store');

    Route::get('otps', function () {
        return Inertia::render('Otps/Index', [
            'accountPhones' => Account::query()->orderBy('phone')->pluck('phone')->values(),
        ]);
    })->name('otps.index');

    Route::get('wallet', function () {
        return Inertia::render('Wallet/Index');
    })->name('wallet.index');

    Route::get('vps-manager', function () {
        return Inertia::render('VpsManager/Index');
    })->middleware('super_admin')->name('vps-manager.index');

    Route::get('api-tester', function () {
        return Inertia::render('ApiTester/Index');
    })->middleware('can:api-tester.access')->name('api-tester.index');

    Route::get('slot-logs/{agentSlot}', function (AgentSlot $agentSlot) {
        return Inertia::render('SlotLogs/Index', [
            'slot' => $agentSlot->loadCount('accounts')->load('bypassIp'),
        ]);
    })->middleware('super_admin')->name('slot-logs.index');

    // Authorised by UserPolicy::impersonate, so a manager may only become their own agents.
    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'impersonate'])->name('impersonate');
    Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');

    Route::get('bypass-ips', function () {
        return Inertia::render('BypassIps/Index');
    })->middleware('super_admin')->name('bypass-ips.page');

    Route::get('page-password', [PagePasswordController::class, 'show'])->name('page-password.show');
    Route::post('page-password', [PagePasswordController::class, 'store'])->name('page-password.store');

    Route::get('captcha-algorithm-monitor', function () {
        return Inertia::render('CaptchaAlgorithm/Index');
    })->middleware('super_admin')->name('captcha-algorithm-monitor.index');

    Route::get('ivac-endpoints', function () {
        return Inertia::render('IvacEndpoints/Index');
    })->middleware('super_admin')->name('ivac-endpoints.index');

    Route::get('notice', function () {
        return Inertia::render('Notice/Index');
    })->middleware('can:notice.write')->name('notice.index');

    Route::get('apk-manager', function () {
        return Inertia::render('ApkManager/Index');
    })->middleware('super_admin')->name('apk-manager.index');


    Route::get('gmail', function () {
        return Inertia::render('Gmail/Index');
    })->middleware('super_admin')->name('gmail.index');

    Route::get('auth/google', [GoogleOAuthController::class, 'redirect'])->middleware('super_admin')->name('google.redirect');
    Route::get('auth/google/callback', [GoogleOAuthController::class, 'callback'])->middleware('super_admin')->name('google.callback');
    Route::delete('auth/google', [GoogleOAuthController::class, 'disconnect'])->middleware('super_admin')->name('google.disconnect');
});

require __DIR__.'/debug.php';
require __DIR__.'/settings.php';
