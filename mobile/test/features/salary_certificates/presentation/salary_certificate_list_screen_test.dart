import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/salary_certificates/domain/salary_certificate.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_list_screen.dart';

import '../../../support/phase8.dart';

void main() {
  late ScriptedCertificates script;

  setUp(() {
    script = ScriptedCertificates(
      idOf: (item) => item.id,
      items: [
        certificateRow(id: 1, status: SalaryCertificateRequest.statusPending),
        certificateRow(
          id: 2,
          name: 'Meera Nair',
          status: SalaryCertificateRequest.statusApproved,
          approvedAt: '2026-09-28T11:00:00Z',
        ),
        certificateRow(
          id: 3,
          name: 'Ravi Kumar',
          status: SalaryCertificateRequest.statusGenerated,
          generatedAt: '2026-09-28T12:00:00Z',
        ),
        certificateRow(
          id: 4,
          name: 'Sara Iqbal',
          status: SalaryCertificateRequest.statusRejected,
          remarks: 'Not needed after all.',
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['salary_certificates.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        certificates: script,
        child: const MaterialApp(home: SalaryCertificateListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without the grant is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('new-certificate')), findsNothing);
  });

  testWidgets('a row leads with its reference and says whose it is', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('certificate-list')), findsOneWidget);
    expect(
      find.text('SAL-CERT-000001 · Housing loan with National Bank'),
      findsOneWidget,
    );
    expect(find.text('Anu Kmani · 2026-09-28'), findsOneWidget);

    expect(find.text('Awaiting approval'), findsOneWidget);
    expect(find.text('Approved'), findsOneWidget);
    expect(find.text('Issued'), findsOneWidget);
    expect(find.text('Rejected'), findsOneWidget);
  });

  testWidgets('asking is not a second grant beyond reading', (tester) async {
    await pumpList(tester, permissions: const ['salary_certificates.view']);

    // An employee cannot be given a permission to request a document about
    // their own salary and then refused for exercising it.
    expect(find.byKey(const ValueKey('new-certificate')), findsOneWidget);
  });

  testWidgets('the status filter is a query parameter', (tester) async {
    await pumpList(tester);

    expectQuery(script.lastQuery, 'status', null);

    await tester.tap(find.byKey(const ValueKey('status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Issued').last);
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'generated');

    await tester.tap(find.byKey(const ValueKey('status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All statuses'));
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'status', null);
  });
}
