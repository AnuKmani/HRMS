import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/training/domain/employee_training.dart';
import 'package:mobile/features/training/presentation/training_compliance_screen.dart';

import '../../../support/phase11.dart';

void main() {
  late ScriptedTraining script;

  setUp(() {
    script = ScriptedTraining();
    script.complianceReply = trainingComplianceJson(
      programs: <Map<String, dynamic>>[
        {
          ...trainingProgramRow(id: 1),
          'enrollments_count': 4,
          'active_enrollments_count': 1,
        },
        {
          ...trainingProgramRow(
            id: 9,
            code: 'ZERO-001',
            name: 'Nobody has sat this yet',
            certificateRequired: false,
            certificateValidityDays: null,
          ),
          'enrollments_count': 0,
          'active_enrollments_count': 0,
        },
      ],
      byStatus: const <String, int>{
        EmployeeTraining.statusCompleted: 3,
        EmployeeTraining.statusCancelled: 1,
        EmployeeTraining.statusEnrolled: 3,
      },
      certificates: const <String, int>{
        EmployeeTraining.expiryValid: 2,
        EmployeeTraining.expirySoon: 2,
        EmployeeTraining.expiryExpired: 1,
        EmployeeTraining.expiryNone: 2,
      },
      enrollments: 7,
      certificatesTotal: 5,
    );
  });

  Future<void> pumpReport(
    WidgetTester tester, {
    List<String> permissions = const ['training.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        training: script,
        child: const MaterialApp(home: TrainingComplianceScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without training.view is never asked for totals', (
    tester,
  ) async {
    await pumpReport(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.complianceCalls, 0);
  });

  testWidgets('the four certificate buckets are sentences, not tints', (
    tester,
  ) async {
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('compliance-body')), findsOneWidget);
    expect(find.text('Valid'), findsOneWidget);
    // The window is named rather than implied: "Expiring" with no number
    // beside it would leave the reader guessing at the policy.
    expect(find.text('Expiring within 30 days'), findsOneWidget);
    expect(find.text('Expired'), findsOneWidget);
    expect(find.text('No expiry date'), findsOneWidget);

    // The counts sit inside the certificate card, and they are the ones
    // this payload sent — three `2`s (valid, expiring, none) and one `1`.
    expect(
      find.descendant(of: find.byType(Card).at(0), matching: find.text('2')),
      findsNWidgets(3),
    );
    expect(
      find.descendant(of: find.byType(Card).at(0), matching: find.text('1')),
      findsOneWidget,
    );
  });

  testWidgets('all seven enrolment states are drawn, including the empty '
      'ones', (tester) async {
    await pumpReport(tester);

    for (final label in const <String>[
      'Enrolled',
      'Scheduled',
      'In progress',
      'Completed',
      'Failed',
      'Cancelled',
      'Certificate expired',
    ]) {
      expect(find.text(label), findsOneWidget, reason: '$label is a row');
    }

    // The server's own totals, printed rather than summed in the browser.
    expect(find.text('Where the 7 rows currently stand'), findsOneWidget);
  });

  testWidgets('a course nobody has sat is still in the catalogue', (
    tester,
  ) async {
    await pumpReport(tester);

    expect(find.text('Nobody has sat this yet'), findsOneWidget);
    expect(find.text('ZERO-001 · 0 active of 0'), findsOneWidget);
    expect(find.text('WAH-101 · 1 active of 4'), findsOneWidget);
  });

  testWidgets('a total without a time is not shown as one', (tester) async {
    await pumpReport(tester);

    expect(find.text('As at 2026-10-01T06:20:00Z'), findsOneWidget);
  });

  testWidgets('a refusal is a message with a way back, not a blank screen', (
    tester,
  ) async {
    script.complianceError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('compliance-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
    expect(find.byKey(const ValueKey('compliance-retry')), findsOneWidget);

    // And the way back works.
    script.complianceReply = trainingComplianceJson();
    await tester.tap(find.byKey(const ValueKey('compliance-retry')));
    await advance(tester);

    expect(find.byKey(const ValueKey('compliance-body')), findsOneWidget);
    expect(script.complianceCalls, 2);
  });

  testWidgets('an unreachable server is a message, not an empty report', (
    tester,
  ) async {
    script.complianceError = const ApiException(
      statusCode: 0,
      message: 'Could not reach the server.',
    );
    await pumpReport(tester);

    expect(find.byKey(const ValueKey('compliance-error')), findsOneWidget);
    expect(find.text('Could not reach the server.'), findsOneWidget);
  });
}
