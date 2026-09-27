import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/holidays/domain/holiday.dart';
import 'package:mobile/features/holidays/presentation/holidays_list_screen.dart';

import '../../../support/phase6.dart';

Holiday holiday({
  required int id,
  String name = 'Company Day',
  String date = '2026-08-15',
  String type = Holiday.typePublic,
  String status = Holiday.statusActive,
  String? siteName,
}) => Holiday.fromJson(<String, dynamic>{
  'id': id,
  'name': name,
  'date': date,
  'type': type,
  'status': status,
  if (siteName != null) 'site_id': 2,
  if (siteName != null) 'site': <String, dynamic>{'name': siteName},
});

void main() {
  late ScriptedHolidays script;

  setUp(() {
    script = ScriptedHolidays(
      idOf: (item) => item.id,
      items: [
        holiday(id: 1),
        holiday(
          id: 2,
          name: 'Site shutdown',
          date: '2026-09-30',
          type: Holiday.typeSite,
          siteName: 'Block A',
        ),
        holiday(
          id: 3,
          name: 'Old day',
          date: '2025-01-01',
          status: Holiday.statusInactive,
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const <String>[],
  }) async {
    await tester.pumpWidget(
      scopedPhase6(
        permissions: permissions,
        holidays: script,
        child: const MaterialApp(home: HolidaysListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('any signed-in session can read the calendar', (tester) async {
    // No permission at all: `GET /holidays` carries no `permission:`
    // middleware and `HolidayPolicy::viewAny` says yes, so a screen that
    // hid the list would be inventing a rule the server does not have.
    await pumpList(tester, permissions: const ['attendance.view']);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(find.text('Company Day'), findsOneWidget);
    expect(script.listCalls, 1);
    expect(find.byKey(const ValueKey('no-permission')), findsNothing);
  });

  testWidgets('a row says the scope, not just the type', (tester) async {
    await pumpList(tester);

    expect(find.text('2026-09-30 · Site · Block A'), findsOneWidget);
    expect(find.text('2026-08-15 · Public'), findsOneWidget);
  });

  testWidgets('the write door is only drawn for holidays.manage', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('add-holiday')), findsNothing);
    // With no write permission the row has nowhere to go either — a chevron
    // leading to a form that could not be saved is worse than none.
    expect(tester.widget<ListTile>(find.byType(ListTile).first).onTap, isNull);

    await pumpList(tester, permissions: const ['holidays.manage']);

    expect(find.byKey(const ValueKey('add-holiday')), findsOneWidget);
    expect(
      tester.widget<ListTile>(find.byType(ListTile).first).onTap,
      isNotNull,
    );
  });

  testWidgets('the scope filter is a query parameter, and clearing it '
      'removes the key', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery, isNot(contains('type')));

    await tester.tap(find.byKey(const ValueKey('holiday-type-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Site').last);
    await tester.pumpAndSettle();

    expect(script.lastQuery!['type'], 'site');

    await tester.tap(find.byKey(const ValueKey('holiday-type-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All types'));
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'type', null);
  });

  testWidgets('retired days stay on the list rather than disappearing', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.text('Old day'), findsOneWidget);
    expect(find.text('Retired'), findsOneWidget);
    expect(find.text('Active'), findsNWidgets(2));
  });
}
