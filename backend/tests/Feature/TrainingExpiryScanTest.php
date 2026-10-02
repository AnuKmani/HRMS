<?php

namespace Tests\Feature;

use App\Events\EmployeeTrainingExpired;
use App\Events\EmployeeTrainingExpiring;
use App\Jobs\ScanTrainingExpiries;
use App\Models\EmployeeTraining;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTrainingAssets;
use Tests\TestCase;

/**
 * The nightly pass that decides when a training certificate has lapsed —
 * and the property that makes it safe to run from cron nobody watches.
 *
 * Three things are being proved here, in this order because each depends on
 * the last:
 *
 *  - **the date decides, not the screen.** Nothing about a lapse may wait
 *    for somebody to open the app. The stored `expired` status is written by
 *    this pass while the state a list draws is computed from the same date
 *    on the way out — so a certificate can be *reported* as expired hours
 *    before the scan records it, and that is correct rather than
 *    inconsistent.
 *  - **the window is the shared one.** Sixty days is silence and ten days is
 *    a warning, from `hrms.expiry.default_warning_days` — one number read by
 *    the scan, the list filter and the Flutter chip alike. A training
 *    certificate is not a document type, so giving it a window of its own
 *    would be adding a second number that has to be kept in step with the
 *    first.
 *  - **running it twice costs nothing.** The select is the guard: a
 *    converted row no longer matches the expire select and a warned row
 *    carries its marker, so the second pass reads nothing. That is what lets
 *    the schedule be `dailyAt` with `withoutOverlapping` *and*
 *    `ShouldBeUnique` without any of the three having to be the only one
 *    holding.
 *
 * Nothing here asserts that a reminder was *delivered*, deliberately. The
 * two events are hooks for a later phase and FCM is not wired up; a test
 * that asserted a message went out would be asserting a feature this build
 * does not have.
 */
class TrainingExpiryScanTest extends TestCase
{
    use BuildsTrainingAssets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrainingAssetStack();

        Storage::fake('local');
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* ------------------------------------------------------------- the pass */

    public function test_a_lapsed_certificate_is_expired_and_said_to_be_exactly_once(): void
    {
        Event::fake([EmployeeTrainingExpired::class, EmployeeTrainingExpiring::class]);

        $this->signInAs('HR Admin');
        [, $one] = $this->makeSeat('Employee');
        [, $two] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $lapsed = $this->makeTraining($one, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subYear()->toDateString(),
            'certificate_expiry_date' => today()->subDays(5)->toDateString(),
        ]);

        $coming = $this->makeTraining($two, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => today()->subMonths(11)->toDateString(),
            'certificate_expiry_date' => today()->addDays(10)->toDateString(),
        ]);

        $this->assertSame(
            EmployeeTraining::STATUS_COMPLETED,
            EmployeeTraining::query()->find($lapsed->id)->status,
            'The row does not get to decide this — only the calendar does, and only once looked at.',
        );

        $first = $this->scan();

        $this->assertSame(['expired' => 1, 'warned' => 1], $first);
        $this->assertSame(
            EmployeeTraining::STATUS_EXPIRED,
            EmployeeTraining::query()->find($lapsed->id)->status,
            'A certificate lapsing does not un-take the course: the completion, the result and the trainer all stay.',
        );
        $this->assertNotNull(EmployeeTraining::query()->find($lapsed->id)->expiry_notified_at);
        $this->assertSame(
            EmployeeTraining::STATUS_COMPLETED,
            EmployeeTraining::query()->find($coming->id)->status,
        );
        $this->assertNotNull(EmployeeTraining::query()->find($coming->id)->expiry_notified_at);

        Event::assertDispatchedTimes(EmployeeTrainingExpired::class, 1);
        Event::assertDispatchedTimes(EmployeeTrainingExpiring::class, 1);

        $second = $this->scan();

        $this->assertSame(
            ['expired' => 0, 'warned' => 0],
            $second,
            'A second run finds nothing to say, which is the whole reason the schedule may overlap.',
        );
        Event::assertDispatchedTimes(EmployeeTrainingExpired::class, 1);
        Event::assertDispatchedTimes(EmployeeTrainingExpiring::class, 1);
    }

    public function test_the_window_is_the_shared_one_rather_than_a_constant(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $training = $this->makeTraining($learner, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => today()->subMonths(11)->toDateString(),
            'certificate_expiry_date' => today()->addDays(60)->toDateString(),
        ]);

        $this->assertSame(
            0,
            $this->scan()['warned'],
            'Sixty days out is outside a thirty-day notice, and no other number gets a say.',
        );

        config(['hrms.expiry.default_warning_days' => 90]);

        $this->assertSame(
            1,
            $this->scan()['warned'],
            'One configuration value changed, so the notice changed with it — no constant, no deploy.',
        );
        $this->assertNotNull(EmployeeTraining::query()->find($training->id)->expiry_notified_at);
    }

    public function test_re_dating_a_certificate_earns_a_fresh_warning(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $training = $this->makeTraining($learner, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => today()->subMonth()->toDateString(),
            'certificate_expiry_date' => today()->addDays(5)->toDateString(),
        ]);

        $this->assertSame(1, $this->scan()['warned']);
        $this->assertSame(0, $this->scan()['warned']);

        // Pushed beyond the window: the marker is withdrawn with the claim
        // it was stamped for, so the next pass is silent rather than
        // reminding somebody about a date that no longer threatens anything.
        $this->putJson('/api/v1/employee-training/'.$training->id, [
            'certificate_expiry_date' => today()->addDays(400)->toDateString(),
        ])->assertOk();

        $this->assertSame(0, $this->scan()['warned']);

        // Brought back inside it: warned again, because the reason to warn
        // has come back.
        $this->putJson('/api/v1/employee-training/'.$training->id, [
            'certificate_expiry_date' => today()->addDays(8)->toDateString(),
        ])->assertOk();

        $this->assertSame(1, $this->scan()['warned']);
    }

    public function test_nothing_that_never_produced_a_certificate_can_lapse(): void
    {
        $this->signInAs('HR Admin');
        [, $cancelledSeat] = $this->makeSeat('Employee');
        [, $failedSeat] = $this->makeSeat('Employee');
        [, $openSeat] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $cancelled = $this->makeTraining($cancelledSeat, $program, [
            'status' => EmployeeTraining::STATUS_CANCELLED,
            'certificate_expiry_date' => today()->subDays(3)->toDateString(),
            'enrollment_date' => today()->subMonth()->toDateString(),
        ]);
        $failed = $this->makeTraining($failedSeat, $program, [
            'status' => EmployeeTraining::STATUS_FAILED,
            'certificate_expiry_date' => today()->subDays(3)->toDateString(),
            'enrollment_date' => today()->subDays(2)->toDateString(),
        ]);
        $open = $this->makeTraining($openSeat, $program, [
            'certificate_expiry_date' => today()->subDays(3)->toDateString(),
            'enrollment_date' => today()->subDays(1)->toDateString(),
        ]);

        $result = $this->scan();

        $this->assertSame(
            ['expired' => 0, 'warned' => 0],
            $result,
            'There is no certificate to lapse, and reporting one would be a bug that reads as a fact.',
        );

        foreach ([$cancelled, $failed, $open] as $row) {
            $this->assertNotNull($row->fresh(), 'Nothing is removed, and nothing is converted.');
            $this->assertNull(EmployeeTraining::query()->find($row->id)->expiry_notified_at);
        }
    }

    public function test_an_already_expired_certificate_is_not_reported_twice_by_the_second_kind_of_pass(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $this->makeTraining($learner, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => today()->subYear()->toDateString(),
            'certificate_expiry_date' => today()->subDay()->toDateString(),
        ]);

        $this->assertSame(['expired' => 1, 'warned' => 0], $this->scan());

        // The same marker covers both messages: a certificate that has
        // already lapsed never needs warning about too, and one column is
        // what stops the warn select finding it.
        $this->assertSame(['expired' => 0, 'warned' => 0], $this->scan());
    }

    /* ------------------------------------------------------------ scheduled */

    public function test_the_scan_is_scheduled_rather_than_left_to_somebody_pressing_a_button(): void
    {
        $job = new ScanTrainingExpiries;

        $this->assertInstanceOf(ShouldQueue::class, $job, 'The server runs it, not a request.');
        $this->assertInstanceOf(ShouldBeUnique::class, $job, 'Two overlapping runs must not both report a lapse.');

        $event = collect(app(Schedule::class)->events())
            ->first(fn (object $event): bool => str_contains((string) $event->description, 'ScanTrainingExpiries'));

        $this->assertNotNull($event, 'A scan nobody has scheduled is a scan that will not run.');

        [$minute, $hour] = explode(' ', $event->expression);

        $this->assertSame((string) (int) config('hrms.expiry.training_scan_minute'), $minute);
        $this->assertSame((string) (int) config('hrms.expiry.training_scan_hour'), $hour);
    }

    public function test_the_job_runs_from_the_queue_as_well_as_from_the_cron_line(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $training = $this->makeTraining($learner, $program, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => today()->subYear()->toDateString(),
            'certificate_expiry_date' => today()->subDay()->toDateString(),
        ]);

        // `dispatchSync` puts a queued job through the sync connection
        // rather than calling handle() itself, so this is the queue's own
        // path — payload, middleware, handler — with no worker to wait for.
        app(Dispatcher::class)->dispatchSync(new ScanTrainingExpiries);

        $this->assertSame(
            EmployeeTraining::STATUS_EXPIRED,
            EmployeeTraining::query()->find($training->id)->status,
            'The job is a job: it has to work when the queue runs it, not only when a test calls it.',
        );
    }

    /* -------------------------------------------------------------- private */

    /**
     * @return array{expired: int, warned: int}
     */
    private function scan(): array
    {
        return app(Dispatcher::class)->dispatchNow(new ScanTrainingExpiries);
    }
}
