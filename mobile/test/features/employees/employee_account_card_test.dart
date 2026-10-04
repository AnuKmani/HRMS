import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/employees/presentation/employee_account_card.dart';

import '../../support/fakes.dart';

class FakeAccounts implements EmployeeAccountRepository {
  EmployeeAccount? account;
  final List<String> created = [];
  final List<String> changed = [];

  @override
  Future<EmployeeAccount?> find(int employeeId) async => account;

  @override
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
  }) async {
    created.add(role);
    return account = EmployeeAccount(
      email: 'new.joiner@example.com',
      roles: [role],
      temporaryPassword: 'Tmp9xY2kQ7wP',
    );
  }

  @override
  Future<EmployeeAccount> changeRole(int employeeId, String role) async {
    changed.add(role);
    return account = EmployeeAccount(email: account!.email, roles: [role]);
  }

  @override
  Future<List<String>> roles() async => const [
    'Employee',
    'Project Manager',
    'Site Supervisor',
  ];
}

Future<FakeAccounts> pumpCard(
  WidgetTester tester, {
  required List<String> permissions,
  EmployeeAccount? existing,
}) async {
  final fake = FakeAccounts()..account = existing;
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        permissionScopeProvider.overrideWithValue(
          PermissionScope(buildUser(permissions: permissions)),
        ),
        employeeAccountRepositoryProvider.overrideWithValue(fake),
      ],
      child: const MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: EmployeeAccountCard(
              employeeId: 3,
              employeeEmail: 'new.joiner@example.com',
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return fake;
}

void main() {
  testWidgets('HR creates a login as Project Manager and sees the password '
      'once', (tester) async {
    final fake = await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
    );

    expect(find.text('No app login yet'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('account-create')));
    await tester.pumpAndSettle();

    expect(
      find.text('Projects, sites (can add sites), site staff'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('role-option-Project Manager')));
    await tester.pumpAndSettle();

    expect(fake.created, ['Project Manager']);
    expect(find.text('Login created'), findsOneWidget);
    expect(find.text('Tmp9xY2kQ7wP'), findsOneWidget);

    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();

    expect(find.text('Project Manager'), findsOneWidget);
    expect(find.text('Tmp9xY2kQ7wP'), findsNothing);
  });

  testWidgets('the role can be changed later', (tester) async {
    final fake = await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
      existing: const EmployeeAccount(
        email: 'new.joiner@example.com',
        roles: ['Employee'],
      ),
    );

    await tester.tap(find.byKey(const ValueKey('account-change-role')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('role-option-Site Supervisor')));
    await tester.pumpAndSettle();

    expect(fake.changed, ['Site Supervisor']);
    expect(find.text('Site Supervisor'), findsOneWidget);
  });

  testWidgets('without users.manage there is nothing to press', (tester) async {
    await pumpCard(tester, permissions: const ['users.view']);

    expect(find.text('No app login yet'), findsOneWidget);
    expect(find.byKey(const ValueKey('account-create')), findsNothing);
  });
}
