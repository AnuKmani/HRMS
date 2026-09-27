import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/overtime/domain/overtime_request.dart';

OvertimeRequest overtime({
  String status = OvertimeRequest.statusPending,
  int requested = 120,
  int? approved,
  bool payrollEligible = false,
}) => OvertimeRequest.fromJson(<String, dynamic>{
  'id': 7,
  'employee_id': 1,
  'overtime_date': '2026-09-27',
  'requested_minutes': requested,
  'requested_hours': requested / 60,
  'approved_minutes': approved,
  'approved_hours': approved == null ? null : approved / 60,
  'reason': 'Handover.',
  'status': status,
  'payroll_eligible': payrollEligible,
});

void main() {
  group('what is worth paying', () {
    test('an approved claim with no separate figure grants the whole '
        'request', () {
      final claim = overtime(
        status: OvertimeRequest.statusApproved,
        requested: 120,
        approved: null,
      );

      // The server sends nothing for `approved_minutes` when the approver
      // allowed everything — absent and zero are different answers — so the
      // payable figure has to fall back to what was asked rather than
      // reading the absence as a refusal.
      expect(claim.payableMinutes, 120);
    });

    test('an approved claim that was trimmed pays the trim, not the ask', () {
      final claim = overtime(
        status: OvertimeRequest.statusApproved,
        requested: 120,
        approved: 45,
      );

      expect(claim.payableMinutes, 45);
    });

    test('nothing is payable until it is approved, whatever was granted', () {
      for (final status in [
        OvertimeRequest.statusDraft,
        OvertimeRequest.statusPending,
        OvertimeRequest.statusRejected,
        OvertimeRequest.statusCancelled,
      ]) {
        final claim = overtime(status: status, requested: 120, approved: 45);

        expect(
          claim.payableMinutes,
          isNull,
          reason: '`$status` must not reach a payroll run',
        );
      }
    });
  });

  group('the payroll flag', () {
    test('is taken from the server and never inferred from the status', () {
      // The two are independent on purpose: an approved claim the server
      // did not mark eligible is possible, and a client that computed the
      // flag from `status` would answer the other way with no way to notice.
      final claim = overtime(
        status: OvertimeRequest.statusApproved,
        approved: 60,
        payrollEligible: false,
      );

      expect(claim.isApproved, isTrue);
      expect(claim.payrollEligible, isFalse);
      expect(claim.payableMinutes, 60);
    });

    test("the resource's `is_payroll_eligible` reads the same as the flag", () {
      final claim = OvertimeRequest.fromJson(<String, dynamic>{
        'id': 1,
        'employee_id': 1,
        'overtime_date': '2026-09-27',
        'requested_minutes': 60,
        'requested_hours': 1,
        'reason': 'Handover.',
        'status': OvertimeRequest.statusApproved,
        'payroll_eligible': false,
        'is_payroll_eligible': true,
      });

      expect(claim.payrollEligible, isTrue);
    });
  });

  group('the labels the screens read', () {
    test('the status label never spells `pending` at the user', () {
      expect(
        overtime(status: OvertimeRequest.statusDraft).statusLabel,
        'Draft',
      );
      expect(
        overtime(status: OvertimeRequest.statusPending).statusLabel,
        'Awaiting approval',
      );
      expect(
        overtime(status: OvertimeRequest.statusApproved).statusLabel,
        'Approved',
      );
      expect(
        overtime(status: OvertimeRequest.statusRejected).statusLabel,
        'Rejected',
      );
      expect(
        overtime(status: OvertimeRequest.statusCancelled).statusLabel,
        'Cancelled',
      );
    });

    test('the ask and the grant are both present, in both units', () {
      final claim = overtime(
        status: OvertimeRequest.statusApproved,
        requested: 120,
        approved: 45,
      );

      expect(claim.requestedLabel, '120 min · 2.00 h');
      expect(claim.approvedLabel, '45 min · 0.75 h');
    });

    test('editing is only ever a draft', () {
      expect(overtime(status: OvertimeRequest.statusDraft).isEditable, isTrue);

      for (final status in [
        OvertimeRequest.statusPending,
        OvertimeRequest.statusApproved,
        OvertimeRequest.statusRejected,
        OvertimeRequest.statusCancelled,
      ]) {
        expect(
          overtime(status: status).isEditable,
          isFalse,
          reason: '`$status` has left the claimant\'s hands',
        );
      }
    });
  });
}
