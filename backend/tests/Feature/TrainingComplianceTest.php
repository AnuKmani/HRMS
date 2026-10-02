<?php

namespace Tests\Feature;

use App\Models\EmployeeTraining;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTrainingAssets;
use Tests\TestCase;

/**
 * GET /api/v1/training-compliance — one screen's worth of answers.
 *
 * The report is three small summaries rather than one clever one, and the
 * thing worth proving about each is different:
 *
 *  - **the totals are scoped before they are counted.** A project manager
 *    who may read only their own rows gets their own numbers, and the
 *    report never becomes a window onto the whole workforce for somebody
 *    holding `training.view` alone. That is the difference between a
 *    *summary* and a leak — and the totals are the most tempting thing to
 *    compute before the row scope is applied, which is exactly why they are
 *    computed after it.
 *  - **the certificate states come from today, not from a column.** A
 *    completed row whose card expired yesterday counts as `expired` whether
 *    or not the scheduler has caught up, so a lagging cron cannot make this
 *    report optimistic.
 *  - **the catalogue itself is not narrowed.** An operator needs to see the
 *    courses they run, even the ones nobody has sat — but every count
 *    hanging off those rows is, so a programme with no *visible* enrolments
 *    honestly reports zero rather than being hidden.
 */
class TrainingComplianceTest extends TestCase
{
    use BuildsTrainingAssets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrainingAssetStack();

        Storage::fake('local');
        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_hr_sees_the_whole_workforce(): void
    {
        $this->signInAs('HR Admin');
        [, $a] = $this->makeSeat('Employee');
        [, $b] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $this->makeTraining($a, $program);
        $this->makeTraining($b, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'completion_date' => now()->toDateString(),
            'certificate_issue_date' => now()->subYear()->toDateString(),
            'certificate_expiry_date' => now()->subYear()->toDateString(),
        ]);

        $data = $this->getJson('/api/v1/training-compliance')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['totals']['enrollments']);
        $this->assertSame(1, $data['enrollments_by_status']['enrolled']);
        $this->assertSame(1, $data['enrollments_by_status']['completed']);
        $this->assertSame(1, $data['certificates']['expired']);
        $this->assertSame(0, $data['certificates']['valid']);
        $this->assertSame(30, $data['warning_days']);
        $this->assertNotNull($data['generated_at']);

        $listed = collect($data['programs'])->firstWhere('code', $program->code);

        $this->assertNotNull($listed, 'The catalogue is listed whatever the counts say.');
        $this->assertSame(2, $listed['enrollments_count']);
        $this->assertSame(1, $listed['active_enrollments_count']);
    }

    public function test_the_totals_are_scoped_before_they_are_counted(): void
    {
        [, $me] = $this->signInAs('Project Manager');
        [, $colleague] = $this->makeSeat('Employee');

        $mine = $this->makeTrainingProgram(['code' => 'PM-1', 'name' => 'PM Briefing']);
        $theirs = $this->makeTrainingProgram(['code' => 'SITE-1', 'name' => 'Site Induction']);

        $this->makeTraining($me, $mine);
        $this->makeTraining($colleague, $theirs, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subYear()->toDateString(),
            'certificate_expiry_date' => now()->subDays(20)->toDateString(),
        ]);

        $data = $this->getJson('/api/v1/training-compliance')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['totals']['enrollments'], 'One person\'s rows, one person\'s total.');
        $this->assertSame(1, $data['enrollments_by_status']['enrolled']);
        $this->assertSame(0, $data['enrollments_by_status']['completed']);
        $this->assertSame(0, $data['certificates']['expiring_soon']);

        // The catalogue is still complete — an operator needs to see the
        // courses they run — but nothing hanging off it leaks a colleague's
        // activity.
        $listedTheirs = collect($data['programs'])->firstWhere('code', $theirs->code);

        $this->assertNotNull($listedTheirs);
        $this->assertSame(0, $listedTheirs['enrollments_count']);
        $this->assertSame(0, $listedTheirs['active_enrollments_count']);

        $listedMine = collect($data['programs'])->firstWhere('code', $mine->code);

        $this->assertSame(1, $listedMine['enrollments_count']);
    }

    public function test_certificate_states_are_computed_from_today_rather_than_stored(): void
    {
        $this->signInAs('HR Admin');
        [, $one] = $this->makeSeat('Employee');
        [, $two] = $this->makeSeat('Employee');
        [, $three] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $lapsed = $this->makeTraining($one, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subYear()->toDateString(),
            'certificate_expiry_date' => now()->subDay()->toDateString(),
        ]);
        $due = $this->makeTraining($two, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subMonths(11)->toDateString(),
            'certificate_expiry_date' => now()->addDays(15)->toDateString(),
            'enrollment_date' => now()->subMonth()->toDateString(),
        ]);
        $fine = $this->makeTraining($three, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subMonth()->toDateString(),
            'certificate_expiry_date' => now()->addYear()->toDateString(),
            'enrollment_date' => now()->subWeek()->toDateString(),
        ]);

        $data = $this->getJson('/api/v1/training-compliance')->assertOk()->json('data');

        $this->assertSame(
            ['valid' => 1, 'expiring_soon' => 1, 'expired' => 1, 'none' => 0],
            $data['certificates'],
            'All three are still `completed` in storage; the calendar is what puts them in these buckets.',
        );

        foreach ([$lapsed, $due, $fine] as $row) {
            $this->assertSame(
                EmployeeTraining::STATUS_COMPLETED,
                EmployeeTraining::query()->find($row->id)->status,
                'A report reads the date; only the scan gets to write a status.',
            );
        }

        $this->assertSame(3, $data['totals']['certificates'], 'Every row has a card; none is in the `none` bucket.');
    }

    public function test_the_report_is_behind_the_same_door_as_the_list(): void
    {
        $this->getJson('/api/v1/training-compliance')->assertUnauthorized();

        $this->signInAs('Employee');

        // An employee holds `training.view`, so the report is theirs — but
        // it is about *them*, because the counts are scoped before they are
        // summed.
        $this->makeTrainingProgram(['code' => 'BRIEF-1', 'name' => 'Site Briefing']);

        $data = $this->getJson('/api/v1/training-compliance')->assertOk()->json('data');

        $this->assertSame(0, $data['totals']['enrollments']);
        $this->assertGreaterThan(0, count($data['programs']), 'The catalogue itself is not a secret.');
    }
}
