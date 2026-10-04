import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/payroll/data/slip_requests_repository.dart';
import 'package:mobile/features/payroll/domain/payroll.dart';
import 'package:mobile/features/payroll/presentation/salary_slips_screen.dart';

import '../../../support/phase8.dart';

Payroll slip({
  required int id,
  String? request,
  bool canDownload = false,
  String name = 'UAT Employee',
}) => Payroll.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': id,
  'employee': <String, dynamic>{'full_name': name, 'employee_code': 'E$id'},
  'payroll_year': 2026,
  'payroll_month': 9,
  'period_label': 'September 2026',
  'net_salary': '30000.00',
  'status': Payroll.statusProcessed,
  'currency': 'AED',
  'slip_request_status': request,
  'can_download': canDownload,
});

class FakeSlipRequests implements SlipRequestsRepository {
  final List<String> calls = [];

  @override
  Future<Payroll> request(int payrollId) async {
    calls.add('request:$payrollId');
    return slip(id: payrollId, request: 'requested');
  }

  @override
  Future<Payroll> approve(int payrollId) async {
    calls.add('approve:$payrollId');
    return slip(id: payrollId, request: 'approved', canDownload: true);
  }

  @override
  Future<Payroll> reject(int payrollId) async {
    calls.add('reject:$payrollId');
    return slip(id: payrollId, request: 'rejected');
  }
}

void main() {
  late FakeSlipRequests requests;
  late RecordingPdfOpener opener;

  setUp(() {
    requests = FakeSlipRequests();
    opener = RecordingPdfOpener();
  });

  Future<void> pump(
    WidgetTester tester, {
    required List<Payroll> rows,
    List<String> permissions = const ['salary_slips.view'],
  }) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        payroll: ScriptedPayroll(idOf: (item) => item.id, slipRows: rows),
        pdfOpener: opener,
        child: ProviderScope(
          overrides: [
            slipRequestsRepositoryProvider.overrideWithValue(requests),
          ],
          child: const MaterialApp(home: SalarySlipsScreen()),
        ),
      ),
    );
    await advance(tester);
  }

  testWidgets('an unreleased slip offers Request, asks, and tells HR', (
    tester,
  ) async {
    await pump(tester, rows: [slip(id: 1)]);

    expect(find.byKey(const ValueKey('slip-request-1')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('slip-row-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('slip-request-confirm')));
    await tester.pumpAndSettle();

    expect(requests.calls, ['request:1']);
    expect(opener.opened, isEmpty);
    expect(find.text('Request sent. HR has been notified.'), findsOneWidget);
  });

  testWidgets('a requested slip waits, and tapping it does not ask twice', (
    tester,
  ) async {
    await pump(tester, rows: [slip(id: 2, request: 'requested')]);

    expect(find.byKey(const ValueKey('slip-waiting-2')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('slip-row-2')));
    await tester.pumpAndSettle();

    expect(requests.calls, isEmpty);
  });

  testWidgets('a released slip downloads', (tester) async {
    await pump(
      tester,
      rows: [slip(id: 3, request: 'approved', canDownload: true)],
    );

    expect(find.byKey(const ValueKey('slip-download-3')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('slip-row-3')));
    await advance(tester);

    expect(opener.opened, isNotEmpty);
  });

  testWidgets('HR releases a pending request', (tester) async {
    await pump(
      tester,
      permissions: const ['salary_slips.view', 'salary_slips.manage'],
      rows: [slip(id: 4, request: 'requested', canDownload: true)],
    );

    expect(find.byKey(const ValueKey('slip-pending-4')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('slip-row-4')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('slip-release')));
    await tester.pumpAndSettle();

    expect(requests.calls, ['approve:4']);
    expect(
      find.text('Released. The employee has been notified.'),
      findsOneWidget,
    );
  });
}
