<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectEvent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\SignsInAccounts;
use Tests\TestCase;

/**
 * HR's project overview: status (Ongoing / Handing over / Completed), who
 * runs it, and the dated inspections coming up. The Project Manager keeps
 * the dates; HR reads them.
 */
class ProjectEventsTest extends TestCase
{
    use RefreshDatabase, SignsInAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-04 09:00:00'));
    }

    public function test_hr_sees_status_manager_and_upcoming_inspections(): void
    {
        [, $pm] = $this->makeSeat('Project Manager', ['first_name' => 'Ravi', 'middle_name' => null, 'last_name' => 'Menon']);
        $project = Project::factory()->create([
            'name' => 'Marina Tower',
            'status' => Project::STATUS_HANDING_OVER,
            'project_manager_id' => $pm->id,
        ]);
        ProjectEvent::query()->create(['project_id' => $project->id, 'type' => 'civil_defence', 'event_date' => '2026-10-20']);
        ProjectEvent::query()->create(['project_id' => $project->id, 'type' => 'dcd_inspection', 'event_date' => '2026-10-10']);
        ProjectEvent::query()->create(['project_id' => $project->id, 'type' => 'dcd_inspection', 'event_date' => '2026-09-01']);

        $this->signInAs('HR Admin');

        $row = collect($this->getJson('/api/v1/projects')->assertOk()->json('data.items'))
            ->firstWhere('id', $project->id);

        $this->assertSame('handing_over', $row['status']);
        $this->assertSame('Ravi Menon', $row['project_manager']['full_name']);
        // Upcoming only, soonest first; September's is history.
        $this->assertSame(['DCD inspection', 'Civil Defence inspection'], array_column($row['upcoming_events'], 'label'));
        $this->assertSame('2026-10-10', $row['upcoming_events'][0]['event_date']);

        // The project page has the full list, history included.
        $this->assertCount(3, $this->getJson("/api/v1/projects/{$project->id}")->assertOk()->json('data.events'));

        // HR reads; it does not book inspections.
        $this->postJson("/api/v1/projects/{$project->id}/events", [
            'type' => 'dcd_inspection', 'event_date' => '2026-11-01',
        ])->assertForbidden();
    }

    public function test_the_project_manager_books_moves_and_cancels_an_inspection(): void
    {
        [$user, $pm] = $this->signInAs('Project Manager');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);

        $id = $this->postJson("/api/v1/projects/{$project->id}/events", [
            'type' => 'civil_defence',
            'event_date' => '2026-10-15',
            'notes' => 'Fire alarm test with the consultant',
        ])->assertCreated()->assertJsonPath('data.label', 'Civil Defence inspection')->json('data.id');

        $this->putJson("/api/v1/projects/{$project->id}/events/{$id}", [
            'type' => 'civil_defence', 'event_date' => '2026-10-18',
        ])->assertOk()->assertJsonPath('data.event_date', '2026-10-18');

        $this->postJson("/api/v1/projects/{$project->id}/events", [
            'type' => 'meeting', 'event_date' => '2026-10-15',
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/projects/{$project->id}/events/{$id}")->assertOk();
        $this->assertSame(0, ProjectEvent::query()->count());
    }

    public function test_an_event_cannot_be_reached_through_another_project(): void
    {
        [, $pm] = $this->signInAs('Project Manager');
        $mine = Project::factory()->create(['project_manager_id' => $pm->id]);
        $other = Project::factory()->create(['project_manager_id' => $pm->id]);
        $event = ProjectEvent::query()->create(['project_id' => $other->id, 'type' => 'handover', 'event_date' => '2026-12-01']);

        $this->deleteJson("/api/v1/projects/{$mine->id}/events/{$event->id}")->assertNotFound();
    }
}
