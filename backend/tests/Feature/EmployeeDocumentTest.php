<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * /api/v1/employee-documents — the file itself, and who may put what in it.
 *
 * The file is arranged around the four things that make an employment
 * document different from an expense receipt:
 *
 *  - **the target is a decision, not a field.** `employee_id` means "me"
 *    when it is absent and must be reachable by the caller when it is
 *    present; a colleague's file and a nonexistent one both answer 403, so
 *    this endpoint never distinguishes "exists and is not yours" from "does
 *    not exist".
 *  - **the type owns the rules.** What the form demands — a number, an
 *    issue date, an expiry — is read from `document_types`, so a test that
 *    changes one row changes what the API accepts without touching code.
 *  - **the file is private in three senses at once.** Minted name, private
 *    disk, and a payload that never contains the path.
 *  - **archiving is not deleting.** The row and the bytes both survive; only
 *    the active list stops showing them.
 *
 * Money is not involved, but the equivalent of "never as a float" here is
 * "never as a colour" and "never as a path": the state a screen draws is
 * `expiry_state`, computed by the server from this type's own warning
 * window, and the bytes are reachable only through one route behind the
 * policy that already governs the row.
 */
class EmployeeDocumentTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentStack();

        Storage::fake('local');
        Storage::fake('public');

        // File uploads are multipart, so they cannot go through postJson();
        // saying what we expect up front keeps every 422 below asserting on
        // a JSON body rather than a rendered exception page.
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* -------------------------------------------------------------- create */

    public function test_hr_files_a_colleagues_document_and_it_arrives_pending(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $data = $this->fileDocument([], $target);

        $this->assertSame($target->id, $data['employee_id']);
        $this->assertSame('pending', $data['status'], 'Nothing is trusted the moment it arrives.');
        $this->assertTrue($data['has_file']);
        $this->assertSame('passport-scan.jpg', $data['original_name']);
        $this->assertSame('image/jpeg', $data['mime_type']);
        $this->assertSame('valid', $data['expiry_state'], 'Expiry is the server\'s answer, computed from the type\'s own window.');
        $this->assertNull($data['verified_at']);
        $this->assertNull($data['rejection_reason']);
        $this->assertArrayNotHasKey('path', $data, 'A storage path is never part of a response.');

        $document = EmployeeDocument::query()->findOrFail($data['id']);

        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertStringStartsWith(
            'employee-documents/'.$target->id.'/',
            $document->path,
            'The name is minted here; the client\'s filename is data only.',
        );
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Private means private, not "unlinked".');

        $this->assertStringNotContainsString(
            (string) $document->path,
            (string) json_encode($data),
            'The decoded payload carries no path for anyone to copy.',
        );
    }

    public function test_an_ordinary_person_files_their_own_and_nobody_elses(): void
    {
        [, $me] = $this->signInAs('Employee');

        $own = $this->fileDocument();
        $this->assertSame($me->id, $own['employee_id']);

        $colleague = Employee::factory()->create();

        $this->post('/api/v1/employee-documents', $this->documentPayload([
            'employee_id' => $colleague->id,
        ]))->assertForbidden();

        // A colleague who is not there at all is answered the same way, so
        // the endpoint cannot be used to walk the employee table.
        $this->post('/api/v1/employee-documents', $this->documentPayload([
            'employee_id' => $colleague->id + 999,
        ]))->assertForbidden();

        $this->assertSame(
            1,
            EmployeeDocument::query()->count(),
            'Only the payload that named the caller as its subject was written.',
        );
    }

    public function test_the_door_is_the_door(): void
    {
        $this->getJson('/api/v1/employee-documents')->assertUnauthorized();

        $this->become(User::factory()->create());
        $this->getJson('/api/v1/employee-documents')->assertForbidden();
        $this->postJson('/api/v1/employee-documents', $this->documentPayload())->assertForbidden();
    }

    public function test_what_the_form_demands_comes_from_the_type_not_from_a_constant(): void
    {
        $this->signInAs('HR Admin');

        $this->postJson('/api/v1/employee-documents', [
            'document_type_id' => $this->documentType('PASSPORT')->id,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'document_number', 'issue_date', 'expiry_date',
        ]);

        // A type with nothing to require accepts an empty payload.
        $this->postJson('/api/v1/employee-documents', [
            'document_type_id' => $this->documentType('OTHER')->id,
        ])->assertCreated();

        // Flip one row and the same payload is now refused — this is the
        // whole reason the flags are columns rather than rules.
        $this->reconfigureType('OTHER', ['requires_document_number' => true]);

        $this->postJson('/api/v1/employee-documents', [
            'document_type_id' => $this->documentType('OTHER')->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('document_number');
    }

    public function test_a_file_that_is_not_a_document_is_refused_and_nothing_is_written(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        // A PHP payload wearing an image's name. `mimes` and `mimetypes`
        // both read the *name*, so CertificateContent's content sniff is
        // what catches this one.
        $this->post('/api/v1/employee-documents', $this->documentPayload([
            'employee_id' => $target->id,
            'file' => UploadedFile::fake()->createWithContent(
                'payload.jpg',
                '<?php system($_GET["c"]);',
            ),
        ]))->assertUnprocessable()->assertJsonValidationErrors('file');

        // A name outside the allow-list, however plausible its bytes.
        $this->post('/api/v1/employee-documents', $this->documentPayload([
            'employee_id' => $target->id,
            'file' => $this->samplePdf('notes.txt'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('file');

        // The ceiling is a config value, not a constant: a real PDF that
        // fails on size alone.
        config(['hrms.storage.document_max_kilobytes' => 4]);
        $this->post('/api/v1/employee-documents', $this->documentPayload([
            'employee_id' => $target->id,
            'file' => $this->samplePdf('big.pdf', str_repeat('%PDF-1.4 a plausible line', 2000)),
        ]))->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(
            0,
            EmployeeDocument::query()->count(),
            'A refused upload writes neither a row nor a byte.',
        );
    }

    public function test_an_expiry_before_its_issue_date_is_refused(): void
    {
        $this->signInAs('HR Admin');

        $this->postJson('/api/v1/employee-documents', $this->documentPayload([
            'issue_date' => '2025-01-01',
            'expiry_date' => '2024-01-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('expiry_date');
    }

    /* --------------------------------------------------------------- read */

    public function test_a_person_only_ever_sees_their_own_file(): void
    {
        $hrUser = $this->signInAs('HR Admin')[0];
        $colleague = Employee::factory()->create();
        $other = $this->fileDocument([], $colleague);

        $this->getJson('/api/v1/employee-documents')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        [$managerUser] = $this->makeSeat('Project Manager');
        $this->become($managerUser);

        // The Project Manager holds `employees.view` and `documents.view`.
        // Neither is a door into somebody's passport.
        $this->getJson('/api/v1/employee-documents')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/api/v1/employee-documents/'.$other['id'])->assertForbidden();
        $this->getJson('/api/v1/employee-documents/'.$other['id'].'/file')->assertForbidden();

        $this->become($hrUser);
        $this->getJson('/api/v1/employee-documents/'.$other['id'])->assertOk();
    }

    public function test_the_file_itself_is_gated_the_same_way_as_the_row(): void
    {
        $this->signInAs('HR Admin');
        [$employeeUser, $seat] = $this->makeSeat('Employee');
        $colleague = Employee::factory()->create();

        $theirOwn = $this->fileDocument($this->documentPayload(), $seat);
        $notTheirs = $this->fileDocument($this->documentPayload(), $colleague);

        $this->become($employeeUser);

        // Their own document's bytes are as open as its metadata.
        $download = $this->get('/api/v1/employee-documents/'.$theirOwn['id'].'/file');
        $download->assertOk();
        $this->assertStringContainsString(
            'attachment;',
            (string) $download->headers->get('Content-Disposition'),
            'Attachment, never inline: a passport has no business being drawn into a shared page.',
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $download->headers->get('Cache-Control'),
        );
        $this->assertSame(
            'nosniff',
            (string) $download->headers->get('X-Content-Type-Options'),
        );

        $this->get('/api/v1/employee-documents/'.$notTheirs['id'].'/file')->assertForbidden();
    }

    public function test_expiry_is_reported_from_the_types_own_window(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $gone = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(10)->toDateString(),
        ]), $target);

        // 100 days out: inside a passport's 180-day window.
        $soon = $this->fileDocument($this->documentPayload([
            'document_type_id' => $this->documentType('EMIRATES_ID')->id,
            'issue_date' => '2024-01-01',
            'expiry_date' => today()->addDays(40)->toDateString(),
        ]), $target);

        $fine = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->addDays(400)->toDateString(),
        ]), $target);

        $none = $this->fileDocument([
            'document_type_id' => $this->documentType('OTHER')->id,
        ], $target);

        $this->assertSame('expired', $gone['expiry_state']);
        $this->assertLessThan(0, $gone['days_until_expiry'], 'The sign carries the meaning.');
        $this->assertSame('expiring_soon', $soon['expiry_state']);
        $this->assertSame('valid', $fine['expiry_state']);
        $this->assertSame('none', $none['expiry_state'], 'No date is not the same statement as "fine".');
        $this->assertNull($none['days_until_expiry']);

        // All four are still `pending`: computing a state is not the same
        // act as accepting the document.
        foreach ([$gone, $soon, $fine, $none] as $document) {
            $this->assertSame('pending', $document['status']);
        }
    }

    /* ------------------------------------------------------------ verify */

    public function test_verification_records_who_signed_off_and_when(): void
    {
        $hrUser = $this->signInAs('HR Admin')[0];
        $target = Employee::factory()->create();
        $document = $this->fileDocument($this->documentPayload(), $target);

        $verified = $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')
            ->assertOk()
            ->json('data');

        $this->assertSame('valid', $verified['status']);
        $this->assertSame($hrUser->id, $verified['verified_by']);
        $this->assertNotNull($verified['verified_at']);
        $this->assertNull($verified['rejection_reason']);

        // Second time is a 409 naming the state rather than a silent success.
        $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')->assertStatus(409);
    }

    public function test_nobody_signs_off_on_their_own_paperwork(): void
    {
        $this->signInAs('HR Admin');

        $own = $this->fileDocument();

        $this->postJson('/api/v1/employee-documents/'.$own['id'].'/verify')->assertForbidden();
        $this->postJson('/api/v1/employee-documents/'.$own['id'].'/reject', [
            'reason' => 'Nope.',
        ])->assertForbidden();

        $this->assertSame('pending', EmployeeDocument::query()->findOrFail($own['id'])->status);
    }

    public function test_replacing_the_evidence_withdraws_the_verification(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();
        $document = $this->fileDocument($this->documentPayload(), $target);

        $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')->assertOk();

        // A date change, not a typo in the notes: this is the substitution a
        // verification exists to prevent.
        $updated = $this->putJson('/api/v1/employee-documents/'.$document['id'], [
            'expiry_date' => today()->addYear()->toDateString(),
            'notes' => 'Re-filed after the employee found the original.',
        ])->assertOk()->json('data');

        $this->assertSame('pending', $updated['status']);
        $this->assertNull($updated['verified_at']);
        $this->assertNull($updated['verified_by']);

        // Correcting a note alone leaves the signature where it was.
        $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')->assertOk();
        $still = $this->putJson('/api/v1/employee-documents/'.$document['id'], [
            'notes' => 'Just a typo.',
        ])->assertOk()->json('data');

        $this->assertSame('valid', $still['status']);
        $this->assertNotNull($still['verified_at']);
    }

    public function test_a_rejection_says_why_and_remembers_who_said_it(): void
    {
        $hrUser = $this->signInAs('HR Admin')[0];
        $target = Employee::factory()->create();
        $document = $this->fileDocument($this->documentPayload(), $target);

        $this->postJson('/api/v1/employee-documents/'.$document['id'].'/reject')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $rejected = $this->postJson('/api/v1/employee-documents/'.$document['id'].'/reject', [
            'reason' => 'The scan is unreadable — please re-photograph page one.',
        ])->assertOk()->json('data');

        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('The scan is unreadable — please re-photograph page one.', $rejected['rejection_reason']);
        $this->assertSame($hrUser->id, $rejected['verified_by']);
        $this->assertNull($rejected['verified_at'], 'A refused document has not been verified.');

        $this->postJson('/api/v1/employee-documents/'.$document['id'].'/reject', [
            'reason' => 'Again.',
        ])->assertStatus(409);
    }

    public function test_accepting_a_document_that_has_already_lapsed_calls_it_expired(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();

        $document = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(3)->toDateString(),
        ]), $target);

        $verified = $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')
            ->assertOk()
            ->json('data');

        $this->assertSame('expired', $verified['status'], 'Accepting an expired passport does not unexpire it.');
        $this->assertNotNull($verified['verified_at']);
    }

    /* ----------------------------------------------------------- archive */

    public function test_archiving_takes_a_document_out_of_the_list_without_taking_it_away(): void
    {
        $this->signInAs('HR Admin');
        $target = Employee::factory()->create();
        $document = $this->fileDocument($this->documentPayload(), $target);

        $row = EmployeeDocument::query()->findOrFail($document['id']);
        $path = $row->path;

        $this->deleteJson('/api/v1/employee-documents/'.$document['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertTrue(Storage::disk('local')->exists($path), 'Archiving is not destruction.');
        $this->assertNotNull(EmployeeDocument::query()->findOrFail($document['id'])->archived_at);

        // Gone from the default list, reachable by asking for it.
        $this->getJson('/api/v1/employee-documents')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/api/v1/employee-documents?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->deleteJson('/api/v1/employee-documents/'.$document['id'])->assertStatus(409);
    }

    public function test_only_the_explicit_grant_may_archive(): void
    {
        [, $employee] = $this->signInAs('Employee');
        $document = $this->fileDocument();

        $this->deleteJson('/api/v1/employee-documents/'.$document['id'])->assertForbidden();
        $this->assertSame('pending', EmployeeDocument::query()->findOrFail($document['id'])->status);
    }

    /* ------------------------------------------------------------ filters */

    public function test_the_list_filters_on_the_things_a_desk_asks(): void
    {
        $this->signInAs('HR Admin');

        // Named rather than generated: a `search` assertion against two
        // faker names could match on a chance substring and fail for a
        // reason that has nothing to do with the filter.
        $target = Employee::factory()->create([
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
        ]);
        $other = Employee::factory()->create([
            'first_name' => 'Bruno',
            'last_name' => 'Silva',
        ]);

        $pending = $this->fileDocument($this->documentPayload(), $target);
        $verified = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->addDays(45)->toDateString(),
        ]), $target);
        $this->postJson('/api/v1/employee-documents/'.$verified['id'].'/verify')->assertOk();

        $gone = $this->fileDocument($this->documentPayload([
            'document_type_id' => $this->documentType('EMIRATES_ID')->id,
            'expiry_date' => today()->subDay()->toDateString(),
        ]), $other);
        $this->postJson('/api/v1/employee-documents/'.$gone['id'].'/verify')->assertOk();

        $ids = fn (string $query): array => collect(
            $this->getJson('/api/v1/employee-documents?'.$query)->assertOk()->json('data.items'),
        )->pluck('id')->all();

        $this->assertSame([$pending['id']], $ids('status=pending'));
        $this->assertSame([$verified['id']], $ids('status=valid'));
        $this->assertSame([$gone['id']], $ids('status=expired'));
        $this->assertSame([$gone['id']], $ids('employee_id='.$other->id));
        $this->assertSame([$gone['id']], $ids('expired=1'), 'Driven by the date, not by a status the cron has yet to write.');
        $this->assertEqualsCanonicalizing(
            [$pending['id'], $verified['id']],
            $ids('document_type_id='.$this->documentType('PASSPORT')->id),
        );
        $this->assertSame(
            [$verified['id']],
            $ids('expiring_soon=1'),
            'A passport inside its 180-day window, and only that.',
        );
        $this->assertSame(
            [$gone['id']],
            $ids('expiry_from='.today()->subDay()->toDateString().'&expiry_to='.today()->toDateString()),
        );
        $this->assertEqualsCanonicalizing(
            [$pending['id'], $verified['id']],
            $ids('search=Aisha'),
        );
        $this->assertSame([], $ids('search=zzzz-nothing-matches'));

        // Two statuses at once, so a desk does not have to make two
        // requests and join them itself.
        $this->assertEqualsCanonicalizing(
            [$verified['id'], $gone['id']],
            $ids('status=valid,expired'),
        );
    }

    public function test_the_expiry_report_is_the_one_question_that_is_about_everybody(): void
    {
        $this->signInAs('HR Admin');

        $mine = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(2)->toDateString(),
        ]));

        $target = Employee::factory()->create();
        $theirs = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->addDays(3)->toDateString(),
        ]), $target);

        // `within` defaults to "already gone" — the question that cannot
        // wait — and widens from there.
        $this->getJson('/api/v1/employee-documents/expiring')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $widened = $this->getJson('/api/v1/employee-documents/expiring?within=7')
            ->assertOk()
            ->json('data.items');

        $this->assertEqualsCanonicalizing(
            [$mine['id'], $theirs['id']],
            collect($widened)->pluck('id')->all(),
        );

        // Widening past a year is clamped rather than refused: the endpoint
        // answers the question it was asked as far as a sensible answer
        // exists, and never turns into a "show me everything from now on"
        // dump that no desk could read.
        $this->getJson('/api/v1/employee-documents/expiring?within=5000')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        // The permission opens the door; Visibility still decides whose rows
        // are in the room. A Payroll Admin holds `documents.expiry.view`
        // without `documents.manage`, so the report shows them their own file
        // and nobody else's.
        [$payrollUser] = $this->makeSeat('Payroll Admin');
        $this->become($payrollUser);

        $this->getJson('/api/v1/employee-documents/expiring')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        // Without the report permission at all, the door is the answer.
        [$employeeUser] = $this->makeSeat('Employee');
        $this->become($employeeUser);

        $this->getJson('/api/v1/employee-documents/expiring')->assertForbidden();
    }

    /* ------------------------------------------------- reference catalogue */

    public function test_the_catalogue_is_readable_by_anyone_who_may_file(): void
    {
        $this->signInAs('Employee');

        $items = $this->getJson('/api/v1/document-types')->assertOk()->json('data.items');

        $this->assertCount(9, $items);

        $passport = collect($items)->firstWhere('code', 'PASSPORT');

        // The form needs these before it has a file to attach.
        $this->assertTrue($passport['requires_document_number']);
        $this->assertTrue($passport['requires_expiry_date']);
        $this->assertSame(180, $passport['expiry_warning_days']);

        $this->getJson('/api/v1/document-types?active_only=1')->assertOk();
    }

    public function test_fields_nobody_owns_are_refused_rather_than_ignored(): void
    {
        $this->signInAs('HR Admin');

        $this->postJson('/api/v1/employee-documents', $this->documentPayload([
            'status' => 'valid',
            'verified_by' => 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['status', 'verified_by']);
    }
}
