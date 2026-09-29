import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/expenses/domain/expense.dart';

Expense claim({
  String status = Expense.statusDraft,
  bool requiresReceipt = false,
  String? maximumAmount,
  int receiptCount = 0,
  bool chain = false,
  String amount = '1234.56',
  bool employeeObject = true,
}) => Expense.fromJson(<String, dynamic>{
  'id': 4,
  'employee_id': employeeObject ? 9 : null,
  'employee': employeeObject
      ? <String, dynamic>{'full_name': 'Anu Kmani'}
      : null,
  'expense_category_id': 2,
  'category': <String, dynamic>{
    'id': 2,
    'name': 'Food',
    'code': 'FOOD',
    'requires_receipt': requiresReceipt,
    'maximum_amount': maximumAmount,
  },
  'project_id': 11,
  'project': <String, dynamic>{'id': 11, 'name': 'Riverfront Towers'},
  'site_id': 3,
  'site': <String, dynamic>{'id': 3, 'name': 'Block A'},
  'expense_date': '2026-09-28',
  'amount': amount,
  'currency': 'AED',
  'description': 'Lunch for the pour crew.',
  'status': status,
  'is_draft': status == Expense.statusDraft,
  'is_open': status == Expense.statusDraft || status == Expense.statusPending,
  'summary': 'Food · 2026-09-28 · 1234.56 AED',
  'requires_receipt': requiresReceipt,
  'maximum_amount': maximumAmount,
  'receipt_count': receiptCount,
  'current_approval_step': chain ? 1 : null,
  'approval_chain': chain
      ? <dynamic>[
          <String, dynamic>{
            'id': 1,
            'sequence': 1,
            'name': 'Standard expense approval',
            'approver_type': 'reporting_manager',
            'status': 'pending',
            'is_current': true,
            'resolved_approver': <String, dynamic>{'full_name': 'Sara Iqbal'},
          },
          <String, dynamic>{
            'id': 2,
            'sequence': 2,
            'name': 'Finance sign-off',
            'approver_type': 'permission',
            'approver_permission': 'expenses.manage',
            'status': 'pending',
            'is_current': false,
          },
        ]
      : null,
  'submitted_at': status == Expense.statusDraft ? null : '2026-09-28T09:30:00Z',
  'approved_at': status == Expense.statusApproved
      ? '2026-09-28T16:00:00Z'
      : null,
  'rejected_at': status == Expense.statusRejected
      ? '2026-09-28T16:00:00Z'
      : null,
  'cancelled_at': status == Expense.statusCancelled
      ? '2026-09-28T16:00:00Z'
      : null,
});

void main() {
  group('the figure on a claim', () {
    test('arrives as a decimal string and is never parsed into a double', () {
      final expense = claim(amount: '1234.56');

      // DECIMAL(12,2) on the server, `Money::decimal()` in the payload. A
      // `double` here would let `1234.56` become `1234.5600000000001` on the
      // way through a widget — and money that reads differently from the
      // paper receipt is the bug nobody can argue with afterwards.
      expect(expense.amount, '1234.56');
      expect(expense.amount, isA<String>());
      expect(expense.currency, 'AED');
    });

    test('a zero-padded figure survives the trip unchanged', () {
      expect(claim(amount: '0.10').amount, '0.10');
      expect(claim(amount: '99999999.99').amount, '99999999.99');
    });
  });

  group('what each state permits', () {
    test('only a draft may be edited, submitted or given receipts', () {
      expect(claim(status: Expense.statusDraft).isEditable, isTrue);
      expect(claim(status: Expense.statusDraft).isOpen, isTrue);
      expect(claim(status: Expense.statusDraft).hasDecision, isFalse);

      for (final status in [
        Expense.statusPending,
        Expense.statusApproved,
        Expense.statusRejected,
        Expense.statusCancelled,
      ]) {
        expect(
          claim(status: status).isEditable,
          isFalse,
          reason: '`$status` has left the claimant\'s hands',
        );
      }
    });

    test('only a claim somebody has answered carries a decision', () {
      // Waiting is not a decision: an approval timestamp on a claim still in
      // the chain would be the app reporting an outcome nobody gave.
      expect(claim(status: Expense.statusPending).hasDecision, isFalse);

      for (final status in [
        Expense.statusApproved,
        Expense.statusRejected,
        Expense.statusCancelled,
      ]) {
        expect(
          claim(status: status).hasDecision,
          isTrue,
          reason: '`$status` is an answer',
        );
      }
    });

    test('a claim in the chain is open, a settled one is not', () {
      expect(claim(status: Expense.statusPending).isOpen, isTrue);
      expect(claim(status: Expense.statusPending).isAwaitingDecision, isTrue);

      for (final status in [
        Expense.statusApproved,
        Expense.statusRejected,
        Expense.statusCancelled,
      ]) {
        expect(
          claim(status: status).isOpen,
          isFalse,
          reason: '`$status` must not appear as withdrawable',
        );
      }
    });

    test('nothing is awaiting a decision until it is submitted', () {
      expect(claim(status: Expense.statusDraft).isAwaitingDecision, isFalse);
      expect(claim(status: Expense.statusPending).isAwaitingDecision, isTrue);
    });
  });

  group('the labels the screens read', () {
    test('the status label never spells `pending` at the user', () {
      expect(claim(status: Expense.statusDraft).statusLabel, 'Draft');
      expect(
        claim(status: Expense.statusPending).statusLabel,
        'Awaiting approval',
      );
      expect(claim(status: Expense.statusApproved).statusLabel, 'Approved');
      expect(claim(status: Expense.statusRejected).statusLabel, 'Rejected');
      expect(claim(status: Expense.statusCancelled).statusLabel, 'Cancelled');
    });

    test('the receipt count reads as one, as many, and as none', () {
      expect(claim(receiptCount: 0).receiptLabel, 'No receipts yet');
      expect(claim(receiptCount: 1).receiptLabel, '1 receipt');
      expect(claim(receiptCount: 3).receiptLabel, '3 receipts');
    });

    test('the category names itself even when the row was not sent', () {
      final withoutCategory = Expense.fromJson(<String, dynamic>{
        'id': 1,
        'employee_id': 1,
        'expense_category_id': 5,
        'expense_date': '2026-09-28',
        'amount': '10.00',
        'currency': 'INR',
        'description': 'Parking.',
        'status': Expense.statusDraft,
      });

      expect(withoutCategory.categoryName, isEmpty);
      expect(withoutCategory.needsReceipt, isFalse);
    });
  });

  group('the two category rules, as the claim carries them', () {
    test('the receipt requirement comes off the claim\'s own copy', () {
      expect(claim(requiresReceipt: true).needsReceipt, isTrue);
      expect(claim(requiresReceipt: false).needsReceipt, isFalse);
    });

    test('a claim with no rule of its own falls back to its category row', () {
      final expense = Expense.fromJson(<String, dynamic>{
        'id': 1,
        'employee_id': 1,
        'expense_category_id': 2,
        'category': <String, dynamic>{
          'id': 2,
          'name': 'Travel',
          'requires_receipt': true,
          'maximum_amount': null,
        },
        'expense_date': '2026-09-28',
        'amount': '10.00',
        'currency': 'INR',
        'description': 'Fare.',
        'status': Expense.statusDraft,
      });

      expect(expense.needsReceipt, isTrue);
      expect(expense.maximumAmount, isNull);
    });
  });

  group('who the claim belongs to', () {
    test('the employee is read from the brief resource `full_name`', () {
      // The three nested objects are not spelled alike: an employee brief
      // sends `full_name`, while a project and a site send `name`. One
      // helper reading only `name` would leave a detail screen blank for
      // the person column and nothing else.
      expect(claim().employeeName, 'Anu Kmani');
      expect(claim().projectName, 'Riverfront Towers');
      expect(claim().siteName, 'Block A');
    });

    test('an absent employee object leaves the name null rather than '
        'throwing', () {
      final expense = claim(employeeObject: false);

      expect(expense.employeeName, isNull);
      expect(expense.employeeId, 0);
    });
  });

  group('the approval chain', () {
    test('is absent from a list row and present on the detail view', () {
      expect(claim().approvalChain, isNull);
      expect(claim(chain: true).approvalChain, hasLength(2));
    });

    test('the steps keep their sequence and their approver', () {
      final steps = claim(chain: true).approvalChain!;

      expect(steps.first.sequence, 1);
      expect(steps.first.name, 'Standard expense approval');
      expect(steps.first.approverType, 'reporting_manager');
      expect(steps.first.isCurrent, isTrue);
      expect(steps.first.resolvedApproverName, 'Sara Iqbal');

      expect(steps.last.sequence, 2);
      expect(steps.last.approverPermission, 'expenses.manage');
      expect(steps.last.isCurrent, isFalse);
    });
  });
}
