import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/site_reports/domain/site_activity_report.dart';
import 'package:mobile/features/site_reports/presentation/site_activity_detail_screen.dart';

import '../../../support/site_reports.dart';

void main() {
  late ScriptedSiteActivityReports repo;

  setUp(() {
    repo = ScriptedSiteActivityReports(
      idOf: (report) => report.id,
      items: <SiteActivityReport>[testActivityReport()],
    );
  });

  Future<void> openDetail(
    WidgetTester tester, {
    SiteActivityReport? report,
    List<String> permissions = const [
      'site_activity_reports.view',
      'site_activity_reports.update',
    ],
  }) async {
    useTallScreen(tester);

    if (report != null) {
      repo.items
        ..clear()
        ..add(report);
    }

    await tester.pumpWidget(
      scopedSiteReports(
        permissions: permissions,
        siteActivity: repo,
        child: MaterialApp.router(
          routerConfig: siteReportsRouter(
            location: '/site-reports/1',
            screen: const SiteActivityDetailScreen(reportId: 1),
          ),
        ),
      ),
    );

    await advance(tester);
  }

  group('who gets in', () {
    testWidgets('a session without the permission never asks the server '
        'for the row', (tester) async {
      await openDetail(tester, permissions: const []);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.findCalls, 0);
    });

    testWidgets('a refusal is left on screen rather than retried', (
      tester,
    ) async {
      repo.findError = forbidden403;

      await openDetail(tester);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(find.byKey(const ValueKey('site-report-detail')), findsNothing);
    });

    testWidgets('a report that could not be opened says so and offers the '
        'way back', (tester) async {
      repo.findError = notFound404;

      await openDetail(tester);

      expect(
        find.byKey(const ValueKey('site-report-detail-error')),
        findsOneWidget,
      );
      expect(find.text('The requested record was not found.'), findsOneWidget);

      await tapIn(tester, find.text('Try again'));
      await advance(tester);

      // The retry failed again, because the fixture never changed — but the
      // point is that it asked, rather than sitting still.
      expect(repo.findCalls, 2);
    });
  });

  group('what it says', () {
    testWidgets('the facts, the progress and the evidence, with nothing '
        'invented for the fields nobody filled', (tester) async {
      await openDetail(tester);

      expect(find.byKey(const ValueKey('site-report-detail')), findsOneWidget);

      expect(find.text('2026-09-28'), findsOneWidget);
      expect(find.text('Block A'), findsOneWidget);
      expect(find.text('Riverfront Towers'), findsOneWidget);
      expect(find.text('Anu Kmani'), findsOneWidget);
      expect(find.text('RCC'), findsOneWidget);
      expect(find.text('Slab pour on the third floor.'), findsOneWidget);
      expect(find.text('12 masons, 8 helpers'), findsOneWidget);

      expect(find.text('60% complete'), findsOneWidget);

      // Fields the author did not fill read as "not recorded" rather than
      // as blank lines a reader would wonder about: materials, equipment,
      // safety, issues, remarks, and the location nobody took.
      expect(find.text('Not recorded'), findsNWidgets(6));
      expect(find.text('Draft'), findsOneWidget);
    });

    testWidgets('prints the coordinates only when all three arrived', (
      tester,
    ) async {
      await openDetail(tester, report: testActivityReport(hasGps: true));

      expect(find.text('12.97160, 77.59460 (±9 m)'), findsOneWidget);
      expect(find.text('Not recorded'), findsWidgets);
    });

    testWidgets('refuses to describe a half-populated fix as a location', (
      tester,
    ) async {
      final partial = SiteActivityReport.fromJson(<String, dynamic>{
        'id': 1,
        'report_date': '2026-09-28',
        'work_category': 'RCC',
        'work_performed': 'Slab pour on the third floor.',
        'status': 'draft',
        'is_draft': true,
        'is_editable': true,
        'has_gps_fix': true,
        'latitude': 12.9716,
        'longitude': 77.5946,
        'photo_count': 0,
      });

      await openDetail(tester, report: partial);

      expect(find.text('Not recorded'), findsWidgets);
      expect(find.textContaining('12.97160'), findsNothing);
    });
  });

  group('correcting it', () {
    testWidgets('is offered only to a session that holds the permission', (
      tester,
    ) async {
      await openDetail(
        tester,
        permissions: const ['site_activity_reports.view'],
      );

      expect(find.byKey(const ValueKey('edit-site-report')), findsNothing);
    });

    testWidgets('is never offered for a report the server has closed', (
      tester,
    ) async {
      await openDetail(
        tester,
        report: testActivityReport(status: SiteActivityReport.statusSubmitted),
      );

      expect(find.byKey(const ValueKey('edit-site-report')), findsNothing);
      expect(find.text('2026-09-28 18:05:00'), findsOneWidget);
    });

    testWidgets('walks to the edit form with the row it named', (tester) async {
      await openDetail(tester);

      await tapIn(tester, find.byKey(const ValueKey('edit-site-report')));
      await tester.pumpAndSettle();

      expect(find.text('stub /site-reports/1/edit'), findsOneWidget);
    });
  });
}
