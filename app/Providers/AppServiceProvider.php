<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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

        // /api/config hands over account sign-in material, so a leaked link must not be able to
        // walk the whole fleet. A bot fetches it once at start and again on a config-version
        // rotation, which leaves a healthy fleet far below this.
        RateLimiter::for('bot-config', fn (Request $request) => Limit::perMinute(
            (int) config('captcha.bot_config_rate_limit_per_minute', 60)
        )->by(self::botConfigThrottleKey($request)));
    }

    /**
     * Bucket a /api/config caller by the credential it presented — hashed, because the cache
     * store must never hold the secret itself. A signed link has no credential to hash, so it
     * falls back to the address, which is still enough to stop one host walking the fleet.
     */
    private static function botConfigThrottleKey(Request $request): string
    {
        $credential = $request->bearerToken();

        if (is_string($credential) && $credential !== '') {
            return 'bot-config:cred:'.hash('sha256', $credential);
        }

        $user = $request->user();

        return $user === null
            ? 'bot-config:ip:'.$request->ip()
            : 'bot-config:user:'.$user->getAuthIdentifier();
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
