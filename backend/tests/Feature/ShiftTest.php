<?php

namespace Tests\Feature;

use App\Models\Shift;
use Database\Seeders\DevelopmentDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_shifts_are_seeded_as_development_data(): void
    {
        $this->seed(DevelopmentDataSeeder::class);

        foreach (['General', 'Morning', 'Evening', 'Night'] as $name) {
            $this->assertDatabaseHas('shifts', ['name' => $name]);
        }
    }

    public function test_overnight_shift_is_detected_from_its_times(): void
    {
        $shift = Shift::factory()->night()->create();

        $this->assertTrue($shift->crosses_midnight);
        $this->assertSame('22:00:00', $shift->start_time);
        $this->assertSame('06:00:00', $shift->end_time);
    }

    public function test_day_shift_does_not_flag_overnight(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $this->assertFalse($shift->crosses_midnight);
    }

    public function test_overnight_flag_is_recomputed_when_times_change(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
        $this->assertFalse($shift->crosses_midnight);

        $shift->update(['end_time' => '03:00:00']);
        $this->assertTrue($shift->fresh()->crosses_midnight);

        $shift->update(['end_time' => '17:00:00']);
        $this->assertFalse($shift->fresh()->crosses_midnight);
    }

    public function test_duration_in_minutes_excludes_break_for_day_shift(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_duration' => 60,
        ]);

        // 9h span - 60m break = 8h paid.
        $this->assertSame(480, $shift->durationMinutes());
    }

    public function test_duration_in_minutes_handles_rollover_past_midnight(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'break_duration' => 45,
        ]);

        // 8h span (22:00 -> 06:00 next day) - 45m break = 435m.
        $this->assertTrue($shift->crosses_midnight);
        $this->assertSame(435, $shift->durationMinutes());
    }

    public function test_shift_configuration_is_per_row_not_hardcoded(): void
    {
        $a = Shift::factory()->create([
            'start_time' => '09:00:00', 'end_time' => '18:00:00',
            'grace_period' => 5, 'minimum_working_hours' => 8, 'overtime_threshold' => 2,
        ]);
        $b = Shift::factory()->create([
            'start_time' => '09:00:00', 'end_time' => '18:00:00',
            'grace_period' => 30, 'minimum_working_hours' => 6, 'overtime_threshold' => 4,
        ]);

        $this->assertSame(5, $a->grace_period);
        $this->assertSame(30, $b->grace_period);
        $this->assertSame(6.0, (float) $b->minimum_working_hours);
        $this->assertSame(4.0, (float) $b->overtime_threshold);
    }

    public function test_shift_code_is_unique(): void
    {
        $shift = Shift::factory()->create();

        $this->expectException(QueryException::class);

        Shift::factory()->create(['code' => $shift->code]);
    }

    public function test_shift_start_equals_end_rolls_to_full_day(): void
    {
        // A degenerate "24h on-call" row: end == start means 24h.
        $shift = Shift::factory()->create([
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
            'break_duration' => 0,
        ]);

        $this->assertTrue($shift->crosses_midnight);
        $this->assertSame(1440, $shift->durationMinutes());
    }
}
