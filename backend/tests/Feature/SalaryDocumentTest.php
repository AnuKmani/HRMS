<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\SalaryCertificateRequest;
use App\Services\Payroll\SalaryCertificatePdf;
use App\Services\Payroll\SalarySlipPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * /api/v1/salary-slips and /api/v1/salary-certificate-requests - the two
 * documents payroll produces, and the two places a figure leaves the system.
 *
 * They share one contract with Phase 7's site report and it is the reason
 * this file exists separately from `PayrollTest`:
 *
 *  - **rendered on demand, never stored.** Nothing is written to disk, so
 *    nothing can go stale beside the row it was copied from - which matters
 *    more here than there, because payroll rows are legitimately
 *    recalculated while they are still `calculated`.
 *
 *  - **private.** `no-store`, an `inline` disposition with a name derived
 *    from the row, and both the permission *and* the row-level policy.
 *    Revoking one while leaving the other is proved, not asserted twice.
 *
 *  - **a certificate is issued once.** `generated_at` moves on the first
 *    render and never again, so it answers "when was this issued?" rather
 *    than "when did somebody last look?".
 */
class SalaryDocumentTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /* -------------------------------------------------------- salary slip */

    public function test_a_slip_is_rendered_from_the_row_and_never_stored(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);
        $this->process();

        $payroll = $this->payrollFor($employee->id);

        $response = $this->get("/api/v1/salary-slips/{$payroll->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        // The whole directive list rather than an exact string: Symfony and
        // dompdf each add to it, and the order they arrive in is nobody's
        // contract. What is a contract is that no cache may keep this.
        $this->assertStringContainsString('no-store', strtolower((string) $response->headers->get('Cache-Control')));

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('salary-slip-', $disposition);

        // The content itself, asserted on the markup rather than on
        // compressed bytes - a test that greps a PDF for a number is a
        // test of dompdf's compressor, not of this module.
        $html = app(SalarySlipPdf::class)->html($payroll->load([
            'employee.department',
            'employee.designation',
            'items',
        ]));

        foreach (['Salary slip', 'September 2026', 'Basic salary', 'Gross salary', 'Net salary'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString('AED 30000.00', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_a_slip_is_readable_by_its_owner_and_by_nobody_without_the_slip_grant(): void
    {
        [$mineUser, $mine] = $this->makeSeat('Employee', ['salary' => 30000]);
        [, $theirs] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);
        $this->process();

        $myId = $this->payrollFor($mine->id)->id;
        $theirId = $this->payrollFor($theirs->id)->id;

        $this->become($mineUser);

        $this->get("/api/v1/salary-slips/{$myId}/pdf")->assertOk();
        $this->get("/api/v1/salary-slips/{$theirId}/pdf")->assertForbidden();

        $items = $this->getJson('/api/v1/salary-slips?year=2026&month=9')
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$mine->id], array_column($items, 'employee_id'));

        // The site roles hold neither `salary_slips.view` nor `payroll.view`
        // - the route's coarse gate answers before any policy is asked.
        foreach (['Project Manager', 'Site Supervisor'] as $role) {
            $this->signInAs($role, ['salary' => 30000]);

            $this->getJson('/api/v1/salary-slips?year=2026')->assertForbidden();
            $this->get("/api/v1/salary-slips/{$myId}/pdf")->assertForbidden();
        }
    }

    /* -------------------------------------------------- certificate asks */

    public function test_an_employee_asks_for_their_own_certificate_and_states_what_it_is_for(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        $request = $this->postJson('/api/v1/salary-certificate-requests', [
            'purpose' => 'Housing loan with National Bank.',
        ])->assertCreated()->json('data');

        $this->assertSame($employee->id, $request['employee_id']);
        $this->assertSame('pending', $request['status']);
        $this->assertStringStartsWith('SAL-CERT-', $request['reference']);
        $this->assertFalse($request['can_issue']);
        $this->assertNull($request['generated_at']);

        // "For whatever they want" is not a document anybody can audit
        // afterwards, so the purpose is not optional.
        $this->postJson('/api/v1/salary-certificate-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose');

        // No decision columns come in over the wire - they belong to the
        // service and to nobody else.
        $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $employee->id,
            'purpose' => 'Visa application.',
            'status' => 'generated',
            'approved_by' => $employee->id,
            'generated_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(2, SalaryCertificateRequest::query()->count());
    }

    public function test_nobody_signs_off_on_their_own_certificate_even_when_they_hold_the_grant(): void
    {
        // HR Admin holds `salary_certificates.manage`, so the route's
        // coarse gate opens and the refusal has to come from the rule.
        [, $employee] = $this->signInAs('HR Admin', ['salary' => 30000]);

        $id = $this->postJson('/api/v1/salary-certificate-requests', [
            'purpose' => 'Mortgage renewal.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/salary-certificate-requests/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/salary-certificate-requests/{$id}/reject")->assertForbidden();

        $row = SalaryCertificateRequest::query()->findOrFail($id);
        $this->assertSame('pending', $row->status, 'The refusal blocked the act, not the request.');
        $this->assertNull($row->approved_at);
    }

    public function test_a_request_may_only_be_filed_about_someone_else_by_a_decision_maker(): void
    {
        [, $colleague] = $this->makeSeat('Employee', ['salary' => 30000]);
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $colleague->id,
            'purpose' => 'On their behalf, for no reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        // An HR desk may file one for somebody else - that is what
        // `salary_certificates.manage` is for.
        $this->signInAs('HR Admin', ['salary' => 30000]);
        $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $colleague->id,
            'purpose' => 'Bank verification for a staff loan.',
        ])->assertCreated();
    }

    /* ------------------------------------------------- certificate decide */

    public function test_a_certificate_is_decided_once_issued_once_and_then_frozen(): void
    {
        // Filed from the employee's own session, exactly as the earlier
        // test files one - a request that has nobody behind it is a 401,
        // not a request.
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);
        $approver = $this->makeSeat('HR Admin', ['salary' => 30000])[0];

        $id = $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $employee->id,
            'purpose' => 'Rental agreement.',
        ])->assertCreated()->json('data.id');

        $this->become($approver);

        // Not issuable before the decision, and the refusal names the
        // state rather than the permission.
        $this->get("/api/v1/salary-certificate-requests/{$id}/pdf")->assertStatus(409);

        $this->postJson("/api/v1/salary-certificate-requests/{$id}/approve", [
            'remarks' => 'Verified against payroll.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        // Decided once, and the second attempt is answered by the policy
        // rather than the service: LoanPolicy::approve and
        // LeaveRequestPolicy both refuse an already-decided row the same
        // way, and three modules disagreeing about whether a re-decision
        // is "you may not" or "that cannot happen any more" would be
        // worse for a client than either answer alone.
        $this->postJson("/api/v1/salary-certificate-requests/{$id}/approve")->assertForbidden();

        $issued = $this->get("/api/v1/salary-certificate-requests/{$id}/pdf");

        $issued->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $issued->headers->get('Cache-Control')),
        );

        $row = SalaryCertificateRequest::query()->findOrFail($id);
        $this->assertSame('generated', $row->status);
        $this->assertNotNull($row->generated_at);
        $this->assertSame($approver->id, $row->approved_by);

        $issuedAt = $row->generated_at;

        // The second render changes nothing: `generated_at` answers "when
        // was this issued?", never "when did somebody last look?".
        $this->travel(5)->minutes();
        $this->get("/api/v1/salary-certificate-requests/{$id}/pdf")->assertOk();

        $row->refresh();
        $this->assertSame('generated', $row->status);
        $this->assertTrue($issuedAt->equalTo($row->generated_at));

        // And once issued it can no longer be cancelled - the document is
        // HR's record as much as the employee's.
        $this->postJson("/api/v1/salary-certificate-requests/{$id}/cancel")->assertStatus(409);
    }

    public function test_a_refused_certificate_cannot_be_issued(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);
        $approver = $this->makeSeat('HR Admin', ['salary' => 30000])[0];

        $id = $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $employee->id,
            'purpose' => 'Visa.',
        ])->assertCreated()->json('data.id');

        $this->become($approver);
        $this->postJson("/api/v1/salary-certificate-requests/{$id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // The permission still says yes; the state says no, and the state
        // wins with a message that names it.
        $this->get("/api/v1/salary-certificate-requests/{$id}/pdf")->assertStatus(409);
        $this->assertNull(SalaryCertificateRequest::query()->findOrFail($id)->generated_at);
    }

    public function test_a_certificate_is_readable_only_by_the_employee_it_is_about_or_a_decision_maker(): void
    {
        [$mineUser, $mine] = $this->makeSeat('Employee', ['salary' => 30000]);
        [, $theirs] = $this->makeSeat('Employee', ['salary' => 30000]);

        // Signed in first: filing the request *is* the act under test, and
        // an unauthenticated POST would answer 401 before any rule was asked.
        $this->become($mineUser);

        $myId = $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $mine->id,
            'purpose' => 'Bank KYC.',
        ])->assertCreated()->json('data.id');

        // The colleague's row is filed from an HR desk: an employee holding
        // only `salary_certificates.view` is refused outright for asking
        // about somebody else, so the second request has to come from a
        // session the server will accept it from.
        $this->become($this->makeSeat('HR Admin')[0]);

        $theirId = $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $theirs->id,
            'purpose' => 'Not mine to read.',
        ])->assertCreated()->json('data.id');

        // Failing closed to own-only: `salary_certificates.view` alone
        // never reaches a colleague's request, and no `employees.view`
        // fallback widens it.
        $this->become($mineUser);
        $items = $this->getJson('/api/v1/salary-certificate-requests')
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$mine->id], array_column($items, 'employee_id'));

        $this->getJson("/api/v1/salary-certificate-requests/{$theirId}")->assertForbidden();
        $this->getJson("/api/v1/salary-certificate-requests/{$myId}")->assertOk();
        $this->get("/api/v1/salary-certificate-requests/{$theirId}/pdf")->assertForbidden();

        // Somebody who may manage them reads every one of them.
        $this->become($this->makeSeat('HR Admin')[0]);
        $this->getJson("/api/v1/salary-certificate-requests/{$theirId}")->assertOk();
    }

    public function test_the_certificate_prints_the_facts_it_certifies(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 125000]);
        $this->become($this->makeSeat('HR Admin', ['salary' => 30000])[0]);

        $id = $this->postJson('/api/v1/salary-certificate-requests', [
            'employee_id' => $employee->id,
            'purpose' => 'Bank verification.',
        ])->assertCreated()->json('data.id');

        $request = SalaryCertificateRequest::query()->findOrFail($id);
        $request->status = SalaryCertificateRequest::STATUS_APPROVED;
        $request->save();

        $html = app(SalaryCertificatePdf::class)->html(
            $request->load(['employee.department', 'employee.designation']),
        );

        foreach ([
            'SALARY CERTIFICATE',
            $request->reference(),
            $employee->full_name,
            $employee->employee_code,
            'Current monthly salary',
            'AED 125000.00',
            'Authorised signatory',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // Honest about what it is: no signature it does not have, and no
        // claim that a reader could take as one.
        $this->assertStringContainsString('not digitally signed', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /* ----------------------------------------------------------- helpers */

    private function process(): void
    {
        $this->postJson('/api/v1/payroll/process', ['year' => self::YEAR, 'month' => self::MONTH])
            ->assertOk();
    }

    private function payrollFor(int $employeeId): Payroll
    {
        $payroll = Payroll::query()
            ->where('employee_id', $employeeId)
            ->where('payroll_year', self::YEAR)
            ->where('payroll_month', self::MONTH)
            ->first();

        $this->assertNotNull($payroll, "No payroll row for employee {$employeeId}.");

        return $payroll;
    }
}
