import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/employee_training.dart';
import 'package:mobile/features/training/presentation/training_expiry_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining();
    script.expiringRows = [
      EmployeeTraining.fromJson(
        employeeTrainingRow(
          id: 41,
          employeeName: 'Meera Nair',
          programId: 1,
          status: EmployeeTraining.statusCompleted,
          hasCertificate: true,
          certificateExpiryDate: '2026-10-06',
          expiryState: EmployeeTraining.expirySoon,
          daysUntilExpiry: 5,
          isEditable: false,
        ),
      ),
      EmployeeTraining.fromJson(
        employeeTrainingRow(
          id: 42,
          employeeName: 'Anu Kmani',
          programId: 2,
          program: trainingProgramRow(
            id: 2,
            code: 'IND-001',
            name: 'Safety induction',
            typeId: 2,
            typeCode: 'SAFETY_INDUCTION',
            typeName: 'Safety induction',
            certificateRequired: true,
            certificateValidityDays: 30,
          ),
          status: EmployeeTraining.statusCompleted,
          hasCertificate: true,
          certificateExpiryDate: '2026-09-19',
          expiryState: EmployeeTraining.expiryExpired,
          daysUntilExpiry: -12,
          isEditable: false,
        ),
      ),
    ];
  });

  Future<void> pumpReport(
    WidgetTester tester, {
    List<String> permissions = const ['training.expiry.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: const MaterialApp(home: TrainingExpiryScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without training.expiry.view is never asked for '
      'everybody', (tester) async {
    await pumpReport(tester, permissions: const ['training.view']);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.expiringCalls, 0);
    expect(find.byKey(const ValueKey('list-empty')), findsNothing);
  });

  testWidgets('a row names the course, its holder and the day it lapses', (
    tester,
  ) async {
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-41')),
        matching: find.text('Working at heights'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-41')),
        matching: find.text('Meera Nair · Expires 2026-10-06'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-41')),
        matching: find.text('Expires in 5 days'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an already-lapsed card says how long ago, in words', (
    tester,
  ) async {
    await pumpReport(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-42')),
        matching: find.text('Expired 12 days ago'),
      ),
      findsOneWidget,
    );
    // The chip is the stored status; the sentence is the arithmetic. Both,
    // because a lagging scheduler cannot be allowed to make this report
    // optimistic.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-42')),
        matching: find.text('Completed'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-42')),
        matching: find.text('Safety induction'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the window is a query parameter, and starts at ninety days', (
    tester,
  ) async {
    await pumpReport(tester);

    expect(script.lastExpiringQuery, isNotNull);
    expect(script.lastExpiringQuery!['within'], 90);

    await tapIn(tester, find.byKey(const ValueKey('expiry-window-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Already expired'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastExpiringQuery!['within'], '0');
  });

  testWidgets('an empty window says what would appear here', (tester) async {
    script.expiringRows = <EmployeeTraining>[];
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('Nothing is due to expire.'), findsOneWidget);
    expect(
      find.text(
        'Certificates inside the selected window appear here, earliest '
        'first.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refusal is a message, not a blank report', (tester) async {
    script.expiringError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
  });
}
