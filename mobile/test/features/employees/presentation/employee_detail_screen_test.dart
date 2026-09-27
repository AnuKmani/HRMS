import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/employees/presentation/employee_detail_screen.dart';

import '../../../support/phase4.dart';

const denied = ApiException(
  statusCode: 403,
  message: 'You are not allowed to view this employee.',
);

Employee employee({double? salary, bool salaryVisible = false}) => Employee(
      id: 12,
      employeeCode: 'EMP-1001',
      firstName: 'Asha',
      lastName: 'Nair',
      fullName: 'Asha Nair',
      email: 'asha@example.com',
      joiningDate: '2024-03-01',
      departmentName: 'Human Resources',
      designationName: 'Site Engineer',
      employmentType: 'permanent',
      employmentStatus: 'active',
      salary: salary,
      salaryVisible: salaryVisible,
    );

void main() {
  late ScriptedEmployees script;

  setUp(() {
    script = ScriptedEmployees(idOf: (item) => item.id, items: [employee()]);
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    required List<String> permissions,
  }) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: permissions,
        employees: script,
        child: const MaterialApp(home: EmployeeDetailScreen(employeeId: 12)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a 403 is shown as a refusal, not as a broken screen',
      (tester) async {
    script.findError = denied;

    await pumpDetail(tester, permissions: const ['employees.view']);

    expect(find.byKey(const ValueKey('detail-error')), findsOneWidget);
    expect(find.text(denied.message), findsOneWidget);
    expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    expect(find.byIcon(Icons.error_outline), findsNothing);

    // Retry is still offered: the refusal may be temporary (a role change
    // made server-side a moment ago), and there is no reason to strand the
    // user without a way to ask again.
    expect(find.byKey(const ValueKey('detail-retry')), findsOneWidget);
    expect(script.findCalls, 1);

    script.items.clear();
    script.items.add(employee(salary: 150000.50, salaryVisible: true));
    await tester.tap(find.byKey(const ValueKey('detail-retry')));
    await advance(tester);

    expect(script.findCalls, 2);
    expect(find.byKey(const ValueKey('detail-error')), findsNothing);
    expect(find.byKey(const ValueKey('detail-name')), findsOneWidget);
  });

  testWidgets('draws the roster fields without a salary for a viewer',
      (tester) async {
    await pumpDetail(tester, permissions: const ['employees.view']);

    expect(find.text('Asha Nair'), findsOneWidget);
    expect(find.text('EMP-1001'), findsOneWidget);
    expect(find.text('Human Resources'), findsOneWidget);

    // Two gates, both required: the permission the session holds *and* the
    // flag the server put on the payload. Neither alone is enough to draw a
    // figure this user may not know.
    expect(find.byKey(const ValueKey('detail-salary')), findsNothing);
    expect(find.text('Compensation'), findsNothing);
    // The edit control is likewise a promise the API would not keep.
    expect(find.byKey(const ValueKey('detail-edit')), findsNothing);
    expect(find.byKey(const ValueKey('detail-delete')), findsNothing);
  });

  testWidgets('draws the salary for a role allowed to see it',
      (tester) async {
    script.items.clear();
    script.items.add(employee(salary: 150000.50, salaryVisible: true));

    await pumpDetail(
      tester,
      permissions: const [
        'employees.view',
        'employees.salary.view',
        'employees.update',
        'employees.delete',
      ],
    );

    expect(find.byKey(const ValueKey('detail-salary')), findsOneWidget);
    expect(find.text('150000.50'), findsOneWidget);

    expect(find.byKey(const ValueKey('detail-edit')), findsOneWidget);
    expect(find.byKey(const ValueKey('detail-delete')), findsOneWidget);
  });

  testWidgets('hides the salary when the permission exists but the payload has none',
      (tester) async {
    // The realistic case for an HR admin looking at a list projection that
    // deliberately carries no payroll: permission alone must not conjure a
    // number, and an absent figure is not a zero.
    await pumpDetail(
      tester,
      permissions: const ['employees.view', 'employees.salary.view'],
    );

    expect(find.byKey(const ValueKey('detail-salary')), findsNothing);
    expect(find.text('0.00'), findsNothing);
  });
}
