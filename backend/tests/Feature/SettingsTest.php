<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_base_settings_are_seeded(): void
    {
        $this->seed(SettingSeeder::class);

        $expected = [
            'attendance.grace_period_minutes',
            'attendance.overtime_threshold_minutes',
            'leave.sick_certificate_deadline_days',
            'notification.reminder_offset_minutes',
            'working_hours.default',
        ];

        foreach ($expected as $key) {
            $this->assertDatabaseHas('settings', ['key' => $key]);
            $this->assertNotNull($this->service()->get($key), "Setting {$key} not readable.");
        }
    }

    public function test_seeding_twice_does_not_duplicate_settings(): void
    {
        $this->seed(SettingSeeder::class);
        $count = Setting::count();

        $this->seed(SettingSeeder::class);

        $this->assertSame($count, Setting::count());
    }

    public function test_setting_keys_are_unique(): void
    {
        Setting::factory()->create(['key' => 'attendance.grace_period_minutes']);

        $this->expectException(QueryException::class);

        Setting::factory()->create(['key' => 'attendance.grace_period_minutes']);
    }

    public function test_typed_getters_return_correct_php_types(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertIsInt($this->service()->int('attendance.grace_period_minutes'));
        $this->assertSame(10, $this->service()->int('attendance.grace_period_minutes'));
        // Phase 6: the deadline is configurable and defaults to two days.
        // A type's own `document_deadline_days` overrides it when set above
        // zero — see LeaveType::documentDeadlineDays().
        $this->assertSame(2, $this->service()->int('leave.sick_certificate_deadline_days'));

        $this->assertIsArray($this->service()->json('working_hours.default'));
        $this->assertSame(8, $this->service()->json('working_hours.default')['daily_hours']);

        $this->assertIsString($this->service()->string('system.currency'));
        $this->assertSame('INR', $this->service()->string('system.currency'));
    }

    public function test_missing_key_falls_back_to_supplied_default(): void
    {
        $this->assertNull($this->service()->get('does.not.exist'));
        $this->assertSame(42, $this->service()->int('does.not.exist', 42));
        $this->assertSame('fallback', $this->service()->string('does.not.exist', 'fallback'));
        $this->assertSame(['a' => 1], $this->service()->json('does.not.exist', ['a' => 1]));
    }

    public function test_business_rules_are_read_from_database_not_constants(): void
    {
        $this->seed(SettingSeeder::class);

        $before = $this->service()->int('attendance.grace_period_minutes');
        $this->assertSame(10, $before);

        // An operator tuning the rule — no deploy, no code change.
        // Writes must go through the Setting model (not DB::table / query
        // builder): the model's saved event is what invalidates the cache.
        Setting::query()->where('key', 'attendance.grace_period_minutes')
            ->first()
            ->update(['value' => '25']);

        $this->assertSame(25, $this->service()->int('attendance.grace_period_minutes'));
    }

    public function test_write_invalidates_the_cached_snapshot(): void
    {
        $this->seed(SettingSeeder::class);

        // Prime the cache.
        $this->service()->int('attendance.grace_period_minutes');

        Setting::create([
            'key' => 'attendance.new_rule',
            'value' => '99',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'New rule',
        ]);

        $this->assertSame(99, $this->service()->int('attendance.new_rule'));
    }

    public function test_settings_are_grouped_for_admin_screen(): void
    {
        $this->seed(SettingSeeder::class);

        $grouped = $this->service()->grouped('attendance');

        $this->assertArrayHasKey('attendance', $grouped);
        $this->assertNotEmpty($grouped['attendance']);

        foreach ($grouped['attendance'] as $setting) {
            $this->assertSame('attendance', $setting->group);
        }
    }

    public function test_every_seeded_setting_declares_a_known_type(): void
    {
        $this->seed(SettingSeeder::class);

        foreach (Setting::all() as $setting) {
            $this->assertContains($setting->type, Setting::TYPES, "Unknown type for {$setting->key}");
            $this->assertNotEmpty($setting->label, "{$setting->key} needs a label");
        }
    }

    public function test_json_value_decodes_to_array(): void
    {
        $this->seed(SettingSeeder::class);

        $value = $this->service()->get('working_hours.default');

        $this->assertIsArray($value);
        $this->assertArrayHasKey('start', $value);
        $this->assertArrayHasKey('working_days', $value);
    }
}
