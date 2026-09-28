import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/payroll/domain/payroll.dart';
import 'package:mobile/features/payroll/presentation/payroll_detail_screen.dart';

import '../../../support/phase8.dart';

const ApiException alreadyMoved = ApiException(
  statusCode: 409,
  message: 'Only a calculated row can be reviewed. This one is locked.',
);

void main() {
  late ScriptedPayroll script;
  late RecordingPdfOpener opener;

  setUp(() {
    opener = RecordingPdfOpener();
    script = ScriptedPayroll(
      idOf: (item) => item.id,
      items: [
        payrollRow(
          id: 1,
          status: Payroll.statusCalculated,
          items: [
            payrollItem(
              id: 10,
              code: 'BASIC',
              description: 'Basic salary',
              amount: '30000.00',
            ),
            payrollItem(
              id: 11,
              code: 'LOP',
              description: 'Loss of pay',
              type: PayrollItem.typeDeduction,
              quantity: '2.00',
              rate: '1000.00',
              amount: '2000.00',
            ),
          ],
        ),
        payrollRow(
          id: 2,
          name: 'Meera Nair',
          status: Payroll.statusProcessed,
          canRecalculate: false,
          canLock: true,
        ),
        payrollRow(
          id: 3,
          name: 'Ravi Kumar',
          status: Payroll.statusLocked,
          canRecalculate: false,
          canLock: false,
          lockedAt: '2026-09-30T18:00:00Z',
        ),
      ],
    );
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const ['payroll.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        payroll: script,
        pdfOpener: opener,
        child: MaterialApp(home: PayrollDetailScreen(payrollId: id)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without payroll.view is never shown a figure', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('the slip is itemised with the working it was computed from', (
    tester,
  ) async {
    await pumpDetail(tester);

    expect(find.text('Anu Kmani'), findsOneWidget);
    expect(find.byKey(const ValueKey('payroll-status')), findsOneWidget);
    expect(find.text('Calculated'), findsOneWidget);

    // Totals, and each line's own `quantity × rate` — the two together are
    // what makes a net salary checkable rather than a number to trust.
    expect(find.byKey(const ValueKey('payroll-net')), findsOneWidget);
    expect(find.text('INR 28,500.00'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('payroll-lines-earnings-first')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('LOP-working')), findsOneWidget);
    expect(find.text('2.00 × 1000.00'), findsOneWidget);
  });

  testWidgets('one ladder step is offered, and only with the grant', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const ['payroll.view']);

    // A reader may look at the ladder; they may not climb it.
    expect(find.byKey(const ValueKey('payroll-action-review')), findsNothing);

    await pumpDetail(
      tester,
      permissions: const ['payroll.view', 'payroll.manage'],
    );

    expect(find.byKey(const ValueKey('payroll-action-review')), findsOneWidget);
    expect(find.byKey(const ValueKey('payroll-action-lock')), findsNothing);
  });

  testWidgets('reviewing moves the row and says what it became', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      permissions: const ['payroll.view', 'payroll.manage'],
    );

    await tester.tap(find.byKey(const ValueKey('payroll-action-review')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('payroll-review-confirm')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.transitions, ['review']);
    expect(script.lastId, 1);

    // The row is refetched and redrawn from the answer, not patched here.
    expect(find.text('Reviewed'), findsOneWidget);
    expect(find.text('Marked as reviewed.'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('payroll-action-finalize')),
      findsOneWidget,
    );
  });

  testWidgets('locking needs both the grant and the server’s own flag', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const [
        'payroll.view',
        'payroll.manage',
        'payroll.process',
        'payroll.lock',
      ],
    );

    expect(find.byKey(const ValueKey('payroll-action-lock')), findsOneWidget);

    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['payroll.view', 'payroll.manage'],
    );

    // `can_lock` is the server's answer about the row; without the grant
    // the button never appears either, so the two never disagree.
    expect(find.byKey(const ValueKey('payroll-action-lock')), findsNothing);
  });

  testWidgets('a locked row offers nothing, and says why', (tester) async {
    await pumpDetail(
      tester,
      id: 3,
      permissions: const [
        'payroll.view',
        'payroll.manage',
        'payroll.process',
        'payroll.lock',
      ],
    );

    expect(find.text('Locked'), findsOneWidget);
    expect(
      find.text('Locked — this period is the record and cannot be restated.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('payroll-action-review')), findsNothing);
    expect(find.byKey(const ValueKey('payroll-action-lock')), findsNothing);
  });

  testWidgets('a refusal from the ladder is shown as written', (tester) async {
    script.actionError = alreadyMoved;

    await pumpDetail(
      tester,
      permissions: const ['payroll.view', 'payroll.manage'],
    );

    await tester.tap(find.byKey(const ValueKey('payroll-action-review')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('payroll-review-confirm')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(
      find.text('Only a calculated row can be reviewed. This one is locked.'),
      findsOneWidget,
    );
  });

  testWidgets('the payslip is rendered on demand and handed to the opener', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      permissions: const ['payroll.view', 'salary_slips.view'],
    );

    await tester.tap(find.byKey(const ValueKey('download-salary-slip')));
    await advance(tester);

    expect(script.slipPdfCalls, 1);
    expect(opener.calls, 1);
    expect(opener.opened, ['salary-slip-1.pdf']);
  });

  testWidgets('the payslip button belongs to salary_slips.view', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const ['payroll.view']);

    expect(find.byKey(const ValueKey('download-salary-slip')), findsNothing);
  });
}
