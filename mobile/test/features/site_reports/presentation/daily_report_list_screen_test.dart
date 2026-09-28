import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';
import 'package:mobile/features/site_reports/presentation/daily_report_list_screen.dart';

import '../../../support/site_reports.dart';

void main() {
  late ScriptedDailySiteReports repo;

  setUp(() {
    repo = ScriptedDailySiteReports(
      idOf: (report) => report.id,
      items: <DailySiteReport>[
        testDailyReport(id: 1),
        testDailyReport(
          id: 2,
          date: '2026-09-29',
          status: DailySiteReport.statusSubmitted,
          manpower: const <ManpowerRow>[
            ManpowerRow(category: 'Masons', count: 4),
          ],
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['daily_site_reports.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedSiteReports(
        permissions: permissions,
        daily: repo,
        child: MaterialApp.router(
          routerConfig: siteReportsRouter(
            location: '/daily-reports',
            screen: const DailySiteReportListScreen(),
          ),
        ),
      ),
    );

    await advance(tester);
  }

  group('who gets in', () {
    // Withheld from the Employee role deliberately: an official document
    // about a site-day is not something the person who only worked on it
    // gets to read back.
    testWidgets('a session without the permission sees a decision, not an '
        'empty list', (tester) async {
      await pumpList(tester, permissions: const []);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(find.byKey(const ValueKey('list-empty')), findsNothing);
      expect(repo.listCalls, 0);
    });

    testWidgets('a refusal from the server lands on the error surface', (
      tester,
    ) async {
      repo.listError = forbidden403;

      await pumpList(tester);

      expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
      expect(find.text('This action is forbidden.'), findsOneWidget);
    });

    testWidgets('nothing prepared yet says so, and says what will appear', (
      tester,
    ) async {
      repo.items.clear();

      await pumpList(tester);

      expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
      expect(find.text('No daily reports yet.'), findsOneWidget);
    });
  });

  group('what is on the list', () {
    testWidgets('one row per site-day, carrying the day, the author and the '
        'head count', (tester) async {
      await pumpList(tester);

      expect(find.byKey(const ValueKey('daily-report-row-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('daily-report-row-2')), findsOneWidget);

      expect(find.text('2026-09-28 · Block A'), findsOneWidget);
      expect(find.text('2026-09-29 · Block A'), findsOneWidget);
      expect(find.text('By Anu Kmani · 18 on site'), findsOneWidget);
      expect(find.text('By Anu Kmani · 4 on site'), findsOneWidget);
      expect(find.text('Draft'), findsOneWidget);
      expect(find.text('Submitted'), findsOneWidget);
    });

    testWidgets('the button to prepare one appears only for a session that '
        'may', (tester) async {
      await pumpList(tester);
      expect(find.byKey(const ValueKey('new-daily-report')), findsNothing);

      await pumpList(
        tester,
        permissions: const [
          'daily_site_reports.view',
          'daily_site_reports.create',
        ],
      );
      expect(find.byKey(const ValueKey('new-daily-report')), findsOneWidget);
    });

    testWidgets('opening one walks to it with the row it named', (
      tester,
    ) async {
      await pumpList(tester);

      await tapIn(tester, find.byKey(const ValueKey('daily-report-row-2')));
      await tester.pumpAndSettle();

      expect(find.text('2026-09-29 · Block A'), findsNothing);
      expect(find.text('stub /daily-reports/2'), findsOneWidget);
    });
  });

  group('the status filter', () {
    testWidgets('narrows the query, and clearing it removes the key '
        'entirely', (tester) async {
      await pumpList(tester);
      expect(repo.lastQuery!.containsKey('status'), isFalse);

      await tapIn(
        tester,
        find.byKey(const ValueKey('daily-report-status-filter')),
      );
      await tester.pumpAndSettle();
      await tapIn(tester, find.text('Submitted only'));
      await tester.pumpAndSettle();

      expect(repo.lastQuery!['status'], DailySiteReport.statusSubmitted);

      await tapIn(
        tester,
        find.byKey(const ValueKey('daily-report-status-filter')),
      );
      await tester.pumpAndSettle();
      await tapIn(tester, find.text('All statuses'));
      await tester.pumpAndSettle();

      expect(repo.lastQuery!.containsKey('status'), isFalse);
    });
  });
}
