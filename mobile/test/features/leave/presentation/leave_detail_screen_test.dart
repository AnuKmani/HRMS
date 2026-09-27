import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/leave/domain/leave_request.dart';
import 'package:mobile/features/leave/presentation/leave_detail_screen.dart';

import '../../../support/phase6.dart';

LeaveRequest leave({
  required int id,
  String status = LeaveRequest.statusPending,
  bool certificateRequired = false,
  bool certificateFiled = false,
  bool certificateOverdue = false,
  String? dueAt,
}) => LeaveRequest.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': 1,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'leave_type_id': 1,
  'leave_type': <String, dynamic>{'name': 'Sick Leave', 'code': 'SL'},
  'start_date': '2026-09-28',
  'end_date': '2026-10-02',
  'summary': '5 working days',
  'requested_days': 5,
  'reason': 'Recovering after surgery.',
  'status': status,
  'certificate': <String, dynamic>{
    'required': certificateRequired,
    'has_file': certificateFiled,
    'overdue': certificateOverdue,
    'due_at': ?dueAt,
    if (certificateFiled) 'original_name': 'certificate.jpg',
    if (certificateFiled) 'uploaded_at': '2026-09-30T10:15:00.000000Z',
  },
});

void main() {
  late ScriptedLeave script;

  setUp(() {
    script = ScriptedLeave(idOf: (item) => item.id, items: [leave(id: 1)]);
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    List<String> permissions = const ['leave.view'],
  }) async {
    // The record plus its buttons is far taller than the 800×600 test
    // surface; an action button outside the viewport is not in the tree at
    // all, and a `find` that misses it would fail for a reason unrelated to
    // permissions.
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase6(
        permissions: permissions,
        leave: script,
        child: const MaterialApp(home: LeaveDetailScreen(leaveId: 1)),
      ),
    );
    await advance(tester);
  }

  group('which actions a state is offered', () {
    testWidgets('a draft offers submit, edit and cancel — and no approval', (
      tester,
    ) async {
      script = ScriptedLeave(
        idOf: (item) => item.id,
        items: [leave(id: 1, status: LeaveRequest.statusDraft)],
      );

      await pumpDetail(tester);

      expect(find.byKey(const ValueKey('submit-leave')), findsOneWidget);
      expect(find.byKey(const ValueKey('edit-leave')), findsOneWidget);
      expect(find.byKey(const ValueKey('cancel-leave')), findsOneWidget);
      expect(find.byKey(const ValueKey('approve-leave')), findsNothing);
      expect(find.byKey(const ValueKey('reject-leave')), findsNothing);
    });

    testWidgets('a pending request offered to its approver carries approve '
        'and reject, and no cancel', (tester) async {
      await pumpDetail(
        tester,
        permissions: const ['leave.view', 'leave.approve'],
      );

      expect(find.byKey(const ValueKey('approve-leave')), findsOneWidget);
      expect(find.byKey(const ValueKey('reject-leave')), findsOneWidget);
      // Cancel is the owner's escape hatch; an approver at the current step
      // is neither the owner nor `leave.manage`, so the button would only
      // ever answer 403.
      expect(find.byKey(const ValueKey('cancel-leave')), findsNothing);
      expect(find.byKey(const ValueKey('submit-leave')), findsNothing);
    });

    testWidgets('a pending request shown to its owner offers cancel, not a '
        'decision', (tester) async {
      await pumpDetail(tester, permissions: const ['leave.view']);

      expect(find.byKey(const ValueKey('cancel-leave')), findsOneWidget);
      expect(find.byKey(const ValueKey('approve-leave')), findsNothing);
      expect(find.byKey(const ValueKey('reject-leave')), findsNothing);
    });

    testWidgets('a finished request offers nothing to press', (tester) async {
      script = ScriptedLeave(
        idOf: (item) => item.id,
        items: [leave(id: 1, status: LeaveRequest.statusApproved)],
      );

      await pumpDetail(
        tester,
        permissions: const ['leave.view', 'leave.approve'],
      );

      expect(find.byKey(const ValueKey('approve-leave')), findsNothing);
      expect(find.byKey(const ValueKey('reject-leave')), findsNothing);
      expect(find.byKey(const ValueKey('cancel-leave')), findsNothing);
      expect(find.byKey(const ValueKey('submit-leave')), findsNothing);
      expect(find.text('Approved'), findsOneWidget);
    });
  });

  group('the certificate', () {
    testWidgets('is not drawn at all for a type that never asks for one', (
      tester,
    ) async {
      await pumpDetail(tester);

      expect(find.byKey(const ValueKey('file-certificate')), findsNothing);
      expect(find.text('Medical certificate'), findsNothing);
    });

    testWidgets('is owed, unfiled and offerable to photograph', (tester) async {
      script = ScriptedLeave(
        idOf: (item) => item.id,
        items: [leave(id: 1, certificateRequired: true, dueAt: '2026-10-04')],
      );

      await pumpDetail(
        tester,
        permissions: const ['leave.view', 'leave.create'],
      );

      expect(find.text('Medical certificate'), findsOneWidget);
      expect(find.text('Certificate due 2026-10-04'), findsOneWidget);
      expect(find.byKey(const ValueKey('file-certificate')), findsOneWidget);
      expect(find.byKey(const ValueKey('view-certificate')), findsNothing);
    });

    testWidgets('is overdue only when the server says so', (tester) async {
      script = ScriptedLeave(
        idOf: (item) => item.id,
        items: [
          leave(
            id: 1,
            certificateRequired: true,
            certificateOverdue: true,
            dueAt: '2026-10-04',
          ),
        ],
      );

      await pumpDetail(tester);

      expect(find.text('Certificate overdue'), findsOneWidget);
      expect(find.text('Overdue'), findsOneWidget);
    });
  });

  group('acting on a request', () {
    testWidgets('reject refuses to send an empty reason, and then sends the '
        'one given', (tester) async {
      await pumpDetail(
        tester,
        permissions: const ['leave.view', 'leave.approve'],
      );

      await tester.tap(find.byKey(const ValueKey('reject-leave')));
      await tester.pumpAndSettle();

      expect(find.text('Reject this request?'), findsOneWidget);
      expect(script.lastTransition, isNull);

      // The dialog will not let an unexplained refusal leave the device.
      // `.last`: the screen's own FilledButton has the same label, and the
      // dialog sits later in the tree above it.
      await tester.tap(find.widgetWithText(FilledButton, 'Reject').last);
      await tester.pumpAndSettle();

      expect(find.text('Say why before confirming.'), findsOneWidget);
      expect(script.lastTransition, isNull);

      await tester.enterText(find.byType(TextField), 'No cover available.');
      await tester.tap(find.widgetWithText(FilledButton, 'Reject').last);
      await tester.pumpAndSettle();

      expect(script.lastTransition, 'reject');
      expect(script.lastTransitionId, 1);
      expect(script.lastRemarks, 'No cover available.');
    });

    testWidgets('submit carries no remarks when none were typed', (
      tester,
    ) async {
      script = ScriptedLeave(
        idOf: (item) => item.id,
        items: [leave(id: 1, status: LeaveRequest.statusDraft)],
      );

      await pumpDetail(tester);

      await tester.tap(find.byKey(const ValueKey('submit-leave')));
      await tester.pumpAndSettle();

      await tester.tap(find.widgetWithText(FilledButton, 'Submit').last);
      await tester.pumpAndSettle();

      expect(script.lastTransition, 'submit');
      expect(script.lastTransitionId, 1);
      expect(script.lastRemarks, '');
    });

    testWidgets('a refused action is reported above the record, not as a '
        'field error', (tester) async {
      await pumpDetail(
        tester,
        permissions: const ['leave.view', 'leave.approve'],
      );

      script.actionError = forbidden403;

      await tester.tap(find.byKey(const ValueKey('approve-leave')));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Approve').last);
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('leave-banner')), findsOneWidget);
      expect(find.textContaining('forbidden'), findsOneWidget);
      expect(find.byKey(const ValueKey('leave-error')), findsNothing);
    });
  });
}
