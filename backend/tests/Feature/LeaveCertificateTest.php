<?php

namespace Tests\Feature;

use App\Events\LeaveConvertedToLop;
use App\Jobs\EnforceSickCertificateDeadlines;
use App\Models\ApprovalRecord;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * Sick leave's medical certificate: where it is filed, who may read it, and
 * what happens when it never arrives.
 *
 * The three properties worth proving here are independent of each other, and
 * each has its own way of being forgotten:
 *
 *  - **it is private.** A doctor's note names an illness against a person, so
 *    it goes to the same private disk the attendance selfie does, under a
 *    filename the server mints, and the path never appears in a response.
 *  - **the deadline is server-side.** The date is written when the request is
 *    submitted and enforced by the scheduler — a phone that is switched off
 *    must not decide whether somebody gets paid.
 *  - **running the enforcement twice changes nothing.** An idempotent job is
 *    the only kind that can be scheduled hourly without a second rule saying
 *    "unless it already ran", which nobody would remember to apply.
 */
class LeaveCertificateTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    /** Enough of a PDF to carry the signature CertificateContent looks for. */
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\nstartxref\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();

        Storage::fake('local');
        Storage::fake('public');
    }

    /* ------------------------------------------------------------ deadline */

    public function test_sick_leave_demands_a_certificate_and_the_deadline_runs_from_the_last_day(): void
    {
        $this->signInAs('Employee');

        $sick = $this->filedSickLeave();
        $data = $this->getJson('/api/v1/leave/'.$sick)->assertOk()->json('data');

        $this->assertTrue($data['certificate']['required']);
        $this->assertFalse($data['certificate']['has_file']);
        $this->assertNull($data['certificate']['uploaded_at']);
        $this->assertFalse($data['certificate']['overdue']);

        // Two days after 2026-10-02. Counted from the last day rather than
        // from submission: a request filed a fortnight in advance would be
        // overdue on the day it was written, and a request filed late would
        // get longer than the rule allows.
        $this->assertSame('2026-10-04', $data['certificate']['due_at']);

        // A type that asks for nothing carries no deadline at all — there is
        // no date to be late with.
        $annual = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-12', '2026-10-14');
        $this->postJson('/api/v1/leave/'.$annual['id'].'/submit')->assertOk();

        $annualData = $this->getJson('/api/v1/leave/'.$annual['id'])->assertOk()->json('data');
        $this->assertFalse($annualData['certificate']['required']);
        $this->assertNull($annualData['certificate']['due_at']);
    }

    public function test_the_leave_type_names_its_own_deadline_and_beats_the_organisation_default(): void
    {
        LeaveType::query()->where('code', 'SL')->update(['document_deadline_days' => 4]);

        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $this->assertSame(
            '2026-10-06',
            $this->getJson('/api/v1/leave/'.$id)->assertOk()->json('data.certificate.due_at'),
            'The type asked for 4 days, and the type wins.',
        );
    }

    public function test_the_organisation_default_applies_when_the_type_names_none(): void
    {
        DB::table('settings')
            ->where('key', 'leave.sick_certificate_deadline_days')
            ->update(['value' => '5']);
        LeaveType::query()->where('code', 'SL')->update(['document_deadline_days' => 0]);

        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $this->assertSame(
            '2026-10-07',
            $this->getJson('/api/v1/leave/'.$id)->assertOk()->json('data.certificate.due_at'),
        );
    }

    /* ------------------------------------------------------- storage & read */

    public function test_a_certificate_is_stored_privately_and_its_path_never_leaves_the_server(): void
    {
        [, $employee] = $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $response = $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('dr-sharma-note.pdf', self::PDF),
        ]);

        $response->assertOk();
        $certificate = $response->json('data.certificate');

        $this->assertTrue($certificate['has_file']);
        $this->assertSame('dr-sharma-note.pdf', $certificate['original_name'], 'The reader still needs to recognise their own file.');
        $this->assertSame('application/pdf', $certificate['mime']);
        $this->assertGreaterThan(0, $certificate['size']);
        $this->assertNotNull($certificate['uploaded_at']);

        // The path is metadata the server keeps, not a fact the client is
        // told — a stored path says where every other stored path is.
        $this->assertArrayNotHasKey('path', $certificate);
        $this->assertStringNotContainsString('leave-certificates/', $response->getContent());

        // On disk: the private disk, under a directory the app has no route
        // to, with a filename this server minted rather than the one that
        // arrived on the wire.
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('leave-certificates/'.$employee->id.'/', $files[0]);
        $this->assertStringNotContainsString('dr-sharma-note', $files[0]);

        $this->assertSame([], Storage::disk('public')->allFiles(), 'Nothing reached a disk any URL serves.');

        // Read back through the one authorised endpoint, as an attachment
        // with no-cache headers so a shared machine does not keep it.
        $download = $this->get('/api/v1/leave/'.$id.'/certificate');
        $download->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $download->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $download->headers->get('cache-control'),
        );
    }

    public function test_a_certificate_can_be_read_only_by_someone_who_may_read_the_request(): void
    {
        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        // A request with nothing on it has nothing to download — 404 rather
        // than 403, because the caller is already entitled to look.
        $this->get('/api/v1/leave/'.$id.'/certificate')->assertNotFound();

        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('note.pdf', self::PDF),
        ])->assertOk();

        // A colleague holds `leave.view` for their own history. That is not
        // an invitation to open this one.
        $this->become($this->makeSeat('Employee')[0]);
        $this->get('/api/v1/leave/'.$id)->assertForbidden();
        $this->get('/api/v1/leave/'.$id.'/certificate')->assertForbidden();

        // HR may read the request, and the certificate follows it exactly —
        // there is deliberately no second, weaker "medical documents" grant.
        $this->become($this->makeSeat('HR Admin')[0]);
        $this->get('/api/v1/leave/'.$id.'/certificate')->assertOk();
    }

    public function test_a_file_that_is_not_a_certificate_is_refused_and_nothing_is_written(): void
    {
        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        // A PHP payload wearing a PDF's name. `mimes` and `mimetypes` both
        // read the *name*, so this is exactly what CertificateContent exists
        // to catch.
        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('note.pdf', '<?php system($_GET["c"]);'),
        ])->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // A name outside the allow-list, however plausible its bytes.
        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('notes.txt', self::PDF),
        ])->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The ceiling is a config value, not a constant: this file is a real
        // PDF that only fails on size.
        config(['hrms.storage.certificate_max_kilobytes' => 4]);
        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent(
                'big.pdf',
                str_repeat('%PDF-1.4 a valid-looking line', 2000),
            ),
        ])->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->assertNull(LeaveRequest::query()->findOrFail($id)->certificate_path);
    }

    /* ------------------------------------------------------------- LOP */

    public function test_a_missed_deadline_converts_the_request_to_loss_of_pay(): void
    {
        Event::fake([LeaveConvertedToLop::class]);

        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        // The day after the deadline: still nothing filed.
        $this->travelTo(Carbon::parse('2026-10-05 06:00:00'));

        $this->assertSame(1, $this->enforceDeadlines());

        $leave = LeaveRequest::query()->findOrFail($id);
        $this->assertSame(LeaveRequest::STATUS_LOP, $leave->status);
        $this->assertSame(5.0, (float) $leave->lop_days);
        $this->assertStringContainsString('2026-10-04', (string) $leave->lop_reason);
        $this->assertNotNull($leave->lop_applied_at);
        $this->assertNull($leave->current_approval_step);

        // The chain is closed, not left waiting on somebody for a request
        // that can never be approved again.
        $this->assertSame(
            ['skipped', 'skipped', 'skipped'],
            ApprovalRecord::query()
                ->where('subject_id', $id)
                ->orderBy('sequence')
                ->pluck('status')
                ->all(),
        );

        // The paid reservation goes back: these days are no longer paid sick
        // leave, and holding the pot as well would charge it twice.
        $balance = $this->balance($id);
        $this->assertSame(0.0, (float) $balance->pending);
        $this->assertSame(0.0, (float) $balance->used);

        // The payroll input is data, not a rule to be re-derived later.
        $payload = $this->getJson('/api/v1/leave/'.$id)->assertOk()->json('data');
        $this->assertSame('lop', $payload['status']);
        $this->assertSame(5.0, (float) $payload['lop']['days']);
        $this->assertStringContainsString('2026-10-04', $payload['lop']['reason']);
        $this->assertNotNull($payload['lop']['applied_at']);

        Event::assertDispatched(
            LeaveConvertedToLop::class,
            fn (LeaveConvertedToLop $event) => $event->leaveRequest->id === $id,
        );
    }

    public function test_running_the_deadline_job_again_converts_nothing_a_second_time(): void
    {
        Event::fake([LeaveConvertedToLop::class]);

        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $this->travelTo(Carbon::parse('2026-10-05 06:00:00'));
        $this->assertSame(1, $this->enforceDeadlines());

        $firstAppliedAt = LeaveRequest::query()->findOrFail($id)->lop_applied_at;
        $firstReason = LeaveRequest::query()->findOrFail($id)->lop_reason;

        // Two days later, and the scheduler runs again — hourly is a long
        // time for a rule like this to be re-applied.
        $this->travelTo(Carbon::parse('2026-10-07 06:00:00'));
        $this->assertSame(0, $this->enforceDeadlines());

        $leave = LeaveRequest::query()->findOrFail($id);
        $this->assertSame(LeaveRequest::STATUS_LOP, $leave->status);
        $this->assertSame(
            $firstAppliedAt->toDateTimeString(),
            $leave->lop_applied_at->toDateTimeString(),
            'The timestamp moved, so the row was written twice.',
        );
        $this->assertSame($firstReason, $leave->lop_reason);
        $this->assertSame(5.0, (float) $leave->lop_days);

        $balance = $this->balance($id);
        $this->assertSame(0.0, (float) $balance->pending);
        $this->assertSame(0.0, (float) $balance->used);

        Event::assertDispatchedTimes(LeaveConvertedToLop::class, 1);
    }

    public function test_a_certificate_filed_on_the_deadline_stops_the_conversion(): void
    {
        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        // The due date itself. Late in the day it still counts as on time —
        // `<= today()` — and the upload clears the marker the job reads, so a
        // run that was already queued cannot convert on a stale pass.
        $this->travelTo(Carbon::parse('2026-10-04 23:30:00'));

        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('note.pdf', self::PDF),
        ])->assertOk();

        $this->assertSame(0, $this->enforceDeadlines());

        $leave = LeaveRequest::query()->findOrFail($id);
        $this->assertSame(LeaveRequest::STATUS_PENDING, $leave->status);
        $this->assertNull($leave->lop_applied_at);

        $this->assertSame(5.0, (float) $this->balance($id)->pending, 'The reservation was never given up.');
    }

    public function test_converting_an_approved_request_releases_the_days_it_already_spent(): void
    {
        LeaveType::query()
            ->where('code', 'SL')
            ->update(['approval_workflow_id' => $this->workflowId('LEAVE-FAST')]);

        $hr = $this->makeSeat('HR Admin')[0];
        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $this->become($hr);
        $this->postJson('/api/v1/leave/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $spent = $this->balance($id);
        $this->assertSame(0.0, (float) $spent->pending);
        $this->assertSame(5.0, (float) $spent->used);
        $this->assertSame(5.0, $spent->remaining());

        $this->travelTo(Carbon::parse('2026-10-05 06:00:00'));
        $this->assertSame(1, $this->enforceDeadlines());

        $spent->refresh();
        $this->assertSame(0.0, (float) $spent->used, 'The pot was charged for paid leave that never happened.');
        $this->assertSame(0.0, (float) $spent->pending);
        $this->assertSame(10.0, $spent->remaining());

        $this->assertSame(LeaveRequest::STATUS_LOP, LeaveRequest::query()->findOrFail($id)->status);
    }

    public function test_a_certificate_cannot_be_uploaded_after_the_request_became_lop(): void
    {
        $this->signInAs('Employee');
        $id = $this->filedSickLeave();

        $this->travelTo(Carbon::parse('2026-10-05 06:00:00'));
        $this->assertSame(1, $this->enforceDeadlines());

        $this->post('/api/v1/leave/'.$id.'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('late.pdf', self::PDF),
        ])->assertStatus(409);

        $this->assertNull(LeaveRequest::query()->findOrFail($id)->certificate_path);

        // The same endpoint on a type that never wanted one answers about the
        // *type*, not about permissions — the policy's job was only ever to
        // decide whose request this is.
        $annual = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-12', '2026-10-14');
        $this->post('/api/v1/leave/'.$annual['id'].'/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('not-needed.pdf', self::PDF),
        ])->assertUnprocessable();

        $this->assertNull(LeaveRequest::query()->findOrFail($annual['id'])->certificate_path);
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * File a sick-leave request for the signed-in account and submit it.
     *
     * Submission is what freezes the deadline, so every test in this file
     * starts from a submitted row rather than a draft.
     */
    private function filedSickLeave(string $start = '2026-09-28', string $end = '2026-10-02'): int
    {
        $draft = $this->draftLeave($this->leaveTypeId('SL'), $start, $end, [
            'reason' => 'Flu, off work.',
        ]);

        $this->postJson('/api/v1/leave/'.$draft['id'].'/submit')->assertOk();

        return (int) $draft['id'];
    }

    /**
     * Run the scheduler's job the way routes/console.php would, and return
     * how many requests it converted.
     *
     * Called directly rather than dispatched so the test exercises the query
     * guard itself — the unique lock that stops an overlapping *dispatch* is
     * a second, independent defence, not the one that makes a repeat run
     * harmless.
     */
    private function enforceDeadlines(): int
    {
        return (new EnforceSickCertificateDeadlines)->handle(app(LeaveRequestService::class));
    }

    private function balance(int $leaveId): LeaveBalance
    {
        $leave = LeaveRequest::query()->findOrFail($leaveId);

        return LeaveBalance::query()
            ->where('employee_id', $leave->employee_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', (int) $leave->start_date->year)
            ->firstOrFail();
    }
}
