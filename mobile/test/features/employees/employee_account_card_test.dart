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
  final List<String?> passwords = [];
  final List<String?> resets = [];

  @override
  Future<EmployeeAccount?> find(int employeeId) async => account;

  @override
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
    String? password,
  }) async {
    created.add(role);
    passwords.add(password);
    return account = EmployeeAccount(
      email: 'new.joiner@example.com',
      roles: [role],
      temporaryPassword: password == null || password.isEmpty
          ? 'Tmp9xY2kQ7wP'
          : password,
    );
  }

  @override
  Future<EmployeeAccount> resetPassword(
    int employeeId, {
    String? password,
  }) async {
    resets.add(password);
    return EmployeeAccount(
      email: account!.email,
      roles: account!.roles,
      temporaryPassword: password == null || password.isEmpty
          ? 'Gen3rated99'
          : password,
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

    // Blank: the server makes one up.
    expect(find.text('First password'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('account-password-go')));
    await tester.pumpAndSettle();

    expect(fake.created, ['Project Manager']);
    expect(fake.passwords, ['']);
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

  testWidgets('HR chooses the first password, e.g. 12345', (tester) async {
    final fake = await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
    );

    await tester.tap(find.byKey(const ValueKey('account-create')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('role-option-Employee')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('account-password')),
      '12345',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('account-password-go')));
    await tester.pumpAndSettle();

    expect(fake.passwords, ['12345']);
    expect(find.text('12345'), findsOneWidget);
  });

  testWidgets('HR resets a forgotten password', (tester) async {
    final fake = await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
      existing: const EmployeeAccount(
        email: 'new.joiner@example.com',
        roles: ['Employee'],
      ),
    );

    await tester.tap(find.byKey(const ValueKey('account-reset-password')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('account-password')),
      'temp99',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('account-password-go')));
    await tester.pumpAndSettle();

    expect(fake.resets, ['temp99']);
    expect(find.text('Password reset'), findsOneWidget);
    expect(find.text('temp99'), findsOneWidget);
  });

  testWidgets('HR sees the username and the temporary password, hidden '
      'until asked', (tester) async {
    await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
      existing: const EmployeeAccount(
        email: 'new.joiner@example.com',
        roles: ['Employee'],
        temporaryPassword: '12345',
        mustChangePassword: true,
      ),
    );

    expect(find.text('new.joiner@example.com'), findsOneWidget);
    expect(find.text('12345'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('account-password-toggle')));
    await tester.pump();
    expect(find.text('12345'), findsOneWidget);
  });

  testWidgets('once the employee changed it, nobody can read it', (
    tester,
  ) async {
    await pumpCard(
      tester,
      permissions: const ['users.view', 'users.manage'],
      existing: const EmployeeAccount(
        email: 'new.joiner@example.com',
        roles: ['Employee'],
      ),
    );

    expect(find.byKey(const ValueKey('account-password-own')), findsOneWidget);
    expect(find.byKey(const ValueKey('account-password-toggle')), findsNothing);
    expect(
      find.byKey(const ValueKey('account-reset-password')),
      findsOneWidget,
    );
  });
}
