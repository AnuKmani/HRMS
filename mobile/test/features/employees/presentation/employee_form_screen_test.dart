import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/employees/presentation/employee_form_screen.dart';

import '../../../support/phase4.dart';

GoRouter formRouter(String initialLocation) => GoRouter(
  initialLocation: initialLocation,
  routes: [
    GoRoute(path: '/', builder: (_, _) => const SizedBox()),
    GoRoute(path: '/employees', builder: (_, _) => const SizedBox()),
    GoRoute(
      path: '/employees/new',
      builder: (_, _) => const EmployeeFormScreen(),
    ),
    GoRoute(path: '/employees/:id', builder: (_, _) => const SizedBox()),
    GoRoute(
      path: '/employees/:id/edit',
      builder: (_, state) => EmployeeFormScreen(
        employeeId: int.parse(state.pathParameters['id']!),
      ),
    ),
  ],
);

const denied = ApiException(
  statusCode: 403,
  message: 'This action is unauthorized.',
);

/// A person with everything the form asks for already filled in, so a save
/// test is about the *request* rather than about re-typing eleven fields.
Employee loadedEmployee({double? salary, bool salaryVisible = false}) =>
    Employee(
      id: 12,
      employeeCode: 'EMP-1001',
      firstName: 'Asha',
      lastName: 'Nair',
      fullName: 'Asha Nair',
      email: 'asha@example.com',
      phone: '9999999999',
      joiningDate: '2024-03-01',
      departmentId: 3,
      departmentName: 'Human Resources',
      designationId: 5,
      designationName: 'Site Engineer',
      employmentType: 'permanent',
      employmentStatus: 'active',
      dateOfBirth: '1994-07-12',
      salary: salary,
      salaryVisible: salaryVisible,
    );

void main() {
  late ScriptedEmployees script;

  setUp(() {
    script = ScriptedEmployees(
      idOf: (item) => item.id,
      items: [loadedEmployee(salary: 150000.50, salaryVisible: true)],
      fallback: loadedEmployee(),
    );
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    required List<String> permissions,
    String at = '/employees/new',
  }) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: permissions,
        employees: script,
        departments: ScriptedDepartments(idOf: (item) => item.id),
        designations: ScriptedDesignations(idOf: (item) => item.id),
        projects: ScriptedProjects(idOf: (item) => item.id),
        sites: ScriptedSites(idOf: (item) => item.id),
        child: MaterialApp.router(routerConfig: formRouter(at)),
      ),
    );
    await advance(tester);
  }

  group('the salary field', () {
    testWidgets('is not drawn for a role that may not see payroll', (
      tester,
    ) async {
      await pumpForm(
        tester,
        permissions: const ['employees.view', 'employees.update'],
      );

      expect(find.text('Salary'), findsNothing);
      expect(find.text('Compensation'), findsNothing);
    });

    testWidgets('is drawn for a role that may', (tester) async {
      await pumpForm(
        tester,
        permissions: const ['employees.view', 'employees.salary.view'],
      );

      expect(find.text('Salary'), findsOneWidget);
      expect(find.text('Compensation'), findsOneWidget);
      expect(
        find.text('Visible only to roles allowed to see payroll.'),
        findsOneWidget,
      );
    });

    testWidgets('is not sent in the body when it is not on screen', (
      tester,
    ) async {
      await pumpForm(
        tester,
        permissions: const ['employees.update'],
        at: '/employees/12/edit',
      );

      expect(script.findCalls, 1);
      // Read the controller, not the text on screen: the employee-code hint is
      // the same sentence as its value, so a text finder counts twice.
      expect(
        tester.widget<TextField>(find.byType(TextField).at(0)).controller?.text,
        'EMP-1001',
      );

      await tester.tap(find.byKey(const ValueKey('form-save')));
      await advance(tester);

      expect(script.updateCalls, 1);
      expect(script.lastId, 12);
      // Not `salary: null`. A payload carrying the key at all is a 422 for
      // this caller, which would make an unrelated edit unsavable.
      expect(script.lastBody!.containsKey('salary'), isFalse);
      // Everything else still went across.
      expect(script.lastBody!['employee_code'], 'EMP-1001');
      expect(script.lastBody!['joining_date'], '2024-03-01');
      expect(script.lastBody!['department_id'], 3);
      expect(script.lastBody!['employment_status'], 'active');
    });

    testWidgets('is prefilled and sent when it may be seen', (tester) async {
      await pumpForm(
        tester,
        permissions: const ['employees.update', 'employees.salary.view'],
        at: '/employees/12/edit',
      );

      expect(find.text('150000.50'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('form-save')));
      await advance(tester);

      expect(script.updateCalls, 1);
      expect(script.lastBody!.containsKey('salary'), isTrue);
      expect(script.lastBody!['salary'], 150000.50);
    });
  });

  testWidgets('an untouched create form never reaches the server', (
    tester,
  ) async {
    await pumpForm(
      tester,
      permissions: const ['employees.create', 'employees.salary.view'],
    );

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(script.createCalls, 0);
    expect(find.text('Employee code is required.'), findsOneWidget);
    expect(find.text('First name is required.'), findsOneWidget);
    expect(find.text('Last name is required.'), findsOneWidget);
    expect(find.text('Email is required.'), findsOneWidget);
    expect(find.text('Joining date is required.'), findsOneWidget);
  });

  testWidgets('a 403 while editing says it plainly', (tester) async {
    await pumpForm(
      tester,
      permissions: const ['employees.update'],
      at: '/employees/12/edit',
    );

    script.saveError = denied;

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(find.byKey(const ValueKey('form-forbidden')), findsOneWidget);
    expect(find.text(denied.message), findsOneWidget);
    // Still on the form: the fix is a role change, not a correction.
    expect(find.byKey(const ValueKey('form-save')), findsOneWidget);
  });
}
