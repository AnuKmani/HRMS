<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        // A completed, on-time, full day: the shape every test starts from
        // before it makes one thing wrong with it.
        $date = fake()->unique()->dateTimeBetween('-60 days', 'today')->format('Y-m-d');

        return [
            'employee_id' => Employee::factory(),
            'project_id' => Project::factory(),
            'site_id' => Site::factory(),
            'attendance_date' => $date,

            'check_in_at' => $date.' 09:00:00',
            'check_out_at' => $date.' 18:00:00',

            'check_in_latitude' => null,
            'check_in_longitude' => null,
            'check_in_accuracy' => 8.0,
            'check_in_distance' => 12.5,
            'check_in_selfie_path' => null,

            'check_out_latitude' => null,
            'check_out_longitude' => null,
            'check_out_accuracy' => 8.0,
            'check_out_distance' => 12.5,

            'shift_id' => null,
            'scheduled_start_at' => $date.' 09:00:00',
            'scheduled_end_at' => $date.' 18:00:00',

            'working_minutes' => 480,
            'break_minutes' => 60,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,

            'status' => Attendance::STATUS_PRESENT,
            'source' => 'online',
            'device_reference' => null,
            'notes' => null,
            'client_event_id' => null,
        ];
    }

    /** Open — checked in, no checkout. */
    public function open(): static
    {
        return $this->state(fn () => [
            'check_out_at' => null,
            'working_minutes' => 0,
            'break_minutes' => 0,
            'overtime_minutes' => 0,
            'early_departure_minutes' => 0,
        ]);
    }

    /** Standing at a known point, so a geofence test has something to measure. */
    public function at(float $latitude, float $longitude, float $radiusMetres = 0): static
    {
        return $this->state(fn () => [
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'check_in_distance' => $radiusMetres,
        ]);
    }
}
