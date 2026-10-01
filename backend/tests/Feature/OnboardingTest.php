<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * /api/v1/onboarding — where a starter stands, and what exactly is holding
 * them up.
 *
 * The file is built around the one idea the module exists for: **the
 * checklist is derived, never counted.** Every assertion below reads the
 * documents, the employee row and the bank record back through
 * OnboardingService and compares the answer, because a stored "3 of 8" would
 * agree with itself long after the file had changed underneath it.
 *
 * Four distinctions are proved rather than assumed, since collapsing any of
 * them would lose the answer a desk acts on:
 *
 *  - `missing`, `pending_verification`, `rejected` and `expired` are four
 *    different people to chase about four different things;
 *  - a refused *new* copy does not unsay a passport that is still on file —
 *    the requirement reads the file, not the latest upload;
 *  - refusing to complete is a 409 naming what is outstanding, not a 403
 *    about permission, and it writes nothing;
 *  - HR sees the directory, a manager sees only themselves, and an employee
 *    sees themselves — even though all three hold `onboarding.view`.
 */
class OnboardingTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentStack();

        Storage::fake('local');
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* ----------------------------------------------------------- directory */

    public function test_the_directory_shows_everybody_including_people_nobody_has_started(): void
    {
        $this->signInAs('HR Admin');

        $untouched = Employee::factory()->create([
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
        ]);
        $started = Employee::factory()->create([
            'first_name' => 'Bruno',
            'last_name' => 'Silva',
        ]);

        $items = collect($this->getJson('/api/v1/onboarding')->assertOk()->json('data.items'))
            ->keyBy('employee_id');

        $this->assertFalse($items[$untouched->id]['exists'], 'Nobody has opened it, and the payload says so.');
        $this->assertSame('draft', $items[$untouched->id]['status']);
        $this->assertFalse($items[$untouched->id]['is_completed']);
        $this->assertNull($items[$untouched->id]['started_at']);

        $this->putJson('/api/v1/onboarding/'.$started->id, [
            'status' => 'hr_review',
            'notes' => 'Waiting on the residence visa.',
        ])->assertOk()->assertJsonPath('data.status', 'hr_review');

        $this->getJson('/api/v1/onboarding?status=hr_review')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        // The two rows nobody has touched — the new starter and the HR seat
        // that signed in to look at them.
        $this->getJson('/api/v1/onboarding?status=draft')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_a_manager_is_not_told_what_people_have_not_handed_in(): void
    {
        $this->signInAs('HR Admin');
        Employee::factory()->create();

        [$managerUser] = $this->makeSeat('Project Manager');
        $this->become($managerUser);

        // `onboarding.view` gets them through the door; Visibility keeps
        // them at their own row. Managing people is not a reason to be told
        // that somebody has not produced a visa.
        $this->getJson('/api/v1/onboarding')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $colleague = Employee::factory()->create();
        $this->getJson('/api/v1/onboarding/'.$colleague->id)->assertForbidden();
    }

    public function test_the_door_is_the_door(): void
    {
        $this->getJson('/api/v1/onboarding')->assertUnauthorized();

        $this->become(User::factory()->create());

        // The employee has to exist for the route to resolve at all — the
        // answer below is about the grant, not about a missing record.
        $starter = Employee::factory()->create();

        $this->getJson('/api/v1/onboarding')->assertForbidden();
        $this->getJson('/api/v1/onboarding/'.$starter->id)->assertForbidden();
        $this->postJson('/api/v1/onboarding/'.$starter->id.'/complete')->assertForbidden();
    }

    /* ------------------------------------------------------------- checklist */

    public function test_the_detail_names_what_is_outstanding_and_who_has_to_fix_it(): void
    {
        $this->signInAs('HR Admin');
        $starter = Employee::factory()->create();

        // The factory's `optional()` contact fields decide this on the flip
        // of a coin otherwise, and a requirement that is satisfied by
        // accident is not an answer to "what is outstanding".
        Employee::query()->whereKey($starter->id)->update([
            'phone' => null,
            'nationality' => null,
        ]);

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->assertOk()->json('data');

        $this->assertCount(8, $detail['requirements']);
        $this->assertSame(
            [
                'personal_information', 'passport', 'emirates_id', 'visa',
                'employment_contract', 'bank_information', 'employee_photo', 'certificates',
            ],
            $detail['missing_requirements'],
            'Catalogue order, because a desk reads the list top to bottom.',
        );
        $this->assertSame(['total' => 8, 'satisfied' => 0], $detail['totals']);
        $this->assertFalse($detail['can_complete']);

        // Filed, not yet signed off: the employee has done their part, so
        // the nudge is HR's.
        $passport = $this->fileDocument($this->documentPayload(), $starter);

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->json('data');
        $item = collect($detail['requirements'])->firstWhere('code', 'passport');

        $this->assertSame('pending_verification', $item['state']);
        $this->assertNotNull($item['document'], 'The row it was decided from travels with the answer.');
        $this->assertContains('passport', $detail['missing_requirements']);

        $this->postJson('/api/v1/employee-documents/'.$passport['id'].'/verify')->assertOk();

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->json('data');
        $item = collect($detail['requirements'])->firstWhere('code', 'passport');

        $this->assertSame('satisfied', $item['state']);
        $this->assertNotContains('passport', $detail['missing_requirements']);
        $this->assertSame(1, $detail['totals']['satisfied']);
        $this->assertFalse($detail['can_complete'], 'One of eight is not eight.');
    }

    public function test_a_data_requirement_says_which_columns_are_still_empty(): void
    {
        $this->signInAs('HR Admin');
        $starter = Employee::factory()->create();

        Employee::query()->whereKey($starter->id)->update([
            'phone' => null,
            'nationality' => null,
        ]);

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->json('data');
        $item = collect($detail['requirements'])->firstWhere('code', 'personal_information');

        $this->assertSame('missing', $item['state']);
        $this->assertEqualsCanonicalizing(['phone', 'nationality'], $item['missing_fields']);
        $this->assertNull($item['document']);

        Employee::query()->whereKey($starter->id)->update($this->completePersonalDetails());

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->json('data');
        $item = collect($detail['requirements'])->firstWhere('code', 'personal_information');

        $this->assertSame('satisfied', $item['state']);
        $this->assertSame([], $item['missing_fields']);
    }

    public function test_refused_and_lapsed_are_told_apart_from_nothing_at_all(): void
    {
        $this->signInAs('HR Admin');

        $nobody = Employee::factory()->create();
        $refused = Employee::factory()->create();
        $lapsed = Employee::factory()->create();

        $declined = $this->fileDocument($this->documentPayload(), $refused);
        $this->postJson('/api/v1/employee-documents/'.$declined['id'].'/reject', [
            'reason' => 'The scan is blurred.',
        ])->assertOk();

        $dated = $this->fileDocument($this->documentPayload([
            'expiry_date' => today()->subDays(30)->toDateString(),
        ]), $lapsed);
        $this->postJson('/api/v1/employee-documents/'.$dated['id'].'/verify')->assertOk();

        $state = function (Employee $employee, string $code, string $key): mixed {
            $item = collect(
                $this->getJson('/api/v1/onboarding/'.$employee->id)->json('data.requirements'),
            )->firstWhere('code', $code);

            return $item[$key];
        };

        $this->assertSame('missing', $state($nobody, 'passport', 'state'));
        $this->assertSame('rejected', $state($refused, 'passport', 'state'));
        $this->assertSame(
            'The scan is blurred.',
            $state($refused, 'passport', 'document')['rejection_reason'],
            'The reason travels with the state — "rejected" alone tells the employee nothing to act on.',
        );
        $this->assertSame('expired', $state($lapsed, 'passport', 'state'));

        // All three are outstanding; they are not the same kind of
        // outstanding, and the payload keeps them apart.
        $this->assertEqualsCanonicalizing(
            ['missing', 'rejected', 'expired'],
            [
                $state($nobody, 'passport', 'state'),
                $state($refused, 'passport', 'state'),
                $state($lapsed, 'passport', 'state'),
            ],
        );
    }

    public function test_a_refused_new_copy_does_not_unsay_a_passport_still_on_file(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $good = $this->fileDocument($this->documentPayload(), $employee);
        $this->postJson('/api/v1/employee-documents/'.$good['id'].'/verify')->assertOk();

        $bad = $this->fileDocument($this->documentPayload(), $employee);
        $this->postJson('/api/v1/employee-documents/'.$bad['id'].'/reject', [
            'reason' => 'That is page two.',
        ])->assertOk();

        $detail = $this->getJson('/api/v1/onboarding/'.$employee->id)->json('data');
        $item = collect($detail['requirements'])->firstWhere('code', 'passport');

        $this->assertSame(
            'satisfied',
            $item['state'],
            'She does hold a passport. Chasing her for one would be asking for what is already in the file.',
        );
        $this->assertSame(
            $good['id'],
            $item['document']['id'],
            'The chip and the preview are decided from the same row, so the two cannot disagree.',
        );
        $this->assertNotContains('passport', $detail['missing_requirements']);
    }

    /* ----------------------------------------------------------- completion */

    public function test_onboarding_cannot_be_completed_while_anything_is_outstanding(): void
    {
        $this->signInAs('HR Admin');
        $starter = Employee::factory()->create();

        $refused = $this->postJson('/api/v1/onboarding/'.$starter->id.'/complete');

        $refused->assertStatus(409);
        $message = (string) $refused->json('message');
        $this->assertStringContainsString('outstanding', $message);
        $this->assertStringContainsString('Passport', $message);
        $this->assertStringContainsString('Bank information', $message);

        // The same answer through PUT, so a client that only knows how to
        // PUT is told the same thing rather than being allowed through.
        $this->putJson('/api/v1/onboarding/'.$starter->id, ['status' => 'completed'])
            ->assertStatus(409);

        $this->getJson('/api/v1/onboarding/'.$starter->id)
            ->assertOk()
            ->assertJsonPath('data.exists', false);

        $this->getJson('/api/v1/onboarding?status=completed')->assertJsonCount(0, 'data.items');
    }

    public function test_onboarding_completes_once_everything_is_in_place(): void
    {
        $hrUser = $this->signInAs('HR Admin')[0];
        $starter = Employee::factory()->create([
            'first_name' => 'Priya',
            'last_name' => 'Nair',
        ]);

        $this->satisfyEveryRequirementFor($starter);

        $detail = $this->getJson('/api/v1/onboarding/'.$starter->id)->json('data');

        $this->assertTrue($detail['can_complete']);
        $this->assertSame([], $detail['missing_requirements']);
        $this->assertSame(['total' => 8, 'satisfied' => 8], $detail['totals']);

        $done = $this->postJson('/api/v1/onboarding/'.$starter->id.'/complete')
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $done['status']);
        $this->assertNotNull($done['completed_at']);
        $this->assertSame($hrUser->id, $done['completed_by']);

        // Scoped to her, because the desk that signed in to check is also
        // in this directory and is not herself completed.
        $this->getJson('/api/v1/onboarding?status=completed&search=Priya')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/onboarding?incomplete=1&search=Priya')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        // Reopening clears the stamp rather than leaving a date beside a
        // status that no longer describes it.
        $reopened = $this->putJson('/api/v1/onboarding/'.$starter->id, ['status' => 'hr_review'])
            ->assertOk()
            ->json('data');

        $this->assertSame('hr_review', $reopened['status']);
        $this->assertNull($reopened['completed_at']);
        $this->assertNull($reopened['completed_by']);
    }

    /* --------------------------------------------------------- self-service */

    public function test_a_person_reads_their_own_record_and_nobody_elses(): void
    {
        $this->signInAs('HR Admin');
        [$meUser, $me] = $this->makeSeat('Employee');
        $colleague = Employee::factory()->create();

        $this->become($meUser);

        $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/onboarding/'.$me->id)->assertOk();

        $this->getJson('/api/v1/onboarding/'.$colleague->id)->assertForbidden();

        // Reading where they stand is theirs; moving it along is HR's. An
        // employee who could complete their own onboarding would be signing
        // off on their own requirements.
        $this->putJson('/api/v1/onboarding/'.$me->id, ['status' => 'completed'])->assertForbidden();
        $this->postJson('/api/v1/onboarding/'.$me->id.'/complete')->assertForbidden();
    }

    /* --------------------------------------------------------------- filter */

    public function test_the_missing_filter_finds_the_people_who_have_not_filed(): void
    {
        $this->signInAs('HR Admin');

        $owes = Employee::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Haddad']);
        $filed = Employee::factory()->create(['first_name' => 'Omar', 'last_name' => 'Farouk']);

        $passport = $this->fileDocument($this->documentPayload(), $filed);
        $this->postJson('/api/v1/employee-documents/'.$passport['id'].'/verify')->assertOk();

        $ids = fn (string $query): array => collect(
            $this->getJson('/api/v1/onboarding?'.$query)->assertOk()->json('data.items'),
        )->pluck('employee_id')->all();

        $this->assertEqualsCanonicalizing([$owes->id], $ids('missing=passport&search=Nadia'));
        $this->assertSame([], $ids('missing=passport&search=Omar'), 'A verified, unexpired copy is on file.');
        $this->assertEqualsCanonicalizing([$owes->id], $ids('incomplete=1&search=Nadia'));

        // A requirement nobody has ever heard of is a 422 naming it, not an
        // empty list that reads as "all done".
        $this->getJson('/api/v1/onboarding?missing=not_a_requirement')
            ->assertUnprocessable();
    }

    /* ------------------------------------------------------------ sensitive */

    public function test_bank_details_appear_in_no_generic_payload(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $iban = 'AE070331234567890123456';

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', [
            'account_holder_name' => 'Aisha Rahman',
            'bank_name' => 'Emirates NBD',
            'iban' => $iban,
            'currency' => 'AED',
        ])->assertOk();

        // The directory screen and the onboarding screen are both places a
        // great many roles read, and neither is a place the account belongs.
        $directory = $this->getJson('/api/v1/employees/'.$employee->id)->assertOk();
        $this->assertStringNotContainsString($iban, $directory->getContent());

        $onboarding = $this->getJson('/api/v1/onboarding/'.$employee->id)->assertOk();
        $this->assertStringNotContainsString($iban, $onboarding->getContent());

        // While its own route — reached only by somebody with one of the two
        // grants, or by the person themselves — returns it whole, because an
        // HR desk that cannot read the IBAN cannot pay anybody.
        $own = $this->getJson('/api/v1/employees/'.$employee->id.'/bank-account')->assertOk();
        $this->assertStringContainsString($iban, $own->getContent());
    }
}
