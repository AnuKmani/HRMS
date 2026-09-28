import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/payroll/domain/payroll.dart';
import 'package:mobile/features/payroll/presentation/salary_slips_screen.dart';

import '../../../support/phase8.dart';

void main() {
  late ScriptedPayroll script;
  late RecordingPdfOpener opener;

  setUp(() {
    opener = RecordingPdfOpener();
    script = ScriptedPayroll(
      idOf: (item) => item.id,
      items: [payrollRow(id: 99, name: 'Somebody Else')],
      slipRows: [
        payrollRow(id: 1, status: Payroll.statusLocked, net: '28500.00'),
        payrollRow(
          id: 2,
          name: 'Meera Nair',
          status: Payroll.statusProcessed,
          net: '31000.00',
        ),
      ],
    );
  });

  Future<void> pumpSlips(
    WidgetTester tester, {
    List<String> permissions = const ['salary_slips.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        payroll: script,
        pdfOpener: opener,
        child: const MaterialApp(home: SalarySlipsScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('salary_slips.view is a door of its own', (tester) async {
    await pumpSlips(tester, permissions: const ['payroll.view']);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.slipCalls, 0);
  });

  testWidgets('it reads the slips endpoint, never the ledger', (tester) async {
    await pumpSlips(tester);

    expect(script.slipCalls, 1);
    expect(script.listCalls, 0);

    expect(find.byKey(const ValueKey('salary-slip-list')), findsOneWidget);
    expect(find.byKey(const ValueKey('slip-row-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('slip-row-2')), findsOneWidget);
    expect(find.text('INR 28,500.00'), findsOneWidget);

    // The ledger's row — reachable through `payroll.view` — is not in this
    // list, because that is the endpoint that was asked.
    expect(find.byKey(const ValueKey('slip-row-99')), findsNothing);
    expect(find.text('Somebody Else'), findsNothing);
  });

  testWidgets('the screen says out loud that no copy is kept', (tester) async {
    await pumpSlips(tester);

    expect(find.byKey(const ValueKey('slip-note')), findsOneWidget);
    expect(
      find.text('Rendered on demand — no copy is kept on this device.'),
      findsOneWidget,
    );
  });

  testWidgets('a tap renders the slip and hands the bytes over', (
    tester,
  ) async {
    await pumpSlips(tester);

    await tester.tap(find.byKey(const ValueKey('slip-row-1')));
    await advance(tester);

    expect(script.slipPdfCalls, 1);
    expect(opener.calls, 1);
    expect(opener.opened, ['salary-slip-1.pdf']);
  });
}
