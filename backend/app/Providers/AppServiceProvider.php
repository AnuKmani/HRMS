<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\SettingsService;
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
    }
}
