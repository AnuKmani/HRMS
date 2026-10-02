import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/training/domain/employee_training.dart';
import 'package:mobile/features/training/presentation/training_detail_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining.rows([
      employeeTrainingRow(
        id: 1,
        employeeName: 'Meera Nair',
        programId: 1,
        status: EmployeeTraining.statusCompleted,
        completionDate: '2026-09-12',
        hasCertificate: true,
        certificateNumber: 'WAH-2026-118',
        certificateIssueDate: '2026-09-12',
        certificateExpiryDate: '2026-10-06',
        expiryState: EmployeeTraining.expirySoon,
        daysUntilExpiry: 5,
        isEditable: false,
      ),
      employeeTrainingRow(
        id: 2,
        employeeName: 'Anu Kmani',
        programId: 3,
        program: trainingProgramRow(
          id: 3,
          code: 'FIRE-201',
          name: 'Fire warden',
          typeId: 3,
          typeCode: 'FIRE_SAFETY',
          typeName: 'Fire safety',
          certificateRequired: false,
          certificateValidityDays: null,
        ),
        status: EmployeeTraining.statusInProgress,
        hasCertificate: false,
        expiryState: EmployeeTraining.expiryNone,
      ),
      employeeTrainingRow(
        id: 3,
        employeeName: 'Anu Kmani',
        programId: 1,
        status: EmployeeTraining.statusEnrolled,
        hasCertificate: false,
        expiryState: EmployeeTraining.expiryNone,
      ),
    ]);
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const [
      'training.view',
      'training.complete',
      'training.update',
    ],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: MaterialApp(home: TrainingDetailScreen(trainingId: id)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without training.view is never asked for the row', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('a finished course shows both its status and its card state, '
      'in words', (tester) async {
    await pumpDetail(tester, id: 1);

    expect(find.byKey(const ValueKey('training-detail-body')), findsOneWidget);
    expect(
      find.text('Working at heights'),
      findsOneWidget,
      reason: 'the course name is the title',
    );

    // Two chips, two sources: the stored decision and the server's date
    // arithmetic, side by side rather than one standing in for the other.
    expect(find.text('Completed'), findsOneWidget);
    // Twice — once in the chip at the top and once beside the dates — but
    // the same sentence both times, so the tint is never the only signal.
    expect(find.text('Expires in 5 days'), findsWidgets);

    expect(find.text('Meera Nair'), findsOneWidget);
    expect(find.text('WAH-2026-118'), findsOneWidget);
    expect(find.text('2026-09-12'), findsWidgets);
    expect(find.text('2026-10-06'), findsOneWidget);
    expect(find.byKey(const ValueKey('download-certificate')), findsOneWidget);
  });

  testWidgets('a course nobody has finished draws no certificate section', (
    tester,
  ) async {
    await pumpDetail(tester, id: 2);

    expect(find.text('Certificate'), findsNothing);
    expect(find.byKey(const ValueKey('download-certificate')), findsNothing);
    expect(find.text('Fire warden'), findsOneWidget);
    expect(find.text('In progress'), findsOneWidget);
  });

  testWidgets('the record itself, not the reader, decides whether it can be '
      'completed', (tester) async {
    await pumpDetail(tester, id: 2);
    expect(find.byKey(const ValueKey('complete-training')), findsOneWidget);

    // A course already decided has no `is_completable` behind it, so the
    // door is not drawn at all rather than drawn and refused.
    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('complete-training')), findsNothing);
  });

  testWidgets('the completion door belongs to training.complete', (
    tester,
  ) async {
    await pumpDetail(tester, id: 2, permissions: const ['training.view']);
    expect(find.byKey(const ValueKey('complete-training')), findsNothing);

    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['training.view', 'training.complete'],
    );
    expect(find.byKey(const ValueKey('complete-training')), findsOneWidget);
  });

  testWidgets('cancelling belongs to training.update, and is drawn but dead '
      'without it', (tester) async {
    await pumpDetail(
      tester,
      id: 3,
      permissions: const ['training.view', 'training.complete'],
    );

    final button = tester.widget<OutlinedButton>(
      find.byKey(const ValueKey('cancel-training')),
    );
    expect(button.onPressed, isNull);

    await pumpDetail(
      tester,
      id: 3,
      permissions: const ['training.view', 'training.update'],
    );
    expect(
      tester
          .widget<OutlinedButton>(find.byKey(const ValueKey('cancel-training')))
          .onPressed,
      isNotNull,
    );
  });

  testWidgets('the cancel sheet sends the remarks it was given', (
    tester,
  ) async {
    await pumpDetail(tester, id: 3);

    await tapIn(tester, find.byKey(const ValueKey('cancel-training')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('cancel-remarks')),
      'Course was cancelled for the cohort.',
    );
    await tester.tap(find.byKey(const ValueKey('confirm-cancel')));
    await advance(tester);

    expect(script.cancelCalls, 1);
    expect(script.lastCancelId, 3);
    expect(
      script.lastCancelBody!['remarks'],
      'Course was cancelled for the cohort.',
    );

    // And the row moves, rather than the sheet just closing.
    expect(find.text('Cancelled'), findsOneWidget);
    expect(find.byKey(const ValueKey('cancel-training')), findsNothing);
  });

  testWidgets('a course that issues a card will not complete without one', (
    tester,
  ) async {
    // Enrolment 3 sits under a programme that promises a certificate, so
    // the form is not allowed to submit empty — and the refusal happens
    // before any request, because a 422 about a missing file would arrive
    // after the sheet had already popped.
    await pumpDetail(tester, id: 3);

    await tapIn(tester, find.byKey(const ValueKey('complete-training')));
    await tester.pumpAndSettle();

    expect(find.text('Attach certificate *'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('save-completion')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('completion-file-error')), findsOneWidget);
    expect(script.completeCalls, 0);
    expect(find.byKey(const ValueKey('save-completion')), findsOneWidget);
  });

  testWidgets('completing sends no expiry date, so the server dates the card '
      'from the programme', (tester) async {
    await pumpDetail(tester, id: 2);

    await tapIn(tester, find.byKey(const ValueKey('complete-training')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('save-completion')));
    await advance(tester);

    expect(script.completeCalls, 1);
    expect(script.lastCompleteId, 2);
    // Typed date beats the default; nothing typed means nothing sent, and
    // an empty string here would be an override to "expires today".
    expect(
      script.lastCompleteBody!.containsKey('certificate_expiry_date'),
      isFalse,
    );
    expect(script.lastCompleteFile, isNull);

    expect(find.text('Completed'), findsOneWidget);
    expect(find.byKey(const ValueKey('complete-training')), findsNothing);
  });

  testWidgets('a record that has moved is an error with a way back, not a '
      'blank screen', (tester) async {
    script.findError = notFound404;
    await pumpDetail(tester, id: 99);

    expect(find.byKey(const ValueKey('training-detail-error')), findsOneWidget);
    expect(find.byKey(const ValueKey('training-detail-retry')), findsOneWidget);
  });

  testWidgets('a record this session may not open says so, and offers no '
      'retry', (tester) async {
    script.findError = forbidden403;
    await pumpDetail(tester, id: 1);

    expect(find.text('You may not open this training record.'), findsOneWidget);
    expect(find.byKey(const ValueKey('training-detail-retry')), findsNothing);
  });
}
