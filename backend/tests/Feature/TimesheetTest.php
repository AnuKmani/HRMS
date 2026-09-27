<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * GET /api/v1/timesheets, GET /api/v1/timesheets/{id}, POST /timesheets/generate.
 *
 * The whole file rests on one claim: a timesheet is *derived*, not written.
 * Attendance owns the day, immutably, and TimesheetService copies it into a
 * row a period report can index. So the tests check that the copy is faithful,
 * that the derivation can be run twice without producing a second answer, and
 * that reading it obeys the same scope the attendance it came from obeys —
 * because two rules for "whose working day is this" would eventually disagree,
 * and the collection is the easier endpoint to hit.
 *
 * There is also no endpoint here that could author a row, and the last test
 * says so out loud.
 */
class TimesheetTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private Project $project;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();

        $this->project = Project::factory()->create();
        $this->site = Site::factory()->create(['project_id' => $this->project->id]);
    }

    /* --------------------------------------------------------- derivation */

    public function test_a_timesheet_is_a_faithful_copy_of_one_attendance_day(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        [, $employee] = $this->signInAs('Employee');

        $attendance = $this->attendance($employee, $this->site, '2026-09-25', [
            'working_minutes' => 495,
            'break_minutes' => 45,
            'overtime_minutes' => 15,
            'status' => Attendance::STATUS_MANUALLY_ADJUSTED,
        ]);

        $this->become($hr);
        $this->assertSame(1, $this->generate('2026-09-25', '2026-09-27')['count']);

        $sheet = Timesheet::query()->firstOrFail();

        $this->assertSame($attendance->id, $sheet->attendance_id, 'Every row points at the day it came from.');
        $this->assertSame($employee->id, $sheet->employee_id);
        $this->assertSame('2026-09-25', $sheet->timesheet_date->toDateString());
        $this->assertSame($this->project->id, $sheet->project_id);
        $this->assertSame($this->site->id, $sheet->site_id);

        // Copied, never recomputed. A timesheet that recalculated 495 minutes
        // from 09:00–18:00 minus a 45-minute break would produce a different
        // number than the row attendance already committed to.
        $this->assertSame(495, $sheet->working_minutes);
        $this->assertSame(45, $sheet->break_minutes);
        $this->assertSame(15, $sheet->overtime_minutes);
        $this->assertNotNull($sheet->check_in_at);
        $this->assertNotNull($sheet->check_out_at);

        $this->assertSame(Timesheet::STATUS_COMPLETE, $sheet->status);
    }

    public function test_generating_the_same_window_twice_refreshes_rather_than_duplicates(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        [, $employee] = $this->signInAs('Employee');

        $this->attendance($employee, $this->site, '2026-09-25');

        $this->become($hr);
        $this->assertSame(1, $this->generate('2026-09-25', '2026-09-27')['count']);

        $sheet = Timesheet::query()->firstOrFail();

        // The point of `updateOrCreate` on (employee, date): a manager who is
        // not sure whether somebody else already ran this can press it again.
        $this->assertSame(1, $this->generate('2026-09-25', '2026-09-27')['count']);
        $this->assertSame(1, Timesheet::query()->count());
        $this->assertSame($sheet->id, Timesheet::query()->firstOrFail()->id);
    }

    public function test_the_status_is_a_property_of_the_day_not_of_the_request(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        [, $employee] = $this->signInAs('Employee');

        // A full day.
        $this->attendance($employee, $this->site, '2026-09-21');
        // Still checked in — the day is running.
        $this->attendance($employee, $this->site, '2026-09-22', [
            'check_out_at' => null,
            'working_minutes' => 0,
            'break_minutes' => 0,
        ]);
        // Closed, but attendance recorded it as incomplete.
        $this->attendance($employee, $this->site, '2026-09-23', [
            'status' => Attendance::STATUS_INCOMPLETE,
            'working_minutes' => 0,
        ]);
        // Closed, no usable working time, and attendance never marked it.
        $this->attendance($employee, $this->site, '2026-09-24', [
            'working_minutes' => 0,
        ]);

        $this->become($hr);
        $this->generate('2026-09-21', '2026-09-24');

        $statuses = Timesheet::query()
            ->orderBy('timesheet_date')
            ->pluck('status', 'timesheet_date')
            ->all();

        $this->assertSame([
            '2026-09-21' => Timesheet::STATUS_COMPLETE,
            '2026-09-22' => Timesheet::STATUS_OPEN,
            '2026-09-23' => Timesheet::STATUS_INCOMPLETE,
            '2026-09-24' => Timesheet::STATUS_INCOMPLETE,
        ], $statuses);
    }

    public function test_a_range_that_makes_no_sense_is_refused_before_anything_is_written(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $this->postJson('/api/v1/timesheets/generate', [
            'from' => '2026-09-27',
            'to' => '2026-09-25',
        ])->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->postJson('/api/v1/timesheets/generate', [
            'from' => '2024-01-01',
            'to' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->postJson('/api/v1/timesheets/generate', [
            'from' => '27-09-2026',
            'to' => '2026-09-27',
        ])->assertUnprocessable()->assertJsonValidationErrors('from');

        $this->assertSame(0, Timesheet::query()->count());
    }

    /* -------------------------------------------------------- authorization */

    public function test_an_employee_reads_only_their_own_working_days(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];

        [$mineUser, $mine] = $this->signInAs('Employee');
        $this->attendance($mine, $this->site, '2026-09-25');

        $theirs = $this->makeSeat('Employee');
        $this->attendance($theirs[1], $this->site, '2026-09-25');

        // Somebody with the standing to derive a period runs it for everyone.
        $this->become($hr);
        $this->assertSame(2, $this->generate('2026-09-25', '2026-09-25')['count']);

        $this->become($mineUser);
        $items = $this->getJson('/api/v1/timesheets')->assertOk()->json('data.items');
        $this->assertSame(
            [$mine->id],
            array_column($items, 'employee_id'),
            'A colleague\'s working day is not published by the permission that reads your own.',
        );

        $othersSheet = Timesheet::query()->where('employee_id', $theirs[1]->id)->firstOrFail();
        $this->getJson('/api/v1/timesheets/'.$othersSheet->id)->assertForbidden();

        $ownSheet = Timesheet::query()->where('employee_id', $mine->id)->firstOrFail();
        $this->getJson('/api/v1/timesheets/'.$ownSheet->id)->assertOk();
    }

    public function test_a_supervisor_sees_the_sites_they_run_and_no_others(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->site->update(['site_supervisor_id' => $supervisor[1]->id]);

        $elsewhere = Site::factory()->create([
            'project_id' => Project::factory()->create()->id,
            'site_supervisor_id' => null,
            'site_manager_id' => null,
        ]);

        $onTheirSite = Employee::factory()->create([
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);
        $elsewhereIn = Employee::factory()->create([
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);

        $this->attendance($onTheirSite, $this->site, '2026-09-25');
        $this->attendance($elsewhereIn, $elsewhere, '2026-09-25');

        $this->become($supervisor[0]);

        // The *scope* of a generation is decided by the same rule that decides
        // the list, so a supervisor pressing the button writes only rows they
        // could already have read.
        $this->assertSame(1, $this->generate('2026-09-25', '2026-09-25')['count']);

        $items = $this->getJson('/api/v1/timesheets')->assertOk()->json('data.items');
        $this->assertSame([$this->site->id], array_column($items, 'site_id'));

        $outOfScope = Timesheet::query()->where('employee_id', $elsewhereIn->id)->first();
        $this->assertNull($outOfScope, 'A day on a site they do not run was derived for them anyway.');

        $inScope = Timesheet::query()->where('employee_id', $onTheirSite->id)->firstOrFail();
        $this->getJson('/api/v1/timesheets/'.$inScope->id)->assertOk();
    }

    public function test_deriving_a_period_is_an_administrative_act_not_an_ordinary_one(): void
    {
        $this->signInAs('Employee');

        // `timesheets.view` opens the list; `timesheets.manage` is what lets
        // you write into it — and the route says so before any policy is asked.
        $this->getJson('/api/v1/timesheets')->assertOk();
        $this->postJson('/api/v1/timesheets/generate', [
            'from' => '2026-09-25',
            'to' => '2026-09-27',
        ])->assertForbidden();

        $this->assertSame(0, Timesheet::query()->count());
    }

    public function test_there_is_no_endpoint_that_can_author_a_timesheet(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $this->attendance(
            Employee::factory()->create(['primary_project_id' => null, 'primary_site_id' => null]),
            $this->site,
            '2026-09-25',
        );
        $this->generate('2026-09-25', '2026-09-25');

        $sheet = Timesheet::query()->firstOrFail();

        // The route only answers GET, so an edit is a 405 rather than a 403 —
        // there is no ability to authorise because there is no act.
        $this->putJson("/api/v1/timesheets/{$sheet->id}", ['working_minutes' => 999])
            ->assertStatus(405);
        $this->postJson("/api/v1/timesheets/{$sheet->id}/approve", [])
            ->assertNotFound();

        $this->assertSame(
            480,
            Timesheet::query()->findOrFail($sheet->id)->working_minutes,
            'Attendance remains the only place a working day can be changed.',
        );
    }

    /* ------------------------------------------------------------- filters */

    public function test_the_list_narrows_by_date_status_site_project_and_person(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];

        $otherProject = Project::factory()->create();
        $otherSite = Site::factory()->create([
            'project_id' => $otherProject->id,
            'site_supervisor_id' => null,
            'site_manager_id' => null,
        ]);

        [, $first] = $this->signInAs('Employee');
        $second = $this->makeSeat('Employee');

        $this->attendance($first, $this->site, '2026-09-25');
        $this->attendance($first, $this->site, '2026-09-26', [
            'check_out_at' => null,
            'working_minutes' => 0,
            'break_minutes' => 0,
        ]);
        $this->attendance($second[1], $otherSite, '2026-09-27');

        $this->become($hr);
        $this->assertSame(3, $this->generate('2026-09-25', '2026-09-27')['count']);

        $this->assertSame(
            2,
            $this->rows(['from' => '2026-09-26', 'to' => '2026-09-27']),
            'A date window is a filter, not a second way to scope.',
        );
        $this->assertSame(1, $this->rows(['status' => 'open']));
        $this->assertSame(2, $this->rows(['status' => 'complete,incomplete']));
        $this->assertSame(1, $this->rows(['site_id' => $otherSite->id]));
        $this->assertSame(1, $this->rows(['project_id' => $otherProject->id]));
        $this->assertSame(2, $this->rows(['employee_id' => $first->id]));
        $this->assertSame(
            1,
            $this->rows(['site_id' => $this->site->id, 'status' => 'complete', 'employee_id' => $first->id]),
            'Filters compose rather than override one another.',
        );
    }

    /* -------------------------------------------------------------- helpers */

    private function attendance(Employee $employee, Site $site, string $date, array $overrides = []): Attendance
    {
        return Attendance::factory()->create($overrides + [
            'employee_id' => $employee->id,
            'project_id' => $site->project_id,
            'site_id' => $site->id,
            'attendance_date' => $date,
            'check_in_at' => $date.' 09:00:00',
            'check_out_at' => $date.' 18:00:00',
            'scheduled_start_at' => $date.' 09:00:00',
            'scheduled_end_at' => $date.' 18:00:00',
            'status' => Attendance::STATUS_PRESENT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(string $from, string $to, ?int $employeeId = null): array
    {
        return $this->postJson('/api/v1/timesheets/generate', array_filter([
            'from' => $from,
            'to' => $to,
            'employee_id' => $employeeId,
        ], fn ($value) => $value !== null))->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function rows(array $query): int
    {
        return count($this->getJson('/api/v1/timesheets?'.http_build_query($query))
            ->assertOk()
            ->json('data.items'));
    }
}
