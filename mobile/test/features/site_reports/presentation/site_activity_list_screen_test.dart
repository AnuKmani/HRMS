import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/site_reports/domain/site_activity_report.dart';
import 'package:mobile/features/site_reports/domain/site_report_photo.dart';
import 'package:mobile/features/site_reports/presentation/site_activity_list_screen.dart';

import '../../../support/site_reports.dart';

void main() {
  late ScriptedSiteActivityReports repo;

  setUp(() {
    repo = ScriptedSiteActivityReports(
      idOf: (report) => report.id,
      items: <SiteActivityReport>[
        testActivityReport(id: 1),
        testActivityReport(
          id: 2,
          date: '2026-09-29',
          status: SiteActivityReport.statusSubmitted,
          // The photograph count only prints when there is a frame to count,
          // so the fixture has to carry one for the row to read differently
          // from its neighbour's.
          photos: <SiteReportPhoto>[testPhoto()],
          photoCount: 3,
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['site_activity_reports.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedSiteReports(
        permissions: permissions,
        siteActivity: repo,
        child: MaterialApp.router(
          routerConfig: siteReportsRouter(
            location: '/site-reports',
            screen: const SiteActivityListScreen(),
          ),
        ),
      ),
    );

    await advance(tester);
  }

  group('who gets in', () {
    testWidgets('a session without the permission sees a decision, not an '
        'empty list', (tester) async {
      await pumpList(tester, permissions: const []);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(find.byKey(const ValueKey('list-empty')), findsNothing);
      expect(repo.listCalls, 0);
    });

    testWidgets('a refusal from the server lands on the error surface with '
        'the server\'s own sentence', (tester) async {
      repo.listError = forbidden403;

      await pumpList(tester);

      expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
      expect(find.text('This action is forbidden.'), findsOneWidget);
      expect(find.byKey(const ValueKey('list-retry')), findsOneWidget);
    });

    testWidgets('nothing filed yet says so, and says what will appear', (
      tester,
    ) async {
      repo.items.clear();

      await pumpList(tester);

      expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
      expect(find.text('No site reports yet.'), findsOneWidget);
    });
  });

  group('what is on the list', () {
    testWidgets('one row per report, carrying the day, the site, the author '
        'and the state', (tester) async {
      await pumpList(tester);

      expect(find.byKey(const ValueKey('site-report-row-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('site-report-row-2')), findsOneWidget);

      // The name comes from the server, which decides whose reports appear
      // at all — so printing it can never overstate the authorship.
      expect(find.text('2026-09-28 · Block A'), findsOneWidget);
      expect(find.text('2026-09-29 · Block A'), findsOneWidget);
      expect(find.text('Anu Kmani · RCC'), findsOneWidget);
      expect(find.text('Anu Kmani · RCC · 3 photo(s)'), findsOneWidget);
      expect(find.text('Draft'), findsOneWidget);
      expect(find.text('Submitted'), findsOneWidget);
    });

    testWidgets('the button to file one appears only for a session that '
        'may', (tester) async {
      await pumpList(tester, permissions: const ['site_activity_reports.view']);
      expect(find.byKey(const ValueKey('new-site-report')), findsNothing);

      await pumpList(
        tester,
        permissions: const [
          'site_activity_reports.view',
          'site_activity_reports.create',
        ],
      );
      expect(find.byKey(const ValueKey('new-site-report')), findsOneWidget);
    });

    testWidgets('opening one walks to it rather than to a stub', (
      tester,
    ) async {
      await pumpList(tester);

      await tapIn(tester, find.byKey(const ValueKey('site-report-row-2')));
      await tester.pumpAndSettle();

      expect(find.text('2026-09-29 · Block A'), findsNothing);
      // The router resolved the tapped row, id and all — which is the whole
      // of what a navigation assertion is for.
      expect(find.text('stub /site-reports/2'), findsOneWidget);
    });
  });

  group('the status filter', () {
    testWidgets('narrows the query, and clearing it removes the key '
        'entirely', (tester) async {
      await pumpList(tester);
      expect(repo.lastQuery!.containsKey('status'), isFalse);

      await tapIn(
        tester,
        find.byKey(const ValueKey('site-report-status-filter')),
      );
      await tester.pumpAndSettle();
      await tapIn(tester, find.text('Drafts only'));
      await tester.pumpAndSettle();

      expect(repo.lastQuery!['status'], SiteActivityReport.statusDraft);

      await tapIn(
        tester,
        find.byKey(const ValueKey('site-report-status-filter')),
      );
      await tester.pumpAndSettle();
      await tapIn(tester, find.text('All statuses'));
      await tester.pumpAndSettle();

      // Absent and empty are different questions to the API: a filter of
      // `status=` is not the same request as no `status` at all.
      expect(repo.lastQuery!.containsKey('status'), isFalse);
    });
  });
}
