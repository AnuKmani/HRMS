import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/assets/domain/asset_assignment.dart';
import 'package:mobile/features/assets/presentation/asset_history_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedAssets script;

  setUp(() {
    script = ScriptedAssets();
    script.assignmentRows = [
      AssetAssignment.fromJson(
        assetAssignmentRow(
          id: 71,
          assetId: 1,
          nestedAsset: true,
          employeeName: 'Anu Kmani',
          assignedDate: '2026-09-15',
          assignedCondition: 'good',
          status: AssetAssignment.statusActive,
          daysOut: null,
        ),
      ),
      AssetAssignment.fromJson(
        assetAssignmentRow(
          id: 72,
          assetId: 9,
          nestedAsset: true,
          employeeName: 'Meera Nair',
          assignedDate: '2026-09-01',
          returnedDate: '2026-09-20',
          assignedCondition: 'good',
          returnedCondition: 'poor',
          status: AssetAssignment.statusReturned,
          daysOut: 19,
        ),
      ),
      AssetAssignment.fromJson(
        assetAssignmentRow(
          id: 73,
          assetId: 4,
          employeeName: 'Ravi Varma',
          assignedDate: '2026-09-10',
          expectedReturnDate: '2026-09-20',
          assignedCondition: 'new',
          status: AssetAssignment.statusActive,
          daysOut: 5,
          isOverdue: true,
        ),
      ),
    ];
  });

  Future<void> pumpLog(
    WidgetTester tester, {
    List<String> permissions = const ['assets.view', 'assets.history.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        assets: script,
        child: const MaterialApp(home: AssetHistoryScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without assets.history.view is never asked for the '
      'log', (tester) async {
    await pumpLog(tester, permissions: const ['assets.view']);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(
      find.text('You do not have permission to view the hand-over log.'),
      findsOneWidget,
    );
    expect(script.assignmentsCalls, 0);
    expect(find.byKey(const ValueKey('list-results')), findsNothing);
  });

  testWidgets('an open loan draws a span with nothing after it', (
    tester,
  ) async {
    await pumpLog(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-71')),
        matching: find.text('Anu Kmani'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-71')),
        matching: find.text('Dell Latitude 5540 · 2026-09-15 →'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-71')),
        matching: find.text('Out with holder'),
      ),
      findsOneWidget,
    );
    // No end date, so no count either: a fabricated "12 days out" would be
    // a claim about a date nobody agreed to.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-71')),
        matching: find.text('—'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a closed loan shows both dates and what came back', (
    tester,
  ) async {
    await pumpLog(tester);

    // The log opens on what is out *now*, so a hand-over that came back is
    // one dropdown away rather than something to scroll past.
    await tapIn(tester, find.byKey(const ValueKey('history-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Already returned'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-72')),
        matching: find.text(
          'Dell Latitude 5540 · 2026-09-01 → 2026-09-20 · came back poor',
        ),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-72')),
        matching: find.text('Returned'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-72')),
        matching: find.text('19 days out'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an overdue loan says both words in its own line', (
    tester,
  ) async {
    await pumpLog(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-73')),
        matching: find.text('Overdue · 5 days out'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('history-row-73')),
        matching: find.text('Out with holder'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the log opens on what is out now, and narrowing is a query', (
    tester,
  ) async {
    await pumpLog(tester);

    // "What is out right now" is the question that gets asked first, so
    // the open hand-overs are what arrive and the closed ones are one
    // dropdown away.
    expect(script.lastAssignmentsQuery, isNotNull);
    expect(script.lastAssignmentsQuery!['status'], 'active');
    expect(find.byKey(const ValueKey('history-row-71')), findsOneWidget);
    expect(find.byKey(const ValueKey('history-row-72')), findsNothing);

    await tapIn(tester, find.byKey(const ValueKey('history-status-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Already returned'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastAssignmentsQuery!['status'], 'returned');
    expect(find.byKey(const ValueKey('history-row-71')), findsNothing);
    expect(find.byKey(const ValueKey('history-row-72')), findsOneWidget);
  });

  testWidgets('an empty log says what would appear here', (tester) async {
    script.assignmentRows = <AssetAssignment>[];
    await pumpLog(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No hand-overs yet.'), findsOneWidget);
    expect(
      find.text(
        'Every time something left the shelf and every time it came '
        'back is listed here.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refusal is a message, not an empty log', (tester) async {
    script.assignmentsError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpLog(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
  });
}
