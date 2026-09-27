import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/timesheet/domain/timesheet.dart';
import 'package:mobile/features/timesheet/presentation/timesheets_list_screen.dart';

import '../../../support/phase6.dart';

Timesheet timesheet({
  required int id,
  String date = '2026-09-28',
  String status = Timesheet.statusComplete,
  int worked = 480,
  int overtime = 0,
  String? site,
}) => Timesheet.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': 1,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'timesheet_date': date,
  if (site != null) 'site': <String, dynamic>{'name': site},
  'working_minutes': worked,
  'break_minutes': 60,
  'overtime_minutes': overtime,
  'working_hours': worked / 60,
  'overtime_hours': overtime / 60,
  'status': status,
  'attendance_id': id,
});

void main() {
  late ScriptedTimesheets script;

  setUp(() {
    script = ScriptedTimesheets(
      idOf: (item) => item.id,
      items: [
        timesheet(id: 1, site: 'Block A'),
        timesheet(
          id: 2,
          date: '2026-09-27',
          status: Timesheet.statusIncomplete,
          worked: 200,
        ),
        timesheet(id: 3, date: '2026-09-26', overtime: 45),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['timesheets.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase6(
        permissions: permissions,
        timesheets: script,
        child: const MaterialApp(home: TimesheetsListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without timesheets.view is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('generate-timesheets')), findsNothing);
  });

  testWidgets('rows carry the day, whose it was, and what it added up to', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(find.text('Anu Kmani · Block A · 8.00 h worked'), findsOneWidget);
    expect(find.text('Mon 28 Sep 2026'), findsOneWidget);
    // Two of the three days were worked to completion; only the third was
    // short. A single chip either way would not distinguish that.
    expect(find.text('Complete'), findsNWidgets(2));
    expect(find.text('Incomplete'), findsOneWidget);
  });

  testWidgets('the extra hours are shown, not folded into the total', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.text('Anu Kmani · Block A · 8.00 h worked'), findsOneWidget);
    expect(find.textContaining('0.75 h extra'), findsOneWidget);
  });

  testWidgets('generate is only offered to timesheets.manage, and it does '
      'ask the server', (tester) async {
    await pumpList(tester, permissions: const ['timesheets.view']);

    expect(find.byKey(const ValueKey('generate-timesheets')), findsNothing);
    expect(script.generateCalls, 0);

    await pumpList(
      tester,
      permissions: const ['timesheets.view', 'timesheets.manage'],
    );

    expect(find.byKey(const ValueKey('generate-timesheets')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('generate-timesheets')));
    await advance(tester);

    expect(script.generateCalls, 1);
    // Twice over the same days would refresh the snapshots rather than make
    // a second copy — which is why there is no guard against pressing it.
    await tester.tap(find.byKey(const ValueKey('generate-timesheets')));
    await advance(tester);

    expect(script.generateCalls, 2);
  });

  testWidgets('the day-status filter is a query parameter, and clearing it '
      'removes the key', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery, isNot(contains('status')));

    await tester.tap(find.byKey(const ValueKey('timesheet-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Incomplete').last);
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'incomplete');

    await tester.tap(find.byKey(const ValueKey('timesheet-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All').last);
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'status', null);
  });
}
