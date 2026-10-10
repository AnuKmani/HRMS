<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Project;
use App\Models\ProjectPaymentMilestone;
use App\Models\ProjectStage;
use App\Models\ProjectSubcontractor;
use App\Services\Projects\ProjectProgressService;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsSiteReports;
use Tests\TestCase;

/**
 * Projects run by the Project Manager who added them: subcontractors,
 * team, stage-based progress fed by daily reports, payment milestones for
 * Sales, documents and the progress PDF.
 */
class ProjectDeliveryTest extends TestCase
{
    use BuildsSiteReports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSiteReports();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Marina Heights',
            'client' => 'Emaar',
            'main_contractor' => 'ALEC',
            'location' => 'Dubai Marina',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'subcontractors' => [
                ['name' => 'Cool Air LLC', 'trade' => 'HVAC', 'contact_name' => 'Sam', 'contact_phone' => '0501234567'],
            ],
        ];
    }

    public function test_a_project_manager_adds_a_project_with_auto_code_template_stages_and_team(): void
    {
        [$user, $pm] = $this->signInAs('Project Manager');
        [, $engineer] = $this->makeSeat('Site Engineer');
        [, $supervisor] = $this->makeSeat('Site Supervisor');

        $candidates = array_column($this->getJson('/api/v1/project-team-candidates')->assertOk()->json('data.items'), 'id');
        $this->assertContains($engineer->id, $candidates);
        $this->assertContains($supervisor->id, $candidates);

        $data = $this->postJson('/api/v1/projects', $this->payload([
            'site_engineer_ids' => [$engineer->id],
            'site_supervisor_ids' => [$supervisor->id],
        ]))->assertCreated()->json('data');

        $this->assertSame('PRJ-0001', $data['code']);
        $this->assertSame('ALEC', $data['main_contractor']);
        $this->assertSame($user->name, $data['created_by_name']);
        $this->assertTrue($data['can_edit']);
        $this->assertSame($pm->id, $data['project_manager']['id']);
        $this->assertCount(count(ProjectStage::TEMPLATE), $data['stages']);
        $this->assertEquals(100, array_sum(array_column($data['stages'], 'weight')));
        $this->assertSame('Cool Air LLC', $data['subcontractors'][0]['name']);
        $this->assertEqualsCanonicalizing(['site_engineer', 'site_supervisor'], array_column($data['team'], 'role'));

        $this->assertSame('PRJ-0002', $this->postJson('/api/v1/projects', $this->payload(['name' => 'Second', 'subcontractors' => []]))->json('data.code'));
    }

    public function test_a_duplicate_name_is_refused_naming_the_project_and_its_manager(): void
    {
        $this->signInAs('Project Manager', ['first_name' => 'Ravi', 'middle_name' => null, 'last_name' => 'Menon']);
        $this->postJson('/api/v1/projects', $this->payload())->assertCreated();

        $this->signInAs('Project Manager');
        $message = $this->postJson('/api/v1/projects', $this->payload(['name' => '  marina   HEIGHTS ']))
            ->assertUnprocessable()->json('errors.name.0');

        $this->assertStringContainsString('This project is already added: Marina Heights (PRJ-0001)', $message);
        $this->assertStringContainsString('Ravi Menon', $message);
    }

    public function test_only_the_creator_edits_but_every_project_manager_sees_it(): void
    {
        [$creator] = $this->signInAs('Project Manager');
        $id = $this->postJson('/api/v1/projects', $this->payload())->json('data.id');

        $this->signInAs('Project Manager');
        $this->getJson('/api/v1/projects/'.$id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->putJson('/api/v1/projects/'.$id, ['name' => 'Taken over'])->assertForbidden();
        $this->postJson("/api/v1/projects/{$id}/subcontractors", ['name' => 'X'])->assertForbidden();
        $this->assertTrue(collect($this->getJson('/api/v1/projects')->json('data.items'))->contains('id', $id));

        $this->become($creator);
        $this->putJson('/api/v1/projects/'.$id, ['name' => 'Marina Heights Phase 1'])->assertOk();

        $this->signInAs('Super Admin');
        $this->putJson('/api/v1/projects/'.$id, ['name' => 'Marina Heights P1'])->assertOk();
    }

    public function test_a_subcontractor_is_removed_with_a_reason_kept_in_history_and_can_be_restored(): void
    {
        $this->signInAs('Project Manager');
        $project = $this->postJson('/api/v1/projects', $this->payload())->json('data');
        $subId = $project['subcontractors'][0]['id'];

        $this->postJson("/api/v1/projects/{$project['id']}/subcontractors/{$subId}/remove", [])->assertUnprocessable();
        $this->postJson("/api/v1/projects/{$project['id']}/subcontractors/{$subId}/remove", [
            'reason' => 'Delayed works', 'removed_on' => '2026-09-20',
        ])->assertOk()->assertJsonPath('data.status', 'removed')->assertJsonPath('data.removed_reason', 'Delayed works');

        $rows = $this->getJson('/api/v1/projects/'.$project['id'])->json('data.subcontractors');
        $this->assertSame('2026-09-20', $rows[0]['removed_on']);

        $this->postJson("/api/v1/projects/{$project['id']}/subcontractors/{$subId}/restore")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, ProjectSubcontractor::query()->count());

        // Contract / LPO document.
        $this->post("/api/v1/projects/{$project['id']}/subcontractors/{$subId}/document", [
            'file' => UploadedFile::fake()->create('lpo.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_document', true);
        $this->get("/api/v1/projects/{$project['id']}/subcontractors/{$subId}/document")->assertOk();
    }

    public function test_a_submitted_daily_report_moves_progress_and_alerts_sales_at_a_milestone(): void
    {
        $world = $this->world('Site Supervisor');
        $project = $world['project'];
        app(ProjectService::class)->replaceStages($project, [
            ['name' => 'Foundation', 'weight' => 40],
            ['name' => 'Structure', 'weight' => 60],
        ]);
        [$foundation, $structure] = $project->stages()->get()->all();
        ProjectPaymentMilestone::query()->create([
            'project_id' => $project->id, 'name' => 'Foundation complete', 'trigger_percent' => 40, 'status' => 'pending',
        ]);
        [$sales] = $this->makeSeat('Sales');

        $this->become($world['user']);
        $this->getJson("/api/v1/projects/{$project->id}/stages")->assertOk()->assertJsonCount(2, 'data');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'stage_progress' => [['stage_id' => $foundation->id, 'percent' => 100], ['stage_id' => $structure->id, 'percent' => 10]],
        ]))->assertCreated()->assertJsonCount(2, 'data.stage_progress')->json('data.id');

        // A draft does not count yet.
        $this->assertEquals(0, $project->refresh()->progress_percent);

        $this->postJson("/api/v1/daily-site-reports/{$id}/submit")->assertOk();

        $this->assertEquals(46, $project->refresh()->progress_percent);
        $this->assertSame('reached', ProjectPaymentMilestone::query()->first()->status);
        $this->assertTrue(InAppNotification::query()->where('user_id', $sales->id)->where('type', 'project.milestone_reached')->exists());

        // A same-day correction moves it again.
        $this->putJson("/api/v1/daily-site-reports/{$id}", [
            'stage_progress' => [['stage_id' => $structure->id, 'percent' => 20]],
        ])->assertOk();
        $this->assertEquals(52, $project->refresh()->progress_percent);

        // A stage of another project is refused.
        $other = Project::factory()->create();
        $foreign = ProjectStage::query()->create(['project_id' => $other->id, 'name' => 'X', 'weight' => 100, 'percent_complete' => 0, 'sort_order' => 0]);
        $this->putJson("/api/v1/daily-site-reports/{$id}", [
            'stage_progress' => [['stage_id' => $foreign->id, 'percent' => 20]],
        ])->assertUnprocessable();
    }

    public function test_sales_reads_progress_marks_payments_but_sees_nothing_else(): void
    {
        $project = Project::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        app(ProjectService::class)->replaceStages($project, [['name' => 'All', 'weight' => 100]]);
        $milestone = ProjectPaymentMilestone::query()->create([
            'project_id' => $project->id, 'name' => 'Advance', 'trigger_percent' => 0, 'status' => 'pending',
        ]);
        app(ProjectProgressService::class)->recompute($project);

        $this->signInAs('Sales');

        $row = collect($this->getJson('/api/v1/project-progress')->assertOk()->json('data'))->firstWhere('id', $project->id);
        // 9 months of 12 gone, nothing done: behind.
        $this->assertTrue($row['behind_schedule']);
        $this->assertSame(1, $row['milestones_due']);

        $this->getJson("/api/v1/project-progress/{$project->id}")->assertOk()->assertJsonCount(1, 'data.stages');
        $this->getJson("/api/v1/projects/{$project->id}/progress-history")->assertOk();
        $this->get("/api/v1/project-progress/{$project->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/v1/projects/{$project->id}/milestones/{$milestone->id}/status", ['status' => 'requested'])
            ->assertOk()->assertJsonPath('data.status', 'requested');
        $this->postJson("/api/v1/projects/{$project->id}/milestones/{$milestone->id}/status", ['status' => 'paid'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Nothing beyond progress.
        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->id}/documents")->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->id}/stages", ['stages' => [['name' => 'A', 'weight' => 100]]])->assertForbidden();
    }

    public function test_the_creator_corrects_a_stage_and_manages_stages_milestones_and_documents(): void
    {
        $this->signInAs('Project Manager');
        $id = $this->postJson('/api/v1/projects', $this->payload(['subcontractors' => []]))->json('data.id');
        $stages = $this->getJson("/api/v1/projects/{$id}/stages")->json('data');

        $this->postJson("/api/v1/projects/{$id}/stages/{$stages[0]['id']}/correct", ['percent' => 100, 'note' => 'Site walk'])
            ->assertOk()->assertJsonPath('data.progress_percent', 5);
        $this->assertSame('manual', $this->getJson("/api/v1/projects/{$id}/progress-history")->json('data.0.source'));

        $this->putJson("/api/v1/projects/{$id}/stages", ['stages' => [['name' => 'A', 'weight' => 60], ['name' => 'B', 'weight' => 30]]])
            ->assertUnprocessable();
        $this->putJson("/api/v1/projects/{$id}/stages", ['stages' => [
            ['id' => $stages[0]['id'], 'name' => 'Mobilisation', 'weight' => 10],
            ['name' => 'Everything else', 'weight' => 90],
        ]])->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals(10, Project::query()->find($id)->progress_percent);

        $this->postJson("/api/v1/projects/{$id}/milestones", ['name' => 'Advance 10%', 'trigger_percent' => 5, 'amount_percent' => 10])
            ->assertCreated()->assertJsonPath('data.status', 'reached');

        $doc = $this->post("/api/v1/projects/{$id}/documents", [
            'title' => 'Ground floor plan', 'category' => 'drawing',
            'file' => UploadedFile::fake()->create('gf.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/projects/{$id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/projects/{$id}/documents/{$doc}")->assertOk();
        $this->deleteJson("/api/v1/projects/{$id}/documents/{$doc}")->assertOk();
    }
}
