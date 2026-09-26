<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Shift;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'project_id' => Project::factory(),
            'name' => fake()->city().' Site',
            'code' => 'SITE'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(8, 34),     // India-ish range
            'longitude' => fake()->longitude(68, 97),
            'geofence_radius' => fake()->randomElement([50, 100, 150, 200]),
            'site_manager_id' => Employee::factory(),
            'site_supervisor_id' => Employee::factory(),
            'working_hours_setting_id' => null,
            'shift_id' => Shift::factory(),
            'status' => Site::STATUS_ACTIVE,
        ];
    }

    /**
     * A site with no geofence configured yet (validation must catch this).
     */
    public function withoutGeofence(): static
    {
        return $this->state(fn () => [
            'latitude' => null,
            'longitude' => null,
            'geofence_radius' => null,
        ]);
    }
}
