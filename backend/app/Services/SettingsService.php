<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Read-through accessor for the `settings` table.
 *
 * Inject this wherever a business rule is needed instead of hard-coding it:
 *
 *     public function __construct(private SettingsService $settings) {}
 *
 *     $grace = $this->settings->minutes('attendance.grace_period_minutes', 10);
 *
 * All reads are cached and flushed whenever a Setting is written.
 */
class SettingsService
{
    public const CACHE_KEY = 'hrms.settings.all';

    /** In-request memo so a hot path does not re-hydrate on every call. */
    private ?array $loaded = null;

    /**
     * @return array<string, Setting>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        return $this->loaded = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->get()->keyBy('key')->all()
        );
    }

    /**
     * Raw typed value, or $default when the key has not been seeded yet.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = $this->all()[$key] ?? null;

        if ($setting === null || $setting->value === null) {
            return $default;
        }

        return $setting->castValue($setting->value);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function minutes(string $key, int $default = 0): int
    {
        return $this->int($key, $default);
    }

    public function json(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * The full Setting row (for an admin settings screen), keyed lookup.
     */
    public function row(string $key): ?Setting
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Grouped rows, ready to render.
     *
     * @return array<string, array<int, Setting>>
     */
    public function grouped(?string $group = null): array
    {
        return collect($this->all())
            ->when($group, fn ($c) => $c->filter(fn (Setting $s) => $s->group === $group))
            ->groupBy('group')
            ->all();
    }

    /**
     * Forget the memo + cache (call after writes or seeding).
     */
    public function refresh(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }
}
