import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/leave/domain/leave_request.dart';
import 'package:mobile/features/leave/presentation/leave_list_screen.dart';

import '../../../support/phase6.dart';

/// A request built the way the API sends one, so the test cannot drift into
/// asserting against a constructor the screen never actually receives.
LeaveRequest leave({
  required int id,
  String status = LeaveRequest.statusPending,
  String type = 'Annual Leave',
  double days = 5,
}) => LeaveRequest.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': 1,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'leave_type_id': 1,
  'leave_type': <String, dynamic>{'name': type, 'code': 'AL'},
  'start_date': '2026-09-28',
  'end_date': '2026-10-02',
  'summary': '5 working days',
  'requested_days': days,
  'status': status,
  'certificate': <String, dynamic>{
    'required': false,
    'has_file': false,
    'overdue': false,
  },
});

void main() {
  late ScriptedLeave script;

  setUp(() {
    script = ScriptedLeave(
      idOf: (item) => item.id,
      items: [
        leave(id: 1),
        leave(
          id: 2,
          status: LeaveRequest.statusApproved,
          type: 'Earned Leave',
          days: 1,
        ),
        leave(
          id: 3,
          status: LeaveRequest.statusRejected,
          type: 'Unpaid Leave',
          days: 2,
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['leave.view'],
  }) async {
    await tester.pumpWidget(
      scopedPhase6(
        permissions: permissions,
        leave: script,
        child: const MaterialApp(home: LeaveListScreen()),
      ),
    );
  }

  testWidgets('a session without leave.view is never asked for requests', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['attendance.view']);
    await advance(tester);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    // The controller is not built behind the gate, so no request left the
    // device — the whole point of the branch.
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('apply-leave')), findsNothing);
    expect(find.byKey(const ValueKey('leave-balances')), findsNothing);
  });

  testWidgets('rows say which leave, over which days, and where it stands', (
    tester,
  ) async {
    await pumpList(tester);
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(find.text('Annual Leave · 28 Sep – 02 Oct'), findsOneWidget);
    expect(find.text('5.0 days · Anu Kmani'), findsOneWidget);
    expect(find.text('Awaiting approval'), findsOneWidget);
  });

  testWidgets('says the list is empty rather than drawing nothing', (
    tester,
  ) async {
    script.items.clear();

    await pumpList(tester);
    await advance(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No leave requests yet.'), findsOneWidget);
    expect(find.byKey(const ValueKey('list-error')), findsNothing);
  });

  testWidgets('the status filter is a query parameter, and clearing it '
      'removes the key', (tester) async {
    await pumpList(tester);
    await advance(tester);

    expect(script.lastQuery, isNot(contains('status')));

    await tester.tap(find.byKey(const ValueKey('leave-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approved').last);
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'approved');

    // Absent and empty are different questions to the API, so the empty
    // value must not be sent as `status=`.
    await tester.tap(find.byKey(const ValueKey('leave-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All statuses'));
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'status', null);
  });

  testWidgets('apply and balances appear only for the permissions that '
      'would honour them', (tester) async {
    await pumpList(tester, permissions: const ['leave.view']);
    await advance(tester);

    expect(find.byKey(const ValueKey('apply-leave')), findsNothing);
    expect(find.byKey(const ValueKey('leave-balances')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['leave.view', 'leave.create', 'leave.balance.view'],
    );
    await advance(tester);

    expect(find.byKey(const ValueKey('apply-leave')), findsOneWidget);
    expect(find.byKey(const ValueKey('leave-balances')), findsOneWidget);
  });
}
