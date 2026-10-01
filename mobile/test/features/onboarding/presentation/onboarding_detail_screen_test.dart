import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/onboarding/domain/onboarding.dart';
import 'package:mobile/features/onboarding/presentation/onboarding_detail_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show tapIn;

/// The path table the detail screen navigates, and nothing else.
GoRouter onboardingRouter(String location) => GoRouter(
  initialLocation: location,
  routes: [
    GoRoute(
      path: '/onboarding/:id',
      builder: (_, state) => OnboardingDetailScreen(
        employeeId: int.parse(state.pathParameters['id']!),
      ),
    ),
    GoRoute(
      path: '/documents/new',
      builder: (_, _) => const Scaffold(body: Text('upload-form')),
    ),
  ],
);

const ApiException refused = ApiException(
  statusCode: 403,
  message: 'This action is unauthorized.',
);

void main() {
  late ScriptedOnboarding script;

  setUp(() {
    script = ScriptedOnboarding.rows([
      onboardingRow(
        employeeId: 5,
        name: 'Meera Nair',
        code: 'EMP-0005',
        status: Onboarding.statusPendingDocuments,
        total: 5,
        satisfied: 3,
        missing: const <String>['visa', 'bank_information'],
        canComplete: false,
        requirements: <Map<String, dynamic>>[
          checklistItem(
            code: 'personal_information',
            name: 'Personal information',
            kind: OnboardingChecklistItem.kindData,
            state: OnboardingChecklistItem.stateSatisfied,
            sortOrder: 10,
          ),
          checklistItem(
            code: 'passport',
            name: 'Passport',
            state: OnboardingChecklistItem.stateMissing,
            sortOrder: 20,
          ),
          checklistItem(
            code: 'emirates_id',
            name: 'Emirates ID',
            state: OnboardingChecklistItem.stateRejected,
            sortOrder: 30,
            document: documentRow(
              id: 7,
              typeCode: 'EMIRATES_ID',
              typeName: 'Emirates ID',
              status: 'rejected',
              rejectionReason: 'The back of the card was cut off.',
            ),
          ),
          checklistItem(
            code: 'visa',
            name: 'Visa',
            state: OnboardingChecklistItem.stateExpired,
            sortOrder: 40,
            document: documentRow(
              id: 8,
              typeCode: 'VISA',
              typeName: 'Visa',
              status: 'expired',
              expiry: '2026-08-01',
              expiryState: 'expired',
              daysUntil: -61,
            ),
          ),
          checklistItem(
            code: 'employment_contract',
            name: 'Employment contract',
            state: OnboardingChecklistItem.statePendingVerification,
            sortOrder: 50,
          ),
        ],
      ),
      onboardingRow(
        employeeId: 6,
        name: 'Ravi Das',
        code: 'EMP-0006',
        status: Onboarding.statusHrReview,
        total: 1,
        satisfied: 1,
        canComplete: true,
        requirements: <Map<String, dynamic>>[
          checklistItem(
            code: 'passport',
            name: 'Passport',
            state: OnboardingChecklistItem.stateSatisfied,
          ),
        ],
      ),
    ]);
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 5,
    List<String> permissions = const ['onboarding.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        onboarding: script,
        child: MaterialApp.router(
          routerConfig: onboardingRouter('/onboarding/$id'),
        ),
      ),
    );
    await advance(tester);
  }

  /// The chip and the dropdown both print the stage, so an assertion about
  /// what the *record* says is scoped to the card that describes it — a bare
  /// `find.text` would be counting the control as well as the answer.
  Finder inCard(String text) =>
      find.descendant(of: find.byType(Card), matching: find.text(text));

  bool canPress(WidgetTester tester, Key key) =>
      tester.widget<FilledButton>(find.byKey(key)).onPressed != null;

  testWidgets('a session without onboarding.view is never shown a person', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('the checklist keeps five states as five different answers', (
    tester,
  ) async {
    await pumpDetail(tester);

    Future<void> state(String code, String expected) async {
      expect(
        find.descendant(
          of: find.byKey(ValueKey('requirement-$code')),
          matching: find.text(expected),
        ),
        findsOneWidget,
        reason: '$code should read "$expected"',
      );
    }

    await state('personal_information', 'Met');
    await state('passport', 'Missing');
    await state('emirates_id', 'Rejected');
    await state('visa', 'Expired');
    await state('employment_contract', 'Awaiting verification');

    expect(find.byKey(const ValueKey('onboarding-missing')), findsOneWidget);
    expect(find.text('Outstanding: visa, bank_information'), findsOneWidget);
  });

  testWidgets('a rejected requirement says why, from the document', (
    tester,
  ) async {
    await pumpDetail(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('requirement-emirates_id')),
        matching: find.text('The back of the card was cut off.'),
      ),
      findsOneWidget,
    );

    // And an expired one borrows the document's date words rather than
    // repeating a bare chip: "Expired" alone is a state nobody can act on.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('requirement-visa')),
        matching: find.text('Expired 61 days ago'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('moving the stage is onboarding.manage, with no '
      'self-service exception', (tester) async {
    await pumpDetail(tester, permissions: const ['onboarding.view']);

    expect(find.byKey(const ValueKey('onboarding-status')), findsNothing);
    expect(find.byKey(const ValueKey('save-onboarding-notes')), findsNothing);
    expect(find.byKey(const ValueKey('complete-onboarding')), findsNothing);

    await pumpDetail(
      tester,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    expect(find.byKey(const ValueKey('onboarding-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('save-onboarding-notes')), findsOneWidget);
    expect(find.byKey(const ValueKey('complete-onboarding')), findsOneWidget);
  });

  testWidgets('the stage travels as a status and comes back changed', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    expect(inCard('Waiting on documents'), findsOneWidget);

    await tapIn(tester, find.byKey(const ValueKey('onboarding-status')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'HR review'),
      warnIfMissed: false,
    );
    await advance(tester);

    expect(script.updateCalls, 1);
    expect(script.lastId, 5);
    expect(script.lastBody!['status'], 'hr_review');
    expect(inCard('HR review'), findsOneWidget);
    expect(inCard('Waiting on documents'), findsNothing);
  });

  testWidgets('completion is refused with the sentence the server sent', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 6,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    script.completeError = ApiException(
      statusCode: 409,
      message: script.incompleteMessage,
    );

    await tapIn(tester, find.byKey(const ValueKey('complete-onboarding')));
    await advance(tester);

    expect(script.completeCalls, 1);
    expect(find.byKey(const ValueKey('onboarding-banner')), findsOneWidget);
    expect(find.text(script.incompleteMessage), findsOneWidget);
    // The record did not move: a refusal the server makes is a message, not
    // a change of state the screen should paint on its own.
    expect(inCard('Completed'), findsNothing);
  });

  testWidgets('completion is offered only when the record is ready', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 5,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    expect(find.byKey(const ValueKey('complete-onboarding')), findsOneWidget);
    expect(
      canPress(tester, const ValueKey('complete-onboarding')),
      isFalse,
      reason: 'a record short of requirements must not offer completion',
    );
    expect(
      find.byKey(const ValueKey('onboarding-cannot-complete')),
      findsOneWidget,
    );

    await pumpDetail(
      tester,
      id: 6,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    expect(canPress(tester, const ValueKey('complete-onboarding')), isTrue);

    await tapIn(tester, find.byKey(const ValueKey('complete-onboarding')));
    await advance(tester);

    expect(script.completeCalls, 1);
    expect(script.lastCompleteId, 6);
    expect(inCard('Completed'), findsOneWidget);
  });

  testWidgets('a checklist line hands over its requirement to the upload '
      'form, by code', (tester) async {
    await pumpDetail(
      tester,
      permissions: const <String>[
        'onboarding.view',
        'onboarding.manage',
        'documents.create',
      ],
    );

    expect(find.byKey(const ValueKey('attach-passport')), findsOneWidget);
    // Something already met has nothing left to attach.
    expect(
      find.byKey(const ValueKey('attach-personal_information')),
      findsNothing,
    );

    await tapIn(tester, find.byKey(const ValueKey('attach-passport')));
    await tester.pumpAndSettle();

    expect(find.text('upload-form'), findsOneWidget);
  });

  testWidgets('the attach door belongs to documents.create, not to '
      'reviewing', (tester) async {
    await pumpDetail(
      tester,
      permissions: const ['onboarding.view', 'onboarding.manage'],
    );

    expect(find.byKey(const ValueKey('attach-passport')), findsNothing);
  });

  testWidgets('a 403 on a row is a refusal, not a missing person', (
    tester,
  ) async {
    script.findError = refused;
    await pumpDetail(tester);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(find.text(refused.message), findsNothing);
  });

  testWidgets('a 401 is neither: the record simply could not be loaded', (
    tester,
  ) async {
    script.findError = const ApiException(
      statusCode: 401,
      message: 'Unauthenticated.',
    );
    await pumpDetail(tester);

    expect(find.byKey(const ValueKey('onboarding-not-found')), findsOneWidget);
    expect(find.text('Unauthenticated.'), findsOneWidget);
  });
}
