import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/training_program.dart';
import 'package:mobile/features/training/presentation/training_program_form_screen.dart';
import 'package:mobile/features/training/presentation/training_programs_screen.dart';

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
      trainingTypeRow(
        id: 2,
        code: 'SAFETY_INDUCTION',
        name: 'Safety induction',
      ),
    ];
    script.programItems = [
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 5,
          code: 'FIRE-201',
          name: 'Fire warden',
          typeId: 2,
          typeCode: 'SAFETY_INDUCTION',
          typeName: 'Safety induction',
          provider: 'In-house',
          durationDays: 1,
          certificateRequired: true,
          certificateValidityDays: 30,
          status: TrainingProgram.statusActive,
        ),
      ),
    ];
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    int? programId,
    List<String> permissions = const ['training.create', 'training.view'],
  }) async {
    useTallScreen(tester);

    // A real router rather than `MaterialApp(home: …)`: saving calls
    // `context.go` on the catalogue, and a test without one would be
    // asserting on the exception that navigation threw instead of on the
    // body it sent.
    final router = phase11Router(
      programId == null
          ? '/training/programs/new'
          : '/training/programs/$programId',
      [
        GoRoute(
          path: '/training/programs/new',
          builder: (_, _) => const TrainingProgramFormScreen(),
        ),
        GoRoute(
          path: '/training/programs/:id',
          builder: (_, state) => TrainingProgramFormScreen(
            programId: int.parse(state.pathParameters['id']!),
          ),
        ),
        GoRoute(
          path: '/training/programs',
          builder: (_, _) => const TrainingProgramsScreen(),
        ),
      ],
    );

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await advance(tester);
  }

  /// Fills a type through the picker — through the dialog rather than by
  /// setting `_typeId`, because the picker is the only route a person has.
  Future<void> chooseType(WidgetTester tester) async {
    await tapIn(tester, find.byKey(const ValueKey('program-type')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.widgetWithText(ListTile, 'Working at heights'));
    await tester.pumpAndSettle();
  }

  testWidgets('a session without training.create is refused at the door', (
    tester,
  ) async {
    await pumpForm(tester, permissions: const ['training.view']);

    expect(
      find.text('You do not have permission to view course creation.'),
      findsOneWidget,
    );
    expect(script.typesCalls, 0);
  });

  testWidgets('a session without training.update is refused at the door when '
      'correcting', (tester) async {
    await pumpForm(tester, programId: 5, permissions: const ['training.view']);

    expect(
      find.text('You do not have permission to view course editing.'),
      findsOneWidget,
    );
    expect(script.findCalls, 0);
  });

  testWidgets('an empty form is refused here, not by a request that was '
      'always going to fail', (tester) async {
    await pumpForm(tester);

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await tester.pumpAndSettle();

    expect(script.createCalls, 0);
    expect(find.text('Choose a training type.'), findsOneWidget);
    expect(find.text('Enter a course code.'), findsOneWidget);
    expect(find.text('Enter a course name.'), findsOneWidget);
    expect(find.byKey(const ValueKey('program-form-banner')), findsNothing);
  });

  testWidgets('touching a field drops only its own error', (tester) async {
    await pumpForm(tester);

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await tester.pumpAndSettle();
    expect(find.text('Enter a course name.'), findsOneWidget);

    await type(tester, const ValueKey('program-name'), 'Fire warden');
    await tester.pump();

    expect(find.text('Enter a course name.'), findsNothing);
    // The other two stay: one field was corrected, not the form.
    expect(find.text('Enter a course code.'), findsOneWidget);
    expect(find.text('Choose a training type.'), findsOneWidget);
  });

  testWidgets('adding a course sends the type, the code and the promise, and '
      'no status', (tester) async {
    await pumpForm(tester);

    await chooseType(tester);
    await type(tester, const ValueKey('program-code'), 'WAH-102');
    await type(tester, const ValueKey('program-name'), 'Heights refresher');
    await type(tester, const ValueKey('program-duration'), '2');
    await type(tester, const ValueKey('program-validity'), '365');

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await advance(tester);

    expect(script.createProgramCalls, 1);

    final body = script.lastProgramBody!;
    expect(body['training_type_id'], 1);
    expect(body['code'], 'WAH-102');
    expect(body['name'], 'Heights refresher');
    expect(body['duration_days'], '2');
    expect(body['certificate_required'], true);
    expect(body['certificate_validity_days'], '365');
    // A new course is `active` because the API says so: offering a "status"
    // on something that does not exist yet would be inviting a wrong default.
    expect(body.containsKey('status'), isFalse);

    // And it goes back to the catalogue rather than leaving the form open.
    expect(find.byType(TrainingProgramsScreen), findsOneWidget);
  });

  testWidgets('turning the certificate off sends no validity, even when one '
      'was typed', (tester) async {
    await pumpForm(tester);

    await chooseType(tester);
    await type(tester, const ValueKey('program-code'), 'SAFETY-001');
    await type(tester, const ValueKey('program-name'), 'Toolbox talk');
    await type(tester, const ValueKey('program-validity'), '365');

    // The field disappears with the switch — and the typed value goes with
    // it, because a validity on a course that issues no certificate is a
    // number about something that will never exist.
    await tester.tap(
      find.byKey(const ValueKey('program-certificate-required')),
    );
    await tester.pump();
    expect(find.byKey(const ValueKey('program-validity')), findsNothing);

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await advance(tester);

    expect(script.createProgramCalls, 1);
    expect(script.lastProgramBody!['certificate_required'], false);
    expect(script.lastProgramBody!['certificate_validity_days'], isNull);
  });

  testWidgets('editing loads the row and sends the status beside it', (
    tester,
  ) async {
    await pumpForm(
      tester,
      programId: 5,
      permissions: const ['training.update', 'training.view'],
    );

    expect(find.text('Edit course'), findsOneWidget);
    expect(find.text('FIRE-201'), findsOneWidget);
    expect(find.text('Fire warden'), findsOneWidget);
    expect(find.text('30'), findsOneWidget);
    expect(find.text('1'), findsWidgets);
    expect(find.byKey(const ValueKey('program-status')), findsOneWidget);

    // The row came from the catalogue's own page, falling back to a fetch
    // only when that page does not hold it.
    expect(script.lastProgramId, 5);
    expect(script.programsCalls, greaterThan(0));

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await advance(tester);

    expect(script.updateProgramCalls, 1);
    expect(script.lastId, 5);
    expect(script.lastProgramBody!.containsKey('status'), isTrue);
    expect(script.lastProgramBody!['status'], 'active');
    expect(script.lastProgramBody!['code'], 'FIRE-201');
  });

  testWidgets('a 422 lands under the field it describes', (tester) async {
    script.programError = const ApiException(
      statusCode: 422,
      message: 'The course code has already been taken.',
      errors: <String, String>{'code': 'That code is already in use.'},
    );

    await pumpForm(tester);

    await chooseType(tester);
    await type(tester, const ValueKey('program-code'), 'WAH-101');
    await type(tester, const ValueKey('program-name'), 'Heights');

    await tapIn(tester, find.byKey(const ValueKey('save-program')));
    await tester.pumpAndSettle();

    // Under the field rather than on the banner: the server named a field,
    // so a banner would be throwing away half of what it said.
    expect(script.createProgramCalls, 1);
    expect(find.text('That code is already in use.'), findsOneWidget);
    expect(find.byKey(const ValueKey('program-form-banner')), findsNothing);

    // And the form is live again, so the correction can be typed.
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('save-program')))
          .onPressed,
      isNotNull,
    );
  });
}
