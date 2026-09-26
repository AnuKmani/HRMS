<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site assignments are append-only history: moving an employee must never
 * overwrite where they used to be.
 */
class AssignmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_records_employee_project_and_site(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();

        $this->assertInstanceOf(Employee::class, $assignment->employee);
        $this->assertInstanceOf(Project::class, $assignment->project);
        $this->assertInstanceOf(Site::class, $assignment->site);
        $this->assertSame($assignment->employee_id, $assignment->employee->id);
        $this->assertSame($assignment->site_id, $assignment->site->id);
    }

    public function test_assignment_links_back_to_its_site_and_employee(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();

        $this->assertTrue($assignment->employee->siteAssignments->contains($assignment));
        $this->assertTrue($assignment->site->assignments->contains($assignment));
        $this->assertTrue($assignment->project->siteAssignments->contains($assignment));
    }

    public function test_moving_site_appends_history_instead_of_overwriting(): void
    {
        $employee = Employee::factory()->create();
        $project = Project::factory()->create();

        $siteA = Site::factory()->create(['project_id' => $project->id]);
        $siteB = Site::factory()->create(['project_id' => $project->id]);

        // Original placement.
        $first = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $siteA->id,
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
            'start_date' => '2026-01-01',
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        // Move: close the old row, insert a new one.
        $this->assertTrue($first->end('2026-06-30'));

        $second = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $siteB->id,
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
            'start_date' => '2026-07-01',
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        $history = $employee->siteAssignments()->orderBy('start_date')->get();

        // Two rows, both intact — the first still points at site A.
        $this->assertCount(2, $history);
        $this->assertSame($siteA->id, $history[0]->site_id);
        $this->assertSame($siteB->id, $history[1]->site_id);

        // Statuses/dates of the closed row were changed in place, not deleted.
        $first->refresh();
        $this->assertSame(EmployeeSiteAssignment::STATUS_ENDED, $first->status);
        $this->assertSame('2026-06-30', $first->end_date->toDateString());
        $this->assertDatabaseHas('employee_site_assignments', ['id' => $first->id]);
    }

    public function test_current_assignment_is_the_latest_active_one(): void
    {
        $employee = Employee::factory()->create();
        $project = Project::factory()->create();
        $site = Site::factory()->create(['project_id' => $project->id]);

        EmployeeSiteAssignment::factory()->ended()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'start_date' => '2025-01-01',
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'start_date' => '2026-05-01',
        ]);

        $this->assertSame(
            '2026-05-01',
            $employee->currentSiteAssignment->start_date->toDateString(),
        );
    }

    public function test_assignment_history_survives_soft_deletion_of_site(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();
        $assignment->site->delete();

        // Soft-deleted site: the historical row is untouched and still
        // resolvable via the trashed model.
        $this->assertDatabaseHas('employee_site_assignments', ['id' => $assignment->id]);
        $this->assertTrue($assignment->site->trashed());
    }

    public function test_assignment_history_blocks_hard_deletion_of_site(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();

        $this->expectException(QueryException::class);

        $assignment->site->forceDelete();
    }

    public function test_assignment_history_blocks_hard_deletion_of_employee(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();

        $this->expectException(QueryException::class);

        $assignment->employee->forceDelete();
    }

    public function test_created_by_records_who_made_the_assignment(): void
    {
        $hrUser = User::factory()->create();
        $assignment = EmployeeSiteAssignment::factory()->create(['created_by' => $hrUser->id]);

        $this->assertSame($hrUser->id, $assignment->created_by);
        $this->assertSame($hrUser->id, $assignment->creator->id);
    }

    public function test_created_by_is_optional_for_system_imports(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create(['created_by' => null]);

        $this->assertNull($assignment->created_by);
        $this->assertNull($assignment->creator);
    }

    public function test_assignment_type_and_status_vocabularies_are_enforced(): void
    {
        $this->assertEqualsCanonicalizing(
            ['primary', 'temporary', 'additional'],
            EmployeeSiteAssignment::TYPES,
        );
        $this->assertEqualsCanonicalizing(
            ['active', 'ended', 'cancelled'],
            EmployeeSiteAssignment::STATUSES,
        );
    }

    public function test_end_helper_is_idempotent_and_dates_the_row(): void
    {
        $assignment = EmployeeSiteAssignment::factory()->create();
        $this->assertTrue($assignment->isActive());

        $this->assertTrue($assignment->end('2026-03-15'));
        $this->assertFalse($assignment->fresh()->isActive());
        $this->assertSame('2026-03-15', $assignment->fresh()->end_date->toDateString());
    }
}
