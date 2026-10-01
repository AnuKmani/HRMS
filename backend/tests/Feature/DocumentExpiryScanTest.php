<?php

namespace Tests\Feature;

use App\Events\EmployeeDocumentExpired;
use App\Events\EmployeeDocumentExpiring;
use App\Jobs\ScanDocumentExpiries;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The nightly pass that decides when a passport has lapsed — and the property
 * that makes it safe to run from cron nobody watches.
 *
 * Three things are being proved here, in this order because each one depends
 * on the last:
 *
 *  - **the date decides, not the screen.** Nothing about a lapse may wait for
 *    somebody to open the app, so the stored status is written by this pass
 *    while the state a list draws is computed from the same date on the way
 *    out. A document can therefore be *reported* as expired hours before the
 *    scan records it, and that is correct rather than inconsistent.
 *  - **the window is the type's.** Sixty days is a warning for an Emirates ID
 *    configured at ninety and silence for the same document configured at
 *    forty-five — one row, changed once, no deploy.
 *  - **running it twice costs nothing.** The select is the guard: a converted
 *    row no longer matches the expire select and a warned row has its marker,
 *    so the second pass reads nothing. That is what lets the schedule be
 *    `dailyAt` with `withoutOverlapping` *and* `ShouldBeUnique` without any
 *    of the three having to be the only one holding.
 *
 * Nothing here asserts that a reminder was *delivered*, deliberately. The
 * events are hooks for a later notification phase and FCM is not wired up;
 * a test that asserted a message went out would be asserting a feature this
 * build does not have.
 */
class DocumentExpiryScanTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentStack();

        Storage::fake('local');
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* ------------------------------------------------------------- the pass */

    public function test_a_lapsed_document_is_expired_and_said_to_be_exactly_once(): void
    {
        Event::fake([EmployeeDocumentExpired::class, EmployeeDocumentExpiring::class]);

        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $lapsed = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(5)->toDateString(),
        ]), $target);

        $coming = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->addDays(10)->toDateString(),
        ]), $target);

        $this->assertSame(
            'pending',
            EmployeeDocument::query()->find($lapsed['id'])->status,
            'The upload does not get to decide this — only the calendar does, and only once looked at.',
        );

        $first = $this->scan();

        $this->assertSame(['expired' => 1, 'warned' => 1], $first);
        $this->assertSame('expired', EmployeeDocument::query()->find($lapsed['id'])->status);
        $this->assertNotNull(EmployeeDocument::query()->find($lapsed['id'])->expiry_notified_at);
        $this->assertSame('pending', EmployeeDocument::query()->find($coming['id'])->status);
        $this->assertNotNull(EmployeeDocument::query()->find($coming['id'])->expiry_notified_at);

        Event::assertDispatchedTimes(EmployeeDocumentExpired::class, 1);
        Event::assertDispatchedTimes(EmployeeDocumentExpiring::class, 1);

        $second = $this->scan();

        $this->assertSame(
            ['expired' => 0, 'warned' => 0],
            $second,
            'A second run finds nothing to say, which is the whole reason the schedule may overlap.',
        );
        Event::assertDispatchedTimes(EmployeeDocumentExpired::class, 1);
        Event::assertDispatchedTimes(EmployeeDocumentExpiring::class, 1);
    }

    public function test_the_window_a_warning_uses_belongs_to_the_type_not_to_a_constant(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $this->reconfigureType('EMIRATES_ID', ['expiry_warning_days' => 45]);

        $document = $this->fileDocument([
            'document_type_id' => $this->documentType('EMIRATES_ID')->id,
            'document_number' => '784-1990-1234567-1',
            'issue_date' => '2024-01-01',
            'expiry_date' => today()->addDays(60)->toDateString(),
        ], $target);

        $this->assertSame(
            0,
            $this->scan()['warned'],
            'Sixty days out is outside a forty-five day notice, and no other type gets a say.',
        );

        $this->reconfigureType('EMIRATES_ID', ['expiry_warning_days' => 90]);

        $this->assertSame(
            1,
            $this->scan()['warned'],
            'One row changed, so the notice changed with it — no service, no constant, no deploy.',
        );
        $this->assertNotNull(
            EmployeeDocument::query()->find($document['id'])->expiry_notified_at,
        );
    }

    public function test_re_dating_a_document_earns_a_fresh_warning(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $document = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->addDays(5)->toDateString(),
        ]), $target);

        $this->assertSame(1, $this->scan()['warned']);
        $this->assertSame(0, $this->scan()['warned']);

        // Pushed beyond the passport's own window: the marker is withdrawn
        // with the claim it was stamped for, so the next pass is silent
        // rather than reminding somebody about a date that no longer
        // threatens anything.
        $this->putJson('/api/v1/employee-documents/'.$document['id'], [
            'expiry_date' => today()->addDays(400)->toDateString(),
        ])->assertOk();

        $this->assertSame(0, $this->scan()['warned']);

        // Brought back inside it: warned again, because the reason to warn
        // has come back.
        $this->putJson('/api/v1/employee-documents/'.$document['id'], [
            'expiry_date' => today()->addDays(8)->toDateString(),
        ])->assertOk();

        $this->assertSame(1, $this->scan()['warned']);
    }

    public function test_a_refused_document_and_an_archived_one_are_left_alone(): void
    {
        $this->signInAs('HR Admin');
        $one = Employee::factory()->create();
        $two = Employee::factory()->create();

        $refused = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(3)->toDateString(),
        ]), $one);

        $this->postJson('/api/v1/employee-documents/'.$refused['id'].'/reject', [
            'reason' => 'This is a scan of somebody else\'s passport.',
        ])->assertOk();

        $archived = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(3)->toDateString(),
        ]), $two);

        $this->deleteJson('/api/v1/employee-documents/'.$archived['id'])->assertOk();

        $result = $this->scan();

        $this->assertSame(
            ['expired' => 0, 'warned' => 0],
            $result,
            'Lapsing is not an answer to "was this ever accepted" or to "is this still in circulation".',
        );
        $this->assertSame('rejected', EmployeeDocument::query()->find($refused['id'])->status);
        $this->assertSame('archived', EmployeeDocument::query()->find($archived['id'])->status);
    }

    /* ------------------------------------------------------------ scheduled */

    public function test_the_scan_is_scheduled_rather_than_left_to_somebody_pressing_a_button(): void
    {
        $job = new ScanDocumentExpiries;

        $this->assertInstanceOf(ShouldQueue::class, $job, 'The server runs it, not a request.');
        $this->assertInstanceOf(ShouldBeUnique::class, $job, 'Two overlapping runs must not both report a lapse.');

        $event = collect(app(Schedule::class)->events())
            ->first(fn (object $event): bool => str_contains((string) $event->description, 'ScanDocumentExpiries'));

        $this->assertNotNull($event, 'A scan nobody has scheduled is a scan that will not run.');

        [$minute, $hour] = explode(' ', $event->expression);

        $this->assertSame((string) (int) config('hrms.expiry.scan_minute'), $minute);
        $this->assertSame((string) (int) config('hrms.expiry.scan_hour'), $hour);
    }

    public function test_the_job_runs_from_the_queue_as_well_as_from_the_cron_line(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $document = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDay()->toDateString(),
        ]), $target);

        // `dispatchSync` puts a queued job through the sync connection rather
        // than calling handle() itself, so this is the queue's own path —
        // payload, middleware, handler — with no worker to wait for.
        app(Dispatcher::class)->dispatchSync(new ScanDocumentExpiries);

        $this->assertSame(
            'expired',
            EmployeeDocument::query()->find($document['id'])->status,
            'The job is a job: it has to work when the queue runs it, not only when a test calls it.',
        );
    }

    /* -------------------------------------------------------------- private */

    /**
     * @return array{expired: int, warned: int}
     */
    private function scan(): array
    {
        return app(Dispatcher::class)->dispatchNow(new ScanDocumentExpiries);
    }
}
