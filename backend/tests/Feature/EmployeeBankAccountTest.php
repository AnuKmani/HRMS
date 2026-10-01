<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * GET|PUT /api/v1/employees/{employee}/bank-account — the only two routes in
 * this application with no `permission:` middleware at all, decided instead
 * by EmployeePolicy.
 *
 * Three properties are worth a test each, because each one alone would be
 * incomplete:
 *
 *  - **the door has to admit the owner.** An ordinary employee reading their
 *    own account is the case a shared gate cannot express ("your own, or
 *    `employees.salary.view`, or `onboarding.manage`"), which is exactly why
 *    the gate is absent and the policy present.
 *  - **reading is not writing.** Self-service *entry* is deliberately not
 *    offered: an unverified IBAN sitting in a payroll run is a failed
 *    payment nobody notices until payday.
 *  - **the value exists in one place.** Encrypted at rest, echoed by one
 *    resource, and reported by *field* when validation fails — a rule that
 *    named the value would put it in an exception message and then a log.
 *
 * There is one account per employee (the unique index), so the write is an
 * upsert: offering a create and an update route would only be two ways to
 * express the same write.
 */
class EmployeeBankAccountTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentStack();

        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_hr_records_an_account_and_reads_it_back_whole(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $saved = $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', $this->payload())
            ->assertOk()
            ->json('data');

        $this->assertSame('AE070331234567890123456', $saved['iban']);
        $this->assertSame('1234567890123456', $saved['account_number']);
        $this->assertSame('AED', $saved['currency']);
        $this->assertSame('active', $saved['status']);

        $read = $this->getJson('/api/v1/employees/'.$employee->id.'/bank-account')
            ->assertOk()
            ->json('data');

        $this->assertSame($saved['iban'], $read['iban'], 'An HR desk that cannot read it cannot pay anybody.');

        $stored = DB::table('employee_bank_accounts')
            ->where('employee_id', $employee->id)
            ->value('iban');

        $this->assertStringNotContainsString(
            'AE070331234567890123456',
            (string) $stored,
            'Encrypted at rest: what is in the row is not the value.',
        );
    }

    public function test_there_is_one_account_and_one_way_to_write_it(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $first = $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', $this->payload())
            ->assertOk()
            ->json('data');

        $second = $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', [
            'account_holder_name' => 'Aisha Rahman',
            'bank_name' => 'ADCB',
            'iban' => 'AE123456789012345678901',
        ])->assertOk()->json('data');

        $this->assertSame($first['id'], $second['id'], 'A second PUT replaces, it does not add.');
        $this->assertSame('ADCB', $second['bank_name']);
        $this->assertSame('AED', $second['currency'], 'The column default, not a guess by the client.');
        $this->assertSame(1, DB::table('employee_bank_accounts')->count());
    }

    public function test_an_employee_may_read_their_own_but_neither_write_it_nor_somebody_elses(): void
    {
        $hrUser = $this->signInAs('HR Admin')[0];
        [$meUser, $me] = $this->makeSeat('Employee');
        $colleague = Employee::factory()->create();

        $this->putJson('/api/v1/employees/'.$me->id.'/bank-account', $this->payload())->assertOk();
        $this->putJson('/api/v1/employees/'.$colleague->id.'/bank-account', $this->payload())->assertOk();

        $this->become($meUser);

        $this->getJson('/api/v1/employees/'.$me->id.'/bank-account')->assertOk();

        // Knowing the route is open to yourself is not knowing where
        // somebody else's salary goes.
        $this->getJson('/api/v1/employees/'.$colleague->id.'/bank-account')->assertForbidden();

        // And reading is where self-service stops.
        $this->putJson('/api/v1/employees/'.$me->id.'/bank-account', [
            'account_holder_name' => 'Somebody Else',
            'bank_name' => 'Whatever Bank',
            'iban' => 'AE999999999999999999999',
        ])->assertForbidden();

        // Somebody holding neither grant is refused outright — no middleware
        // is a missing gate, not an open one.
        [$managerUser] = $this->makeSeat('Project Manager');
        $this->become($managerUser);
        $this->getJson('/api/v1/employees/'.$me->id.'/bank-account')->assertForbidden();

        $this->become(User::factory()->create());
        $this->getJson('/api/v1/employees/'.$me->id.'/bank-account')->assertForbidden();

        // None of those refusals changed what HR wrote.
        $this->become($hrUser);
        $this->assertSame(
            'AE070331234567890123456',
            $this->getJson('/api/v1/employees/'.$me->id.'/bank-account')->json('data.iban'),
        );
    }

    public function test_validation_reports_the_field_and_never_the_value(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $farTooLong = str_repeat('ABCDEFGH', 30);

        $response = $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', [
            'account_holder_name' => 'Aisha Rahman',
            'bank_name' => 'Emirates NBD',
            'iban' => $farTooLong,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('iban');

        $this->assertStringNotContainsString(
            $farTooLong,
            $response->getContent(),
            'The field is named; the value is not. A message containing it would be logged.',
        );

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', [
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE070331234567890123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_holder_name');

        $this->assertSame(0, DB::table('employee_bank_accounts')->count(), 'A refused write leaves nothing behind.');
    }

    public function test_currency_follows_the_supported_list_not_the_request(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create();

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', $this->payload([
            'currency' => 'GBP',
        ]))->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', $this->payload([
            'currency' => 'usd',
        ]))->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', $this->payload([
            'currency' => 'AED',
        ]))->assertOk()->assertJsonPath('data.currency', 'AED');

        $this->assertSame(1, DB::table('employee_bank_accounts')->count());
    }

    public function test_the_endpoint_is_guarded_even_though_no_middleware_is(): void
    {
        $this->getJson('/api/v1/employees/1/bank-account')->assertUnauthorized();

        $employee = Employee::factory()->create();

        // No employee behind this token, and no grant that would stand in
        // for one: 403 from the policy, which is the only thing guarding
        // this endpoint.
        $this->become(User::factory()->create());

        $this->getJson('/api/v1/employees/'.$employee->id.'/bank-account')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_holder_name' => 'Aisha Rahman',
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE070331234567890123456',
            'account_number' => '1234567890123456',
            'swift_bic' => 'EBILAEAD',
            'currency' => 'AED',
        ], $overrides);
    }
}
