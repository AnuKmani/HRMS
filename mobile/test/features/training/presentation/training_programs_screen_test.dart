import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/training_program.dart';
import 'package:mobile/features/training/presentation/training_programs_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining();
    script.programItems = [
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 1,
          code: 'WAH-101',
          name: 'Working at heights',
          provider: 'Gulf Safety Training',
          durationDays: 2,
          certificateRequired: true,
          certificateValidityDays: 365,
          status: TrainingProgram.statusActive,
        ),
      ),
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 2,
          code: 'IND-001',
          name: 'Safety induction',
          typeId: 2,
          typeCode: 'SAFETY_INDUCTION',
          typeName: 'Safety induction',
          provider: null,
          durationDays: null,
          certificateRequired: false,
          certificateValidityDays: null,
          status: TrainingProgram.statusRetired,
        ),
      ),
      TrainingProgram.fromJson(
        trainingProgramRow(
          id: 3,
          code: 'FIRE-201',
          name: 'Fire warden',
          typeId: 3,
          typeCode: 'FIRE_SAFETY',
          typeName: 'Fire safety',
          provider: 'In-house',
          durationDays: 1,
          certificateRequired: true,
          certificateValidityDays: null,
          status: TrainingProgram.statusActive,
        ),
      ),
    ];
  });

  Future<void> pumpCatalogue(
    WidgetTester tester, {
    List<String> permissions = const ['training.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: const MaterialApp(home: TrainingProgramsScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without training.view is never asked for the '
      'catalogue', (tester) async {
    await pumpCatalogue(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.programsCalls, 0);
    expect(find.byKey(const ValueKey('new-program')), findsNothing);
  });

  testWidgets('a row says the code, the kind, the provider and the length', (
    tester,
  ) async {
    await pumpCatalogue(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-1')),
        matching: find.text(
          'WAH-101 · Working at heights · Gulf Safety Training · 2 days',
        ),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-1')),
        matching: find.text('Active'),
      ),
      findsOneWidget,
    );
    // The promise, spelled out — a blank here would read as "nobody filled
    // it in", which is a different claim from "365 days".
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-1')),
        matching: find.text('Certificate valid for 365 days'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a retired course is still in the catalogue, saying so', (
    tester,
  ) async {
    await pumpCatalogue(tester);

    expect(
      find.byKey(const ValueKey('program-row-2')),
      findsOneWidget,
      reason: 'a retired course still sits behind every cohort filed under it',
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-2')),
        matching: find.text('Retired'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-2')),
        matching: find.text('No certificate'),
      ),
      findsOneWidget,
    );
    // The subtitle is one line of four facts joined, so it is asserted as
    // one line — a fragment of it is not a widget anybody could read.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-2')),
        matching: find.text(
          'IND-001 · Safety induction · Duration not '
          'tracked',
        ),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a certificate with no validity says it never expires', (
    tester,
  ) async {
    await pumpCatalogue(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-3')),
        matching: find.text('Certificate never expires'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('program-row-3')),
        matching: find.text('FIRE-201 · Fire safety · In-house · 1 day'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('adding a course belongs to training.create', (tester) async {
    await pumpCatalogue(tester, permissions: const ['training.view']);
    expect(find.byKey(const ValueKey('new-program')), findsNothing);

    await pumpCatalogue(
      tester,
      permissions: const ['training.view', 'training.create'],
    );
    expect(find.byKey(const ValueKey('new-program')), findsOneWidget);
  });

  testWidgets('the status filter travels as a query parameter', (tester) async {
    await pumpCatalogue(tester);

    expect(script.lastProgramQuery, isNotNull);
    expect(script.lastProgramQuery!['status'], isNull);

    await tapIn(tester, find.byKey(const ValueKey('program-status-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Retired'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastProgramQuery!['status'], 'retired');
  });

  testWidgets('an empty catalogue says what would appear here', (tester) async {
    script.programItems = <TrainingProgram>[];
    await pumpCatalogue(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No courses yet.'), findsOneWidget);
    expect(
      find.text(
        'Add the courses this company runs and how long each card lasts.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refusal — 401 or 403 — is a message, not a blank page', (
    tester,
  ) async {
    script.programsError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpCatalogue(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
  });
}
