<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteVisitTask;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\SignsInAccounts;
use Tests\TestCase;

/**
 * A Project Manager plans site visits for field engineers up to a month
 * ahead; the engineer sees them; doing the visit completes the plan, and
 * both sides see it completed.
 */
class SiteVisitPlansTest extends TestCase
{
    use RefreshDatabase, SignsInAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
    }

    /**
     * @return array{pm: User, project: Project, site: Site, a: array, b: array}
     */
    private function world(): array
    {
        [$pm, $pmEmployee] = $this->makeSeat('Project Manager');
        $project = Project::factory()->create(['project_manager_id' => $pmEmployee->id]);
        $site = Site::factory()->create([
            'project_id' => $project->id,
            'latitude' => 25.2,
            'longitude' => 55.27,
            'status' => Site::STATUS_ACTIVE,
        ]);

        return [
            'pm' => $pm,
            'project' => $project,
            'site' => $site,
            'a' => $this->makeSeat('Site Engineer'),
            'b' => $this->makeSeat('Site Engineer'),
        ];
    }

    private function plan(array $world, array $overrides = [])
    {
        $this->become($world['pm']);

        return $this->postJson('/api/v1/site-visit-plans', $overrides + [
            'employee_ids' => [$world['a'][1]->id, $world['b'][1]->id],
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
            'visit_date' => '2026-10-15',
            'purpose' => 'Slab inspection',
        ]);
    }

    public function test_a_project_manager_plans_visits_for_several_engineers(): void
    {
        $world = $this->world();

        $this->plan($world)->assertCreated()->assertJsonCount(2, 'data.items');

        // Each engineer is told, and sees only their own on their list.
        foreach (['a', 'b'] as $who) {
            $this->assertTrue(InAppNotification::query()
                ->where('user_id', $world[$who][0]->id)
                ->where('type', 'site_task.assigned')
                ->exists());
        }

        $this->become($world['a'][0]);
        $items = $this->getJson('/api/v1/site-visit-plans?scope=mine')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('planned', $items[0]['status']);
        $this->assertSame('Slab inspection', $items[0]['purpose']);
        $this->assertTrue($items[0]['is_mine']);

        // The planner sees both.
        $this->become($world['pm']);
        $this->assertCount(2, $this->getJson('/api/v1/site-visit-plans')->json('data.items'));
    }

    public function test_dates_are_today_up_to_a_month_ahead(): void
    {
        $world = $this->world();

        $this->plan($world, ['visit_date' => '2026-10-09'])->assertStatus(422);
        $this->plan($world, ['visit_date' => '2026-11-11'])->assertStatus(422);
        $this->plan($world, ['visit_date' => '2026-11-10'])->assertCreated();
        $this->plan($world, ['visit_date' => '2026-10-10'])->assertCreated();
    }

    public function test_only_people_with_the_site_visit_option_can_be_given_one(): void
    {
        $world = $this->world();
        [, $plain] = $this->makeSeat('Employee');

        $this->plan($world, ['employee_ids' => [$plain->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_ids']);

        $this->become($world['pm']);
        $names = array_column($this->getJson('/api/v1/site-visit-plans/assignees')->assertOk()->json('data.items'), 'id');
        $this->assertContains($world['a'][1]->id, $names);
        $this->assertNotContains($plain->id, $names);
    }

    public function test_doing_the_visit_completes_the_plan_for_both_sides(): void
    {
        $world = $this->world();
        $this->plan($world, ['visit_date' => '2026-10-10'])->assertCreated();
        [$engineer, $engineerEmployee] = $world['a'];

        $this->become($engineer);
        $visit = $this->postJson('/api/v1/site-visits/start', [
            'site_id' => $world['site']->id,
            'latitude' => 25.2,
            'longitude' => 55.27,
            'accuracy' => 8,
            'purpose' => 'Slab inspection',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/site-visits/{$visit}/end", [
            'latitude' => 25.2, 'longitude' => 55.27, 'accuracy' => 8,
        ])->assertOk();

        $task = SiteVisitTask::query()->where('employee_id', $engineerEmployee->id)->sole();
        $this->assertSame(SiteVisitTask::STATUS_COMPLETED, $task->status);
        $this->assertSame($visit, $task->site_visit_id);

        // The other engineer's plan is untouched.
        $this->assertSame(1, SiteVisitTask::query()->where('status', 'planned')->count());

        // The planner sees it completed and was told.
        $this->become($world['pm']);
        $row = collect($this->getJson('/api/v1/site-visit-plans')->json('data.items'))
            ->firstWhere('employee_id', $engineerEmployee->id);
        $this->assertSame('completed', $row['status']);
        $this->assertTrue(InAppNotification::query()
            ->where('user_id', $world['pm']->id)
            ->where('type', 'site_task.completed')
            ->exists());
    }

    public function test_the_engineer_can_mark_it_completed_by_hand_and_nobody_else_can(): void
    {
        $world = $this->world();
        $this->plan($world)->assertCreated();
        $mine = SiteVisitTask::query()->where('employee_id', $world['a'][1]->id)->sole();

        $this->become($world['b'][0]);
        $this->postJson("/api/v1/site-visit-plans/{$mine->id}/complete")->assertForbidden();

        $this->become($world['a'][0]);
        $this->postJson("/api/v1/site-visit-plans/{$mine->id}/complete", ['note' => 'Checked with the foreman'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completion_note', 'Checked with the foreman');

        $this->postJson("/api/v1/site-visit-plans/{$mine->id}/complete")->assertStatus(409);
    }

    public function test_the_planner_cancels_and_a_past_unvisited_day_reads_missed(): void
    {
        $world = $this->world();
        $this->plan($world)->assertCreated();
        [$first, $second] = SiteVisitTask::query()->orderBy('id')->get()->all();

        $this->become($world['pm']);
        $this->postJson("/api/v1/site-visit-plans/{$first->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $row = collect($this->getJson('/api/v1/site-visit-plans')->json('data.items'))->firstWhere('id', $second->id);
        $this->assertSame('missed', $row['status']);
        $this->assertCount(1, $this->getJson('/api/v1/site-visit-plans?status=missed')->json('data.items'));
    }

    public function test_a_plan_cannot_name_a_site_from_another_project(): void
    {
        $world = $this->world();
        $otherSite = Site::factory()->create();
        $this->plan($world, ['site_id' => $otherSite->id])->assertStatus(422);

        // Engineers are sent across projects: any project will do.
        $elsewhere = Project::factory()->create();
        $this->plan($world, ['project_id' => $elsewhere->id, 'site_id' => null])->assertCreated();
    }

    public function test_one_project_manager_does_not_see_another_managers_plans(): void
    {
        $world = $this->world();
        $this->plan($world)->assertCreated();

        [$otherPm] = $this->makeSeat('Project Manager');
        $this->become($otherPm);
        $this->assertSame([], $this->getJson('/api/v1/site-visit-plans')->assertOk()->json('data.items'));

        $task = SiteVisitTask::query()->first();
        $this->postJson("/api/v1/site-visit-plans/{$task->id}/cancel")->assertForbidden();
    }

    public function test_hr_and_plain_employees_have_no_door_into_plans(): void
    {
        $this->signInAs('HR Admin');
        $this->getJson('/api/v1/site-visit-plans')->assertForbidden();

        $this->signInAs('Employee');
        $this->getJson('/api/v1/site-visit-plans')->assertForbidden();
    }
}
