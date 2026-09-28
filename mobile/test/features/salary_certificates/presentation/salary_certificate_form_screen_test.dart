import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_form_screen.dart';

import '../../../support/phase8.dart';
import '../../../support/site_reports.dart' show pickDate, tapIn;

GoRouter certificateFormRouter(String initialLocation) => GoRouter(
  initialLocation: initialLocation,
  routes: [
    GoRoute(path: '/salary-certificates', builder: (_, _) => const SizedBox()),
    GoRoute(
      path: '/salary-certificates/new',
      builder: (_, _) => const SalaryCertificateFormScreen(),
    ),
    GoRoute(
      path: '/salary-certificates/:id',
      builder: (_, _) => const SizedBox(),
    ),
  ],
);

const invalidPurpose = ApiException(
  statusCode: 422,
  message: 'The given data was invalid.',
  errors: {'purpose': 'Say who this is for.'},
);

void main() {
  late ScriptedCertificates script;

  setUp(() {
    script = ScriptedCertificates(
      idOf: (item) => item.id,
      items: const [],
      // `_save` answers with this when the pool is empty, which is how a
      // request that was never filed before can still hand the user the row
      // it just made.
      fallback: certificateRow(id: 99),
    );
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    List<String> permissions = const ['salary_certificates.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        certificates: script,
        child: MaterialApp.router(
          routerConfig: certificateFormRouter('/salary-certificates/new'),
        ),
      ),
    );
    await advance(tester);
  }

  testWidgets('reading and asking are the same grant', (tester) async {
    await pumpForm(tester, permissions: const ['salary_certificates.view']);

    expect(find.byKey(const ValueKey('certificate-submit')), findsOneWidget);
    expect(find.text('Employee'), findsNothing);
    expect(find.byKey(const ValueKey('certificate-employee')), findsNothing);
  });

  testWidgets('without the grant there is nothing to fill in', (tester) async {
    await pumpForm(tester, permissions: const []);

    expect(find.text('You may not ask for a certificate.'), findsOneWidget);
    expect(find.byKey(const ValueKey('certificate-submit')), findsNothing);
  });

  testWidgets('it sends a purpose and a date, and never an employee id', (
    tester,
  ) async {
    await pumpForm(tester);

    await tester.enterText(
      find.byKey(const ValueKey('certificate-purpose')),
      'Car loan with City Bank',
    );
    // Through the picker rather than the controller: the field is
    // `readOnly`, so the dialog is the only route a person has.
    await pickDate(tester, const ValueKey('certificate-date'));

    await tapIn(tester, find.byKey(const ValueKey('certificate-submit')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.createCalls, 1);

    final body = script.lastCreated!;
    expect(body.containsKey('employee_id'), isFalse);
    expect(body.containsKey('status'), isFalse);
    expect(body['purpose'], 'Car loan with City Bank');
    expect(body['request_date'], DateTime.now().toString().split(' ')[0]);

    expect(find.byKey(const ValueKey('certificate-submit')), findsNothing);
  });

  testWidgets('the date is optional — blank means today, not nothing', (
    tester,
  ) async {
    await pumpForm(tester);

    await tester.enterText(
      find.byKey(const ValueKey('certificate-purpose')),
      'Visa application',
    );

    await tapIn(tester, find.byKey(const ValueKey('certificate-submit')));
    await advance(tester);

    expect(script.createCalls, 1);
    expect(script.lastCreated!.containsKey('request_date'), isFalse);
  });

  testWidgets('a refusal about the purpose is drawn against the field', (
    tester,
  ) async {
    script.actionError = invalidPurpose;

    await pumpForm(tester);

    await tester.enterText(
      find.byKey(const ValueKey('certificate-purpose')),
      '',
    );
    await tapIn(tester, find.byKey(const ValueKey('certificate-submit')));
    await advance(tester);

    expect(find.text('Say who this is for.'), findsOneWidget);
    expect(find.byKey(const ValueKey('form-error')), findsNothing);
  });
}
