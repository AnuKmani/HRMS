import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/employee_training.dart';
import 'package:mobile/features/training/presentation/training_list_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining.rows([
      employeeTrainingRow(
        id: 1,
        employeeName: 'Anu Kmani',
        programId: 1,
        status: EmployeeTraining.statusCompleted,
        completionDate: '2026-09-12',
        hasCertificate: true,
        certificateNumber: 'WAH-2026-118',
        certificateIssueDate: '2026-09-12',
        certificateExpiryDate: '2027-09-12',
        expiryState: EmployeeTraining.expiryValid,
        daysUntilExpiry: 346,
        isEditable: false,
      ),
      employeeTrainingRow(
        id: 2,
        employeeName: 'Meera Nair',
        programId: 1,
        program: trainingProgramRow(
          id: 1,
          code: 'WAH-101',
          name: 'Working at heights',
        ),
        status: EmployeeTraining.statusScheduled,
        hasCertificate: false,
        expiryState: EmployeeTraining.expiryNone,
      ),
      employeeTrainingRow(
        id: 3,
        employeeName: 'Anu Kmani',
        programId: 2,
        program: trainingProgramRow(
          id: 2,
          code: 'IND-001',
          name: 'Safety induction',
          typeId: 2,
          typeCode: 'SAFETY_INDUCTION',
          typeName: 'Safety induction',
        ),
        enrollmentDate: '2026-08-01',
        status: EmployeeTraining.statusCompleted,
        hasCertificate: true,
        certificateExpiryDate: '2026-10-06',
        expiryState: EmployeeTraining.expirySoon,
        daysUntilExpiry: 5,
        isEditable: false,
      ),
    ]);
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['training.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: const MaterialApp(home: TrainingListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without training.view is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('enrol-training')), findsNothing);
  });

  testWidgets('a row says whose it is, which course, and how far the card has '
      'left', (tester) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    // Three facts per row, and the expiry one is a sentence rather than a
    // colour — scope item X is about this exact string.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-1')),
        matching: find.text('Working at heights'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-1')),
        matching: find.text('Anu Kmani · WAH-101 · Enrolled 2026-09-01'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-1')),
        matching: find.text('Expires in 346 days'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-3')),
        matching: find.text('Expires in 5 days'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a course nobody has sat yet draws no expiry at all', (
    tester,
  ) async {
    await pumpList(tester);

    // The certificate state only appears when there is a certificate to
    // have one: "No expiry date" on an enrolment nobody has completed would
    // read as a missing field rather than as "not yet".
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-2')),
        matching: find.text('No expiry date'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('training-row-2')),
        matching: find.text('Scheduled'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the enrol door belongs to training.assign, not to viewing', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['training.view']);
    expect(find.byKey(const ValueKey('enrol-training')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['training.view', 'training.assign'],
    );
    expect(find.byKey(const ValueKey('enrol-training')), findsOneWidget);
  });

  testWidgets('the everybody\'s-expiry door belongs to training.expiry.view', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['training.view']);
    expect(find.byKey(const ValueKey('training-expiring-door')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['training.view', 'training.expiry.view'],
    );
    expect(
      find.byKey(const ValueKey('training-expiring-door')),
      findsOneWidget,
    );
  });

  testWidgets('the catalogue and the report are readable by training.view '
      'alone', (tester) async {
    // Both answer about the same people as the list below them, so neither
    // needs a permission of its own — only the cross-employee expiry view
    // does, because it reaches past the rows this reader may already see.
    await pumpList(tester, permissions: const ['training.view']);

    expect(
      find.byKey(const ValueKey('training-programs-door')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('training-compliance-door')),
      findsOneWidget,
    );
  });

  testWidgets('the status filter travels as a query parameter', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery!['status'], isNull);

    await tapIn(tester, find.byKey(const ValueKey('training-status-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Completed'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'completed');
  });

  testWidgets('the certificate selector becomes the three booleans the API '
      'reads', (tester) async {
    await pumpList(tester);

    await tapIn(
      tester,
      find.byKey(const ValueKey('training-certificate-filter')),
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Has a certificate'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    // One key in `state.query` so the dropdown has a value to draw when it
    // comes back; one on the wire because that is what the endpoint takes.
    expect(script.lastQuery!['certified'], '1');
    expect(script.lastQuery!.containsKey('certificate'), isFalse);

    await tapIn(
      tester,
      find.byKey(const ValueKey('training-certificate-filter')),
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Expiring soon'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['expiring_soon'], '1');
    expect(script.lastQuery!.containsKey('certified'), isFalse);
    expect(script.lastQuery!.containsKey('certificate'), isFalse);
  });

  testWidgets('an empty answer says what would appear here', (tester) async {
    script = ScriptedTraining();
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No training records yet.'), findsOneWidget);
  });

  testWidgets('a refusal — 401 or 403 — is a message, not a blank page', (
    tester,
  ) async {
    script.listError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
  });

  testWidgets('while the first page is in flight there is a spinner', (
    tester,
  ) async {
    final held = Completer<void>();
    script.holdList = held;

    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);
    expect(find.byKey(const ValueKey('list-empty')), findsNothing);

    held.complete();
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    script.holdList = null;
  });
}
