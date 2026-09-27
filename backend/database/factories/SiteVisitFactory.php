<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteVisit>
 */
class SiteVisitFactory extends Factory
{
    public function definition(): array
    {
        $day = fake()->unique()->dateTimeBetween('-30 days', 'today')->format('Y-m-d');

        return [
            'employee_id' => Employee::factory(),
            'project_id' => Project::factory(),
            'site_id' => Site::factory(),

            'started_at' => $day.' 11:20:00',
            'ended_at' => $day.' 12:05:00',

            'start_latitude' => null,
            'start_longitude' => null,
            'start_accuracy' => 9.0,
            'start_distance' => 4.0,

            'end_latitude' => null,
            'end_longitude' => null,
            'end_accuracy' => 9.0,
            'end_distance' => 5.0,

            'purpose' => 'Concrete pour inspection',
            'remarks' => null,
            'status' => SiteVisit::STATUS_COMPLETED,
            'client_event_id' => null,
            'end_client_event_id' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'ended_at' => null,
            'status' => SiteVisit::STATUS_OPEN,
            'end_latitude' => null,
            'end_longitude' => null,
            'end_accuracy' => null,
            'end_distance' => null,
        ]);
    }
}
