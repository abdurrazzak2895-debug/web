<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            config(['app.debug' => true]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('settings.read', fn ($user) => $user->hasPermission('settings.read'));
        Gate::define('settings.write', fn ($user) => $user->hasPermission('settings.write'));
        Gate::define('captcha.read', fn ($user) => $user->hasPermission('captcha.read'));
        Gate::define('captcha.write', fn ($user) => $user->hasPermission('captcha.write'));
        Gate::define('accounts.read', fn ($user) => $user->hasPermission('accounts.read'));
        Gate::define('accounts.write', fn ($user) => $user->hasPermission('accounts.write'));
        Gate::define('proxies.read', fn ($user) => $user->hasPermission('proxies.read'));
        Gate::define('proxies.write', fn ($user) => $user->hasPermission('proxies.write'));
        Gate::define('bot.manage', fn ($user) => $user->isSuperAdmin());
        Gate::define('accounts.assign', fn ($user) => $user->hasPermission('accounts.assign'));
        Gate::define('users.manage', fn ($user) => $user->hasPermission('users.manage'));
        Gate::define('logs.read', fn ($user) => $user->hasPermission('logs.read'));
        // The request tester talks to IVAC directly, so it stays with the admin and manager
        // tiers — sub managers and agents never see it.
        Gate::define('api-tester.access', fn ($user) => ! $user->isAgent() && ! $user->isSubManager());

        // The header notice is authored by the operator, not delegated: super admins and
        // managers write it, sub managers and agents only read it off the shared Inertia prop.
        Gate::define('notice.write', fn ($user) => $user->isSuperAdmin() || $user->isManager());

        // Captcha delivery — the one place a leaked credential turns into money spent at the
        // solve providers, so it is bucketed per credential rather than per address. A worker
        // mints one token per solve, which leaves a healthy fleet far below the budget.
        RateLimiter::for('captcha-api', fn (Request $request) => Limit::perMinute(
            (int) config('captcha.api_rate_limit_per_minute', 240)
        )->by(self::captchaApiThrottleKey($request, 'delivery')));

        // The poll is the hot path — four requests a second while a solve is in flight — so it
        // carries the same credential with a far higher budget of its own.
        RateLimiter::for('captcha-api-poll', fn (Request $request) => Limit::perMinute(
            (int) config('captcha.poll_rate_limit_per_minute', 7200)
        )->by(self::captchaApiThrottleKey($request, 'poll')));
    }

    /**
     * Bucket a captcha API caller by the credential it presented — hashed, because the cache
     * store must never hold the secret itself. A session buckets by user and everyone else by
     * address, so the limiter can run ahead of the auth middleware without letting an anonymous
     * flood reach the slot lookup.
     *
     * `$bucket` separates the limiters: Laravel derives the counter from this key alone, so
     * without it the poll and the delivery routes would share one counter and a busy poll — four
     * requests a second per solve — would spend the delivery budget and refuse the next mint.
     */
    private static function captchaApiThrottleKey(Request $request, string $bucket): string
    {
        $credential = $request->bearerToken() ?: $request->header('X-Api-Key');

        if (is_string($credential) && $credential !== '') {
            return 'captcha-api:'.$bucket.':cred:'.hash('sha256', $credential);
        }

        $user = $request->user();

        return $user === null
            ? 'captcha-api:'.$bucket.':ip:'.$request->ip()
            : 'captcha-api:'.$bucket.':user:'.$user->getAuthIdentifier();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
