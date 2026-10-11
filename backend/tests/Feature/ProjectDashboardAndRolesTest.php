<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectStage;
use App\Models\ProjectSubcontractor;
use App\Services\Projects\ProjectProgressService;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * Operations Manager, Senior Project Manager and Project Coordinator, and
 * the money-and-progress dashboard for Project Manager and above.
 */
class ProjectDashboardAndRolesTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
        Storage::fake('local');
    }

    /** A project with HVAC (600k, 50% done, 300k billed) and Electrical (400k, 25%, 100k). */
    private function mepProject(?int $pmId = null): Project
    {
        $project = Project::factory()->create(['project_manager_id' => $pmId, 'status' => 'active']);
        app(ProjectService::class)->replaceStages($project, [
            ['name' => 'HVAC', 'contract_value' => 600000],
            ['name' => 'Electrical', 'contract_value' => 400000],
        ]);
        [$hvac, $elec] = $project->stages()->get()->all();
        $hvac->forceFill(['percent_complete' => 50, 'billed_amount' => 300000])->save();
        $elec->forceFill(['percent_complete' => 25, 'billed_amount' => 100000])->save();
        app(ProjectProgressService::class)->recompute($project);

        ProjectSubcontractor::query()->create([
            'project_id' => $project->id, 'project_stage_id' => $hvac->id, 'name' => 'Cool Air LLC',
            'trade' => 'HVAC installation', 'contract_value' => 200000, 'billed_amount' => 50000, 'status' => 'active',
        ]);

        return $project->refresh();
    }

    public function test_weights_follow_system_values_and_the_dashboard_adds_it_all_up(): void
    {
        $project = $this->mepProject();

        $this->assertEquals([60, 40], $project->stages()->pluck('weight')->map(fn ($w) => (float) $w)->all());
        // 0.6 × 50 + 0.4 × 25
        $this->assertEquals(40, (float) $project->progress_percent);

        $this->signInAs('Project Manager');
        $data = $this->getJson('/api/v1/project-dashboard')->assertOk()->json('data');
        $row = collect($data['items'])->firstWhere('id', $project->id);

        $this->assertEquals(1000000, $row['value_total']);
        $this->assertEquals(400000, $row['billed_total']);
        $this->assertEquals(40, $row['billed_percent']);
        $this->assertEquals(50, $row['systems'][0]['billed_percent']);
        $this->assertSame('Cool Air LLC', $row['subcontractors'][0]['name']);
        $this->assertSame('HVAC', $row['subcontractors'][0]['system']);
        $this->assertEquals(25, $row['subcontractors'][0]['billed_percent']);
        $this->assertEquals(1000000, $data['totals']['value_total']);
    }

    public function test_only_project_manager_and_above_see_the_dashboard(): void
    {
        foreach (['Project Manager', 'Senior Project Manager', 'Operations Manager', 'Management', 'Super Admin'] as $role) {
            $this->signInAs($role);
            $this->getJson('/api/v1/project-dashboard')->assertOk();
        }
        foreach (['Site Engineer', 'Site Supervisor', 'Project Coordinator', 'Sales', 'HR Admin', 'Employee'] as $role) {
            $this->signInAs($role);
            $this->getJson('/api/v1/project-dashboard')->assertForbidden();
        }
    }

    public function test_the_creator_or_sales_records_billing_per_system(): void
    {
        [$creator] = $this->signInAs('Project Manager');
        $id = $this->postJson('/api/v1/projects', [
            'name' => 'Tower A', 'stages' => [
                ['name' => 'HVAC', 'contract_value' => 300000],
                ['name' => 'Plumbing', 'contract_value' => 100000],
            ],
        ])->assertCreated()->json('data.id');
        $stage = ProjectStage::query()->where('project_id', $id)->where('name', 'HVAC')->firstOrFail();
        $this->assertEquals(75, (float) $stage->weight);

        $this->putJson("/api/v1/projects/{$id}/stages/{$stage->id}/billing", ['billed_amount' => 90000])->assertOk();

        $this->signInAs('Sales');
        $this->putJson("/api/v1/projects/{$id}/stages/{$stage->id}/billing", ['billed_amount' => 120000])->assertOk();
        $this->assertEquals(120000, (float) $stage->refresh()->billed_amount);

        $this->signInAs('Site Engineer');
        $this->putJson("/api/v1/projects/{$id}/stages/{$stage->id}/billing", ['billed_amount' => 1])->assertForbidden();
    }

    public function test_a_senior_pm_edits_his_pms_projects_only_and_operations_reassigns_any(): void
    {
        [$seniorUser, $senior] = $this->makeSeat('Senior Project Manager');
        [, $pm] = $this->makeSeat('Project Manager');
        $pm->update(['reporting_manager_id' => $senior->id]);
        [, $otherPm] = $this->makeSeat('Project Manager');
        [, $newPm] = $this->makeSeat('Project Manager');

        $mine = Project::factory()->create(['project_manager_id' => $pm->id, 'created_by' => null]);
        $theirs = Project::factory()->create(['project_manager_id' => $otherPm->id, 'created_by' => null]);

        $this->become($seniorUser);
        $this->putJson("/api/v1/projects/{$mine->id}", ['name' => 'Renamed by senior'])->assertOk();
        $this->putJson("/api/v1/projects/{$theirs->id}", ['name' => 'Nope'])->assertForbidden();
        $this->putJson("/api/v1/projects/{$mine->id}/manager", ['project_manager_id' => $newPm->id])->assertOk();
        $this->putJson("/api/v1/projects/{$theirs->id}/manager", ['project_manager_id' => $newPm->id])->assertForbidden();

        $this->signInAs('Operations Manager');
        $this->putJson("/api/v1/projects/{$theirs->id}/manager", ['project_manager_id' => $newPm->id])
            ->assertOk()->assertJsonPath('data.project_manager_id', $newPm->id);
        $this->putJson("/api/v1/projects/{$theirs->id}", ['name' => 'Ops edit'])->assertForbidden();

        $this->signInAs('Project Manager');
        $this->putJson("/api/v1/projects/{$theirs->id}/manager", ['project_manager_id' => $newPm->id])->assertForbidden();
    }

    public function test_a_coordinator_keeps_paperwork_on_assigned_projects_only(): void
    {
        $project = $this->mepProject();
        $other = Project::factory()->create();
        [$user, $coordinator] = $this->signInAs('Project Coordinator');
        ProjectMember::query()->create(['project_id' => $project->id, 'employee_id' => $coordinator->id, 'role' => ProjectMember::ROLE_COORDINATOR]);

        // Sees only the assigned project.
        $ids = collect($this->getJson('/api/v1/projects')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($project->id));
        $this->assertFalse($ids->contains($other->id));

        $this->postJson("/api/v1/projects/{$project->id}/events", ['type' => 'dcd_inspection', 'event_date' => '2026-11-01'])->assertCreated();
        $this->post("/api/v1/projects/{$project->id}/documents", [
            'title' => 'Permit', 'category' => 'permit', 'file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $sub = ProjectSubcontractor::query()->firstOrFail();
        $this->putJson("/api/v1/projects/{$project->id}/subcontractors/{$sub->id}", [
            'name' => 'Cool Air LLC', 'contact_phone' => '0509999999', 'contract_value' => 1, 'billed_amount' => 1,
        ])->assertOk();
        $sub->refresh();
        $this->assertSame('0509999999', $sub->contact_phone);
        $this->assertEquals(200000, (float) $sub->contract_value);

        // Not the PM's decisions.
        $this->putJson("/api/v1/projects/{$project->id}/stages", ['stages' => [['name' => 'All', 'weight' => 100]]])->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/subcontractors", ['name' => 'New'])->assertForbidden();
        $this->postJson("/api/v1/projects/{$other->id}/events", ['type' => 'dcd_inspection', 'event_date' => '2026-11-01'])->assertForbidden();
    }

    public function test_leave_climbs_pm_then_senior_pm_then_operations_then_hr(): void
    {
        [$hrUser] = $this->makeSeat('HR Admin');
        [$opsUser, $ops] = $this->makeSeat('Operations Manager');
        [$seniorUser, $senior] = $this->makeSeat('Senior Project Manager', ['reporting_manager_id' => $ops->id]);
        [$pmUser, $pm] = $this->makeSeat('Project Manager', ['reporting_manager_id' => $senior->id]);

        // The PM's leave goes to the Senior PM, then HR.
        $this->become($pmUser);
        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-12', '2026-10-13');
        $chain = $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();
        $chain = $this->getJson("/api/v1/leave/{$draft['id']}")->json('data.approval_chain');
        $this->assertSame($senior->id, $chain[0]['approver_employee_id']);

        $this->become($seniorUser);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertOk()->assertJsonPath('data.current_approval_step', 2);
        $this->become($hrUser);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // The Senior PM's leave goes to Operations.
        $this->become($seniorUser);
        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-20', '2026-10-20');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();
        $this->assertSame($ops->id, $this->getJson("/api/v1/leave/{$draft['id']}")->json('data.approval_chain.0.approver_employee_id'));
        $this->become($opsUser);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertOk();
    }
}
