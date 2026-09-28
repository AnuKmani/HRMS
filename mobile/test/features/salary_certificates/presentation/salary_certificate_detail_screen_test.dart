import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/salary_certificates/domain/salary_certificate.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_detail_screen.dart';

import '../../../support/phase8.dart';

const ApiException alreadyDecided = ApiException(
  statusCode: 409,
  message: 'Only a request awaiting a decision can be approved.',
);

void main() {
  late ScriptedCertificates script;
  late RecordingPdfOpener opener;

  setUp(() {
    opener = RecordingPdfOpener();
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
      ],
    );
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const ['salary_certificates.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        certificates: script,
        pdfOpener: opener,
        child: MaterialApp(home: SalaryCertificateDetailScreen(requestId: id)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without the grant is never shown a request', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('what was asked, and the decision on it', (tester) async {
    await pumpDetail(tester, id: 1);

    expect(find.text('SAL-CERT-000001'), findsOneWidget);
    expect(find.byKey(const ValueKey('certificate-status')), findsOneWidget);
    expect(find.text('Awaiting approval'), findsOneWidget);
    expect(find.text('Anu Kmani'), findsOneWidget);
    expect(find.text('Housing loan with National Bank'), findsOneWidget);
  });

  testWidgets('an approved certificate is one button, not a search', (
    tester,
  ) async {
    await pumpDetail(tester, id: 2);

    expect(find.byKey(const ValueKey('issue-certificate')), findsOneWidget);
    expect(find.text('Issue document (PDF)'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('issue-certificate')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.pdfCalls, 1);
    expect(script.lastPdfId, 2);
    expect(opener.calls, 1);
    expect(opener.opened, ['salary-certificate-2.pdf']);
  });

  testWidgets('can_issue is the server’s whole answer, not a suggestion', (
    tester,
  ) async {
    // Same state — approved — but the grant was folded into the flag and
    // the server said no. The screen must not re-derive "would this work?"
    // from the status and offer a button it has not been told about.
    script.items
      ..clear()
      ..add(
        certificateRow(
          id: 4,
          status: SalaryCertificateRequest.statusApproved,
          canIssue: false,
        ),
      );

    await pumpDetail(
      tester,
      id: 4,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );

    expect(find.text('Approved'), findsOneWidget);
    expect(find.byKey(const ValueKey('issue-certificate')), findsNothing);
  });

  testWidgets('deciding belongs to salary_certificates.manage', (tester) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const ['salary_certificates.view'],
    );
    expect(find.byKey(const ValueKey('approve-certificate')), findsNothing);
    expect(find.byKey(const ValueKey('reject-certificate')), findsNothing);

    await pumpDetail(
      tester,
      id: 1,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );
    expect(find.byKey(const ValueKey('approve-certificate')), findsOneWidget);
    expect(find.byKey(const ValueKey('reject-certificate')), findsOneWidget);
  });

  testWidgets('approving moves the request through the repository', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );

    await tester.tap(find.byKey(const ValueKey('approve-certificate')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approve').last);
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.lastTransition, 'approve');
    expect(script.lastTransitionId, 1);
    expect(script.lastRemarks, isNull);
  });

  testWidgets('refusing is impossible without a reason', (tester) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );

    await tester.tap(find.byKey(const ValueKey('reject-certificate')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Refuse').last);
    await tester.pumpAndSettle();

    expect(script.lastTransition, isNull);
    expect(find.text('Say why before confirming.'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Remarks *'),
      'Not required by the bank after all.',
    );
    await tester.tap(find.text('Refuse').last);
    await advance(tester);

    expect(script.lastTransition, 'reject');
    expect(script.lastRemarks, 'Not required by the bank after all.');
  });

  testWidgets('a refusal from the server is shown as written', (tester) async {
    script.actionError = alreadyDecided;

    await pumpDetail(
      tester,
      id: 1,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );

    await tester.tap(find.byKey(const ValueKey('approve-certificate')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approve').last);
    await advance(tester);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('certificate-banner')), findsOneWidget);
    expect(
      find.text('Only a request awaiting a decision can be approved.'),
      findsOneWidget,
    );
  });

  testWidgets(
    'a pending request may be withdrawn without permission to decide it',
    (tester) async {
      await pumpDetail(tester, id: 1);

      expect(find.byKey(const ValueKey('cancel-certificate')), findsOneWidget);
      expect(find.byKey(const ValueKey('approve-certificate')), findsNothing);
    },
  );

  testWidgets('a decided request is read-only', (tester) async {
    await pumpDetail(
      tester,
      id: 3,
      permissions: const [
        'salary_certificates.view',
        'salary_certificates.manage',
      ],
    );

    // Two `Issued` on purpose: the status chip, and the label of the row
    // that says *when* it was issued.
    expect(find.text('Issued'), findsWidgets);
    expect(find.byKey(const ValueKey('certificate-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('cancel-certificate')), findsNothing);
    expect(find.byKey(const ValueKey('approve-certificate')), findsNothing);
  });
}
