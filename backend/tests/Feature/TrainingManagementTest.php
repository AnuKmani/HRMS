<?php

namespace Tests\Feature;

use App\Models\EmployeeTraining;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTrainingAssets;
use Tests\TestCase;

/**
 * /api/v1/training-programs, /api/v1/employee-training — the catalogue, and
 * a person's place on one of its courses.
 *
 * The file is arranged around the four things that make training different
 * from the modules that came before it:
 *
 *  - **the catalogue is data.** `training_types` is a seeded *vocabulary*
 *    and the programs are rows somebody wrote. Nothing is hard-coded, so a
 *    test that renames a type changes what every screen says without a line
 *    of code moving.
 *  - **the row scope is ownership, and `training.manage` is the only door
 *    onto a colleague's record.** Every role holds `training.view` (an
 *    employee must be able to read their own course history), which makes
 *    it worth nothing on its own — so the narrow rule does the work.
 *  - **the certificate is narrower again than the row.** Your own card
 *    needs only `training.view`; a colleague's needs
 *    `training.certificates.view`. Two acts, two permissions, and
 *    `training.manage` deliberately opens only the first.
 *  - **nothing may be deleted.** A program is retired, an enrolment is
 *    cancelled. A cohort that ran cannot be un-run, and a row that vanished
 *    would leave its seat unexplained.
 *
 * Transitions answer 409 naming the state rather than 403, so every test
 * below that expects 409 is asserting on a sentence about the *world* and
 * every test that expects 403 is asserting on a sentence about the caller.
 */
class TrainingManagementTest extends TestCase
{
    use BuildsTrainingAssets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrainingAssetStack();

        Storage::fake('local');
        Storage::fake('public');

        // Certificates are multipart, so they cannot go through postJson();
        // saying what we expect up front keeps every 422 below asserting on
        // a JSON body rather than on a rendered exception page.
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* ---------------------------------------------------------- catalogue */

    public function test_hr_adds_a_program_and_nothing_may_take_its_code(): void
    {
        $this->signInAs('HR Admin');

        $payload = $this->programPayload();

        $created = $this->postJson('/api/v1/training-programs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', $payload['code'])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.certificate_required', true)
            ->assertJsonPath('data.certificate_validity_days', 365)
            ->assertJsonPath('data.training_type.code', 'WORKING_AT_HEIGHTS');

        $this->postJson('/api/v1/training-programs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        // The id is the one that survived; a rejection never half-writes.
        $this->assertNotNull($created->json('data.id'));
    }

    public function test_a_program_cannot_be_filed_under_a_type_that_is_not_active(): void
    {
        $this->signInAs('HR Admin');

        $type = $this->trainingType('OTHER');
        $type->update(['status' => 'inactive']);

        $this->postJson('/api/v1/training-programs', $this->programPayload([
            'training_type_id' => $type->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['training_type_id']);

        // Flip it back and the identical payload is now accepted — this is
        // the whole reason the flag lives on the row rather than in a rule.
        $type->update(['status' => 'active']);

        $this->postJson('/api/v1/training-programs', $this->programPayload([
            'training_type_id' => $type->id,
        ]))->assertCreated();
    }

    public function test_the_type_vocabulary_is_data_that_can_be_renamed(): void
    {
        $this->signInAs('HR Admin');

        $this->getJson('/api/v1/training-types')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonFragment(['code' => 'SAFETY_INDUCTION']);

        $this->trainingType('HSE')->update(['name' => 'Health, Safety & Environment']);

        // One row, one rename, and every screen that draws the catalogue
        // now says the new thing — there is no second copy to update.
        $this->getJson('/api/v1/training-types?search=HSE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Health, Safety & Environment');
    }

    public function test_the_catalogue_door_is_the_permission_and_nothing_else(): void
    {
        $this->getJson('/api/v1/training-programs')->assertUnauthorized();
        $this->getJson('/api/v1/training-types')->assertUnauthorized();

        $this->signInAs('Employee');

        // An employee reads the catalogue — they have to, to make sense of
        // a course history — but the catalogue is not theirs to add to.
        $this->getJson('/api/v1/training-programs')->assertOk();
        $this->postJson('/api/v1/training-programs', $this->programPayload())->assertForbidden();

        // And a caller with no role at all has no door at all.
        $this->become(User::factory()->create());
        $this->getJson('/api/v1/training-programs')->assertForbidden();
        $this->getJson('/api/v1/employee-training')->assertForbidden();
    }

    public function test_the_catalogue_is_editable_but_never_removable(): void
    {
        $this->signInAs('HR Admin');
        $program = $this->makeTrainingProgram();

        $this->putJson('/api/v1/training-programs/'.$program->id, [
            'provider' => 'Gulf Safety Institute',
            'duration_days' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'Gulf Safety Institute')
            ->assertJsonPath('data.duration_days', 3);

        // Retirement, not deletion: the rows it already produced still have
        // to name something real.
        $this->putJson('/api/v1/training-programs/'.$program->id, ['status' => 'retired'])
            ->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('training_programs', ['id' => $program->id]);

        // There is no DELETE route to reach — the method is refused before
        // any controller could answer it. Retirement is the whole verb for
        // removing a programme from the catalogue.
        $this->deleteJson('/api/v1/training-programs/'.$program->id)->assertStatus(405);
    }

    /* ---------------------------------------------------------- enrolment */

    public function test_hr_puts_somebody_on_a_course(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $this->postJson('/api/v1/employee-training', $this->enrollPayload($learner, $program))
            ->assertCreated()
            ->assertJsonPath('data.status', 'enrolled')
            ->assertJsonPath('data.employee_id', $learner->id)
            ->assertJsonPath('data.training_program_id', $program->id)
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_terminal', false);
    }

    public function test_a_place_on_a_course_cannot_be_held_twice(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $payload = $this->enrollPayload($learner, $program);

        $this->postJson('/api/v1/employee-training', $payload)->assertCreated();

        // Same day, same course: the unique index's question, asked before
        // the index has to ask it.
        $this->postJson('/api/v1/employee-training', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That person is already enrolled on this course for that date.');

        // A different day and still open is still two seats for one
        // attendee — the second question, with a second sentence.
        $this->postJson('/api/v1/employee-training', $this->enrollPayload($learner, $program, [
            'enrollment_date' => now()->addWeek()->toDateString(),
        ]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That person already has a place on this course.');

        $this->assertSame(1, EmployeeTraining::query()->count());
    }

    public function test_a_finished_course_may_be_sat_again(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $first = $this->postJson(
            '/api/v1/employee-training',
            $this->enrollPayload($learner, $program),
        )->assertCreated()->json('data');

        $this->postJson('/api/v1/employee-training/'.$first['id'].'/complete', [
            'certificate_issue_date' => now()->toDateString(),
        ])->assertOk();

        // A year later, a new enrolment on a new date: recertification is a
        // *new row*, which is exactly why the unique index is keyed on the
        // date rather than on (person, course).
        $second = $this->postJson('/api/v1/employee-training', $this->enrollPayload($learner, $program, [
            'enrollment_date' => now()->addYear()->toDateString(),
        ]));

        $second->assertCreated()->assertJsonPath('data.status', 'enrolled');

        $this->assertSame(2, EmployeeTraining::query()->count());
    }

    public function test_no_grant_means_no_self_service(): void
    {
        [, $me] = $this->signInAs('Employee');
        [, $colleague] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        // `training.assign` has no self-service half — putting yourself on
        // the course that certifies you is exactly what the brief rules out
        // — so an employee can enrol neither themselves nor anybody else.
        $this->postJson('/api/v1/employee-training', $this->enrollPayload($me, $program))
            ->assertForbidden();

        $this->postJson('/api/v1/employee-training', $this->enrollPayload($colleague, $program))
            ->assertForbidden();

        // And a target that does not exist is answered the same way, so the
        // endpoint cannot be used to walk the employee table.
        $this->postJson('/api/v1/employee-training', [
            'employee_id' => $colleague->id + 999,
            'training_program_id' => $program->id,
            'enrollment_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame(0, EmployeeTraining::query()->count());
    }

    /* --------------------------------------------------------- visibility */

    public function test_an_employee_reads_only_their_own_course_history(): void
    {
        [, $me] = $this->signInAs('Employee');
        [, $colleague] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $mine = $this->makeTraining($me, $program);
        $theirs = $this->makeTraining($colleague, $program);

        $this->getJson('/api/v1/employee-training')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id);

        // `training.view` is held by everybody, so it is worth nothing on
        // its own: the row beside it still needs `training.manage`.
        $this->getJson('/api/v1/employee-training/'.$theirs->id)->assertForbidden();
    }

    public function test_a_project_manager_reads_only_their_own_course_history(): void
    {
        [, $me] = $this->signInAs('Project Manager');
        [, $colleague] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $mine = $this->makeTraining($me, $program);
        $theirs = $this->makeTraining($colleague, $program);

        $this->getJson('/api/v1/employee-training')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id);

        // A manager who is not on the course cannot read the row at all,
        // because `training.view` alone is not `training.manage`.
        $this->getJson('/api/v1/employee-training/'.$theirs->id)->assertForbidden();
    }

    public function test_hr_reads_everybodys_course_history(): void
    {
        $this->signInAs('HR Admin');
        [, $a] = $this->makeSeat('Employee');
        [, $b] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $this->makeTraining($a, $program);
        $theirs = $this->makeTraining($b, $program);

        $this->getJson('/api/v1/employee-training')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->getJson('/api/v1/employee-training/'.$theirs->id)->assertOk();
    }

    /* ---------------------------------------------------------- completion */

    public function test_completing_dates_the_card_from_the_programs_own_validity(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $issued = now()->toDateString();
        $expectedExpiry = now()->addDays(365)->toDateString();

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'completion_date' => $issued,
            'result' => 'Pass',
            'certificate_number' => 'WAH-2026-001',
            'certificate_issue_date' => $issued,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result', 'Pass')
            ->assertJsonPath('data.completion_date', $issued)
            ->assertJsonPath('data.certificate_expiry_date', $expectedExpiry)
            ->assertJsonPath('data.certificate_expiry_state', 'valid')
            ->assertJsonPath('data.days_until_expiry', 365);
    }

    public function test_a_date_the_instructor_wrote_by_hand_beats_the_default(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
            'certificate_expiry_date' => now()->addMonths(6)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.certificate_expiry_date', now()->addMonths(6)->toDateString());
    }

    public function test_a_program_that_promises_a_card_cannot_be_completed_without_one(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');

        $program = $this->makeTrainingProgram(['certificate_required' => true]);
        $training = $this->makeTraining($learner, $program);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'completion_date' => now()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['certificate_issue_date']);

        $this->assertSame(
            EmployeeTraining::STATUS_ENROLLED,
            $training->fresh()->status,
            'A refused completion changes nothing at all.',
        );

        // A program that promises nothing accepts the same payload: the
        // requirement is the row's, not a rule's.
        $free = $this->makeTrainingProgram([
            'certificate_required' => false,
            'certificate_validity_days' => null,
            'code' => 'BRIEFING-99',
        ]);
        $other = $this->makeTraining($learner, $free, [
            'enrollment_date' => now()->addDay()->toDateString(),
        ]);

        $this->postJson('/api/v1/employee-training/'.$other->id.'/complete', [
            'completion_date' => now()->toDateString(),
        ])->assertOk();
    }

    public function test_a_second_completion_is_refused_and_a_refusal_writes_nothing(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
            'result' => 'Pass',
        ])->assertOk();

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'result' => 'Distinction',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That training has already been marked complete.');

        $this->assertSame('Pass', $training->fresh()->result, 'The first answer stood.');
    }

    /* ------------------------------------------------------------ cancel */

    public function test_cancelling_keeps_the_row_but_cannot_reach_a_decided_outcome(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program, ['remarks' => 'Booked on the 4th']);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/cancel', [
            'remarks' => 'Site clashed with the induction.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.is_terminal', true);

        $this->assertDatabaseHas('employee_trainings', [
            'id' => $training->id,
            'status' => EmployeeTraining::STATUS_CANCELLED,
        ]);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('message', 'That enrolment is already cancelled.');

        // And a cancelled attempt cannot be walked forward into a pass —
        // the attempt did not happen, so there is nothing to have passed.
        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A cancelled enrolment cannot be completed. Enrol them again.');
    }

    public function test_a_completed_course_cannot_be_cancelled(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $this->postJson('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
        ])->assertOk();

        $this->postJson('/api/v1/employee-training/'.$training->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('message', 'A completed training cannot be cancelled. Correct the record instead.');
    }

    /* --------------------------------------------------------- certificates */

    public function test_the_card_lands_in_the_private_store_and_no_path_leaves_the_server(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $data = $this->post('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
            'file' => $this->certificatePdf(),
        ])->assertOk()->json('data');

        $this->assertTrue($data['has_certificate']);
        $this->assertSame('working-at-heights.pdf', $data['certificate_original_name']);
        $this->assertSame('application/pdf', $data['certificate_mime_type']);

        $stored = $training->fresh();

        $this->assertStringStartsWith(
            'employee-documents/'.$learner->id.'/',
            (string) $stored->certificate_path,
            'The name is minted here; the client\'s filename is data only.',
        );
        Storage::disk('local')->assertExists($stored->certificate_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Private means private, not "unlinked".');

        $this->assertArrayNotHasKey('certificate_path', $data, 'A storage path is never part of a response.');
        $this->assertStringNotContainsString(
            (string) $stored->certificate_path,
            (string) json_encode($data),
            'The decoded payload carries no path for anyone to copy.',
        );
    }

    public function test_an_employee_may_hand_over_their_own_card_but_not_a_colleagues(): void
    {
        $this->signInAs('HR Admin');
        [$learnerUser, $learner] = $this->makeSeat('Employee');
        [$colleagueUser] = $this->makeSeat('Employee');

        $program = $this->makeTrainingProgram();
        $training = $this->makeTraining($learner, $program);

        $this->post('/api/v1/employee-training/'.$training->id.'/complete', [
            'certificate_issue_date' => now()->toDateString(),
            'file' => $this->certificatePdf(),
        ])->assertOk();

        $url = '/api/v1/employee-training/'.$training->id.'/file';

        // HR holds `training.certificates.view`, so a colleague's card is
        // theirs to hand over.
        $this->get($url)->assertOk();

        // The card's owner needs only `training.view` — an employee showing
        // a site supervisor their working-at-heights card is the whole
        // reason the two doors are separate.
        $this->become($learnerUser);
        $this->get($url)->assertOk();

        // A colleague with `training.view` but no `training.certificates.view`
        // reads their own history and nothing about this card. `training.manage`
        // would not have opened it either, and that is the point.
        $this->become($colleagueUser);
        $this->get($url)->assertForbidden();
    }

    /* ------------------------------------------------------------- filters */

    public function test_filters_pick_out_the_programme_the_person_and_the_state(): void
    {
        $this->signInAs('HR Admin');
        [, $a] = $this->makeSeat('Employee');
        [, $b] = $this->makeSeat('Employee');

        $firstAid = $this->makeTrainingProgram(['code' => 'FA-1', 'name' => 'First Aid Level 1']);
        $heights = $this->makeTrainingProgram();

        $done = $this->makeTraining($a, $firstAid, [
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'completion_date' => now()->toDateString(),
            'certificate_issue_date' => now()->toDateString(),
        ]);
        $live = $this->makeTraining($b, $heights);

        $this->getJson('/api/v1/employee-training?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $done->id);

        $this->getJson('/api/v1/employee-training?status=completed,failed,cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/employee-training?training_program_id='.$firstAid->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $done->id);

        $this->getJson('/api/v1/employee-training?employee_id='.$b->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $live->id);

        $this->getJson('/api/v1/employee-training?search=First Aid')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $done->id);

        $this->getJson('/api/v1/employee-training?certified=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/employee-training?status=nonsense')
            ->assertOk()
            ->assertJsonCount(0, 'data.items', 'An unknown status matches nothing rather than everything.');
    }

    public function test_the_expiry_filters_read_the_calendar_not_the_stored_status(): void
    {
        $this->signInAs('HR Admin');
        [, $learner] = $this->makeSeat('Employee');
        $program = $this->makeTrainingProgram();

        $lapsed = $this->makeTraining($learner, $program, [
            'enrollment_date' => now()->subYears(2)->toDateString(),
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subYears(2)->toDateString(),
            'certificate_expiry_date' => now()->subDay()->toDateString(),
        ]);
        $due = $this->makeTraining($learner, $program, [
            'enrollment_date' => now()->subMonths(2)->toDateString(),
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subMonths(2)->toDateString(),
            'certificate_expiry_date' => now()->addDays(10)->toDateString(),
        ]);
        $fine = $this->makeTraining($learner, $program, [
            'enrollment_date' => now()->subWeek()->toDateString(),
            'status' => EmployeeTraining::STATUS_COMPLETED,
            'certificate_issue_date' => now()->subWeek()->toDateString(),
            'certificate_expiry_date' => now()->addYear()->toDateString(),
        ]);

        // The statuses are all `completed` and the scheduler has run for
        // none of them, so anything that grouped by status would return
        // all three here.
        $this->getJson('/api/v1/employee-training?expired=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $lapsed->id);

        $this->getJson('/api/v1/employee-training?expiring_soon=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $due->id);

        // And the state the *server* reports, not one the client guessed.
        $this->getJson('/api/v1/employee-training/'.$lapsed->id)
            ->assertOk()
            ->assertJsonPath('data.certificate_expiry_state', 'expired')
            ->assertJsonPath('data.days_until_expiry', -1);

        $this->getJson('/api/v1/employee-training/'.$due->id)
            ->assertOk()
            ->assertJsonPath('data.certificate_expiry_state', 'expiring_soon')
            ->assertJsonPath('data.days_until_expiry', 10);

        $this->getJson('/api/v1/employee-training/'.$fine->id)
            ->assertOk()
            ->assertJsonPath('data.certificate_expiry_state', 'valid');
    }

    public function test_the_expiry_report_is_behind_its_own_permission(): void
    {
        [, $me] = $this->signInAs('Employee');
        $program = $this->makeTrainingProgram();
        $this->makeTraining($me, $program);

        $this->getJson('/api/v1/employee-training/expiring')->assertForbidden();

        $this->signInAs('Payroll Admin');

        // Payroll holds `training.expiry.view` but not `training.manage`, so
        // the report is open and the *rows* in it are still theirs alone —
        // the permission opens the door, it does not hand over the room.
        $this->getJson('/api/v1/employee-training/expiring')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }
}
