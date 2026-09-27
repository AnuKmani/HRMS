<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton: one cached read-through of `settings` per request.
        $this->app->singleton(SettingsService::class, fn () => new SettingsService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Any settings write invalidates the cached snapshot so the next read
        // sees the new value (including across queue workers / other tabs).
        Setting::saved(fn () => $this->app->make(SettingsService::class)->refresh());
        Setting::deleted(fn () => $this->app->make(SettingsService::class)->refresh());

        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters referenced from routes/api.php as `throttle:{name}`.
     *
     * Both read their numbers from config/rate_limiting.php (which reads .env),
     * so tightening a limit is a config change and a deploy — never a hunt
     * through route files for a literal.
     *
     * Keyed by client IP rather than by email. Keying login by email would let
     * anyone with a connection flood one address and lock its real owner out;
     * keying by IP throttles the machine actually doing the guessing and is
     * immune to that abuse.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.login.decay_minutes'),
                (int) config('rate_limiting.login.max_attempts'),
            )->by('login:'.$request->ip());
        });

        RateLimiter::for('password_reset', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.password_reset.decay_minutes'),
                (int) config('rate_limiting.password_reset.max_attempts'),
            )->by('password_reset:'.$request->ip());
        });

        // Attendance writes. Keyed by the authenticated user rather than by
        // IP, because a crew on one site shares an address and one device
        // retrying on a weak signal must not stop everyone else clocking in.
        // Unauthenticated traffic falls back to the IP — it never reaches
        // these routes anyway, since auth:sanctum runs first.
        RateLimiter::for('attendance', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.attendance.decay_minutes'),
                (int) config('rate_limiting.attendance.max_attempts'),
            )->by('attendance:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
