<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'name' => 'Shift '.chr(64 + (($seq % 26) + 1)).($seq > 26 ? $seq : ''),
            'code' => 'SHF'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'crosses_midnight' => false,
            'break_duration' => 60,
            'grace_period' => 10,
            'minimum_working_hours' => 8.00,
            'overtime_threshold' => 2.00,
            'status' => Shift::STATUS_ACTIVE,
        ];
    }

    /**
     * A night shift that rolls past midnight (22:00 -> 06:00).
     */
    public function night(): static
    {
        return $this->state(fn () => [
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
        ]);
    }
}
