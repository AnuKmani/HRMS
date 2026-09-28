import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';
import 'package:mobile/features/site_reports/presentation/daily_report_detail_screen.dart';
import 'package:mobile/features/site_reports/presentation/report_pdf_opener.dart';

import '../../../support/site_reports.dart';

void main() {
  late ScriptedDailySiteReports repo;
  late FakeReportPdfOpener pdf;

  setUp(() {
    repo = ScriptedDailySiteReports(
      idOf: (report) => report.id,
      items: <DailySiteReport>[
        testDailyReport(
          materials: const <MaterialRow>[
            MaterialRow(name: 'Cement', quantity: 20, unit: 'bags'),
          ],
          equipment: const <EquipmentRow>[
            EquipmentRow(name: 'Concrete pump', quantity: 2, operatingHours: 3),
          ],
        ),
      ],
    );
    pdf = FakeReportPdfOpener();
  });

  const view = 'daily_site_reports.view';
  const pdfPermission = 'daily_site_reports.pdf';
  const update = 'daily_site_reports.update';

  Future<void> openDetail(
    WidgetTester tester, {
    List<String> permissions = const [view, pdfPermission, update],
    DailySiteReport? report,
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
        daily: repo,
        pdf: pdf,
        child: MaterialApp.router(
          routerConfig: siteReportsRouter(
            location: '/daily-reports/1',
            screen: const DailySiteReportDetailScreen(reportId: 1),
          ),
        ),
      ),
    );

    await advance(tester);
  }

  group('who gets in', () {
    testWidgets('a session without the permission never asks the server for '
        'the document', (tester) async {
      await openDetail(tester, permissions: const []);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.findCalls, 0);
    });

    testWidgets('a refusal from the server is left on screen', (tester) async {
      repo.findError = forbidden403;

      await openDetail(tester);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(find.byKey(const ValueKey('daily-report-detail')), findsNothing);
    });

    testWidgets('a report that could not be opened says so and can be '
        'tried again', (tester) async {
      repo.findError = notFound404;

      await openDetail(tester);

      expect(
        find.byKey(const ValueKey('daily-report-detail-error')),
        findsOneWidget,
      );
      expect(find.text('The requested record was not found.'), findsOneWidget);

      await tapIn(tester, find.text('Try again'));
      await advance(tester);

      expect(repo.findCalls, 2);
    });
  });

  group('what it says', () {
    testWidgets('the official record: who prepared it, what the day held, '
        'and the rows underneath the total', (tester) async {
      await openDetail(tester);

      expect(find.byKey(const ValueKey('daily-report-detail')), findsOneWidget);

      expect(find.text('2026-09-28'), findsOneWidget);
      expect(find.text('Block A'), findsOneWidget);
      expect(find.text('Riverfront Towers'), findsOneWidget);
      expect(find.text('Anu Kmani'), findsOneWidget);
      expect(find.text('18 people'), findsOneWidget);
      expect(find.text('Slab shuttering on the third floor.'), findsOneWidget);
      expect(find.text('Shuttering finished up to grid C.'), findsOneWidget);
      expect(find.text('Two harnesses reissued.'), findsOneWidget);

      // The three child sets, each as its own card.
      expect(
        find.byKey(const ValueKey('daily-report-manpower')),
        findsOneWidget,
      );
      expect(find.text('Masons'), findsOneWidget);
      expect(find.text('Helpers'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('daily-report-materials')),
        findsOneWidget,
      );
      expect(find.text('Cement — 20 bags'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('daily-report-equipment')),
        findsOneWidget,
      );
      expect(find.text('Concrete pump ×2 3.0 h'), findsOneWidget);

      // Delays, issues and remarks were not filled in, and the screen says
      // so rather than leaving three unlabelled gaps.
      expect(find.text('Not recorded'), findsNWidgets(3));
    });

    testWidgets('the total prefers the rows a reader can add up', (
      tester,
    ) async {
      await openDetail(
        tester,
        report: DailySiteReport.fromJson(<String, dynamic>{
          'id': 1,
          'report_date': '2026-09-28',
          'total_manpower': 42,
          'work_planned': 'Slab shuttering on the third floor.',
          'work_completed': 'Shuttering finished up to grid C.',
          'status': 'draft',
          'is_draft': true,
          'is_editable': true,
          'manpower': <dynamic>[],
          'materials': <dynamic>[],
          'equipment': <dynamic>[],
        }),
      );

      expect(find.text('42 people'), findsOneWidget);
      expect(find.byKey(const ValueKey('daily-report-manpower')), findsNothing);
    });
  });

  group('correcting it', () {
    testWidgets('is offered only to a session that holds the permission', (
      tester,
    ) async {
      await openDetail(tester, permissions: const [view, pdfPermission]);

      expect(find.byKey(const ValueKey('edit-daily-report')), findsNothing);
    });

    testWidgets('is never offered for a document the server has closed', (
      tester,
    ) async {
      await openDetail(
        tester,
        report: testDailyReport(status: DailySiteReport.statusSubmitted),
      );

      expect(find.byKey(const ValueKey('edit-daily-report')), findsNothing);
      expect(find.text('2026-09-28 18:30:00'), findsOneWidget);
    });

    testWidgets('walks to the edit form with the row it named', (tester) async {
      await openDetail(tester);

      await tapIn(tester, find.byKey(const ValueKey('edit-daily-report')));
      await tester.pumpAndSettle();

      expect(find.text('stub /daily-reports/1/edit'), findsOneWidget);
    });
  });

  group('the download', () {
    testWidgets('is absent rather than present-and-refused without the '
        'permission', (tester) async {
      await openDetail(tester, permissions: const [view, update]);

      expect(
        find.byKey(const ValueKey('download-daily-report-pdf')),
        findsNothing,
      );
      expect(pdf.calls, 0);
    });

    testWidgets('asks for the report by id and names the file after its day', (
      tester,
    ) async {
      await openDetail(tester);

      await tapIn(
        tester,
        find.byKey(const ValueKey('download-daily-report-pdf')),
      );
      await advance(tester);

      expect(pdf.calls, 1);
      expect(pdf.lastId, 1);
      expect(pdf.lastFilename, reportPdfFilename(1, '2026-09-28'));
      expect(pdf.lastFilename, 'daily-site-report-1-28092026.pdf');
      expect(
        find.byKey(const ValueKey('daily-report-pdf-error')),
        findsNothing,
      );
    });

    testWidgets('a refusal says so in the words the permission deserves', (
      tester,
    ) async {
      pdf.error = forbidden403;

      await openDetail(tester);
      await tapIn(
        tester,
        find.byKey(const ValueKey('download-daily-report-pdf')),
      );
      await advance(tester);

      expect(
        find.byKey(const ValueKey('daily-report-pdf-error')),
        findsOneWidget,
      );
      expect(
        find.text('You are not allowed to export this report.'),
        findsOneWidget,
      );
      // Not retried: asking again would get the same refusal.
      expect(pdf.calls, 1);
    });

    testWidgets('a session that ended while the document was in flight says '
        'which button is next', (tester) async {
      pdf.error = const ApiException(
        statusCode: 401,
        message: 'Unauthenticated.',
      );

      await openDetail(tester);
      await tapIn(
        tester,
        find.byKey(const ValueKey('download-daily-report-pdf')),
      );
      await advance(tester);

      expect(
        find.text(
          'Your session has ended. Sign in again to download this report.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('a server that could not build it says to check the report, '
        'not the network', (tester) async {
      pdf.error = const ApiException(
        statusCode: 422,
        message: 'The report could not be rendered.',
        errors: <String, String>{},
      );

      await openDetail(tester);
      await tapIn(
        tester,
        find.byKey(const ValueKey('download-daily-report-pdf')),
      );
      await advance(tester);

      expect(
        find.text(
          'The server could not build this report. Check it is complete, '
          'then try again.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('a server that could not be reached says that instead', (
      tester,
    ) async {
      pdf.error = unreachable;

      await openDetail(tester);
      await tapIn(
        tester,
        find.byKey(const ValueKey('download-daily-report-pdf')),
      );
      await advance(tester);

      expect(
        find.text(
          'Could not reach the server. Check your connection and try again.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('is not asked twice while the first one is still running', (
      tester,
    ) async {
      final gate = Completer<void>();
      pdf.hold = gate;

      await openDetail(tester);

      final button = find.byKey(const ValueKey('download-daily-report-pdf'));
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pump();

      // Still in flight, and the button is disabled rather than queued —
      // a second tap would render the same document twice for no reason.
      expect(pdf.calls, 1);
      expect(find.text('Preparing…'), findsOneWidget);
      expect(tester.widget<FilledButton>(button).onPressed, isNull);

      gate.complete();
      await advance(tester);

      expect(find.text('Preparing…'), findsNothing);
      expect(find.text('Download PDF'), findsOneWidget);
      expect(pdf.calls, 1);
    });
  });
}
