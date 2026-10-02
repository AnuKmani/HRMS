import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/training_program.dart';
import 'package:mobile/features/training/presentation/training_enroll_screen.dart';
import 'package:mobile/features/training/presentation/training_list_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn, type;

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining();
    script.typeRows = [
      trainingTypeRow(
        id: 1,
        code: 'WORKING_AT_HEIGHTS',
        name: 'Working at heights',
      ),
    ];
    script.programItems = [
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 1,
          code: 'WAH-101',
          name: 'Working at heights',
          certificateRequired: true,
          certificateValidityDays: 365,
        ),
      ),
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 2,
          code: 'FIRE-201',
          name: 'Fire warden',
          typeId: 3,
          typeCode: 'FIRE_SAFETY',
          typeName: 'Fire safety',
          certificateRequired: false,
          certificateValidityDays: null,
        ),
      ),
    ];
  });

  Future<void> pumpEnrol(
    WidgetTester tester, {
    List<String> permissions = const ['training.assign'],
  }) async {
    useTallScreen(tester);

    // A real router rather than `MaterialApp(home: …)`: saving calls
    // `context.go('/training')`, and a test without one would be asserting
    // on the exception that navigation threw instead of on the seat it
    // booked.
    final router = phase11Router('/training/new', [
      GoRoute(
        path: '/training/new',
        builder: (_, _) => const TrainingEnrollScreen(),
      ),
      GoRoute(path: '/training', builder: (_, _) => const TrainingListScreen()),
    ]);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await advance(tester);
  }

  /// Opens a picker and chooses one option — through the dialog, because
  /// that is the only route a person has.
  Future<void> pick(
    WidgetTester tester, {
    required Key field,
    required String label,
  }) async {
    await tapIn(tester, find.byKey(field));
    await tester.pumpAndSettle();

    await tapIn(tester, find.widgetWithText(ListTile, label));
    await tester.pumpAndSettle();
  }

  testWidgets('a session without training.assign is refused at the door', (
    tester,
  ) async {
    await pumpEnrol(tester, permissions: const ['training.view']);

    expect(
      find.text('You do not have permission to view training enrolment.'),
      findsOneWidget,
    );
    expect(script.enrollCalls, 0);
    expect(script.listCalls, 0);
  });

  testWidgets('a seat needs a person and a course before it is sent', (
    tester,
  ) async {
    await pumpEnrol(tester);

    await tapIn(tester, find.byKey(const ValueKey('save-enrolment')));
    await tester.pumpAndSettle();

    expect(script.enrollCalls, 0);
    expect(find.text('Choose who this is for.'), findsOneWidget);
    expect(find.text('Choose a course.'), findsOneWidget);
    // The date is filled in by the form, so it is not one of the three.
    expect(find.text('Choose the date of the seat.'), findsNothing);
    expect(find.byKey(const ValueKey('enrol-form-banner')), findsNothing);
  });

  testWidgets('a seat is sent with its person, its course and its date', (
    tester,
  ) async {
    await pumpEnrol(tester);

    await pick(
      tester,
      field: const ValueKey('enrol-employee'),
      label: 'Anu Kmani',
    );
    await pick(
      tester,
      field: const ValueKey('enrol-program'),
      label: 'Working at heights (WAH-101)',
    );

    await type(tester, const ValueKey('enrol-trainer'), 'Gulf Safety');
    await type(tester, const ValueKey('enrol-remarks'), 'Cohort of four.');

    await tapIn(tester, find.byKey(const ValueKey('save-enrolment')));
    await advance(tester);

    expect(script.enrollCalls, 1);
    expect(script.lastEnrollId, isNotNull);

    final body = script.lastEnrollBody!;
    expect(body['employee_id'], 7);
    expect(body['training_program_id'], 1);
    expect(body['enrollment_date'], isNotEmpty);
    expect(body['trainer'], 'Gulf Safety');
    expect(body['remarks'], 'Cohort of four.');
    // Left empty means left out, not sent as a null: a course date nobody
    // knows is not a date.
    expect(body.containsKey('training_date'), isFalse);

    // And it goes back to the directory rather than leaving the form open.
    expect(find.byType(TrainingListScreen), findsOneWidget);
  });

  testWidgets('a duplicate seat comes back as one sentence about the state '
      'the row is already in', (tester) async {
    script.enrollError = const ApiException(
      statusCode: 409,
      message: 'That person is already enrolled on this course for that date.',
    );

    await pumpEnrol(tester);

    await pick(
      tester,
      field: const ValueKey('enrol-employee'),
      label: 'Anu Kmani',
    );
    await pick(
      tester,
      field: const ValueKey('enrol-program'),
      label: 'Working at heights (WAH-101)',
    );

    await tapIn(tester, find.byKey(const ValueKey('save-enrolment')));
    await tester.pumpAndSettle();

    // On the banner rather than under a field: a 409 carries no field
    // errors, and putting it under one would be inventing a claim about
    // which box was wrong.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('enrol-form-banner')),
        matching: find.text(
          'That person is already enrolled on this course for that date.',
        ),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('enrol-employee')), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('save-enrolment')))
          .onPressed,
      isNotNull,
    );
  });

  testWidgets('the course picker asks for the active half of the catalogue', (
    tester,
  ) async {
    // A retired course is refused by the API too, so the picker declines to
    // offer a door that would only come back as a 422 — and it asks for the
    // whole catalogue rather than nine rows of twelve.
    await pumpEnrol(tester);

    await pick(
      tester,
      field: const ValueKey('enrol-program'),
      label: 'Working at heights (WAH-101)',
    );

    expect(script.lastProgramQuery, isNotNull);
    expect(script.lastProgramQuery!['status'], 'active');
    expect(script.lastProgramQuery!['per_page'], 100);
  });
}
