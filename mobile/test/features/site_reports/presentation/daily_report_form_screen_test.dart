import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/site_reports/data/report_draft_store.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';
import 'package:mobile/features/site_reports/presentation/daily_report_form_screen.dart';

import '../../../support/site_reports.dart';

/// Today, as the date field will have written it.
String get _today {
  final now = DateTime.now();

  return '${now.year.toString().padLeft(4, '0')}-'
      '${now.month.toString().padLeft(2, '0')}-'
      '${now.day.toString().padLeft(2, '0')}';
}

/// The row tally a section shows — looked up by key rather than by text,
/// because all three sections render the same phrases ("0 rows", "1 row")
/// and `find.text` would count the neighbours' too.
String rowsLabel(WidgetTester tester, String id) =>
    tester.widget<Text>(find.byKey(ValueKey('$id-count'))).data ?? '';

void main() {
  late ScriptedDailySiteReports repo;
  late MemoryReportDraftStore drafts;

  setUp(() {
    repo = ScriptedDailySiteReports(
      idOf: (report) => report.id,
      items: <DailySiteReport>[testDailyReport()],
    );
    drafts = MemoryReportDraftStore();
  });

  Future<void> openForm(
    WidgetTester tester, {
    int? reportId,
    List<String> permissions = const ['daily_site_reports.create'],
  }) async {
    useTallScreen(tester);

    final location = reportId == null
        ? '/daily-reports/new'
        : '/daily-reports/$reportId/edit';

    await tester.pumpWidget(
      scopedSiteReports(
        permissions: permissions,
        daily: repo,
        drafts: drafts,
        child: MaterialApp.router(
          routerConfig: siteReportsRouter(
            location: location,
            screen: reportId == null
                ? const DailySiteReportFormScreen()
                : DailySiteReportFormScreen(reportId: reportId),
          ),
        ),
      ),
    );

    await advance(tester);
  }

  /// Everything the four flat fields need, plus the first workforce row.
  Future<void> fillCore(WidgetTester tester) async {
    await chooseSite(tester, field: const ValueKey('daily-report-site'));
    await pickDate(tester, const ValueKey('daily-report-date'));
    await type(
      tester,
      const ValueKey('daily-report-planned'),
      'Slab shuttering on the third floor.',
    );
    await type(
      tester,
      const ValueKey('daily-report-completed'),
      'Shuttering finished up to grid C.',
    );
  }

  Future<void> fillManpower(WidgetTester tester) async {
    await type(tester, const ValueKey('manpower-0-category'), 'Masons');
    await type(tester, const ValueKey('manpower-0-count'), '12');
  }

  group('who gets in', () {
    testWidgets('a session without the permission is never shown the form', (
      tester,
    ) async {
      await openForm(tester, permissions: const ['daily_site_reports.view']);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(find.byKey(const ValueKey('save-daily-report')), findsNothing);
      expect(find.byKey(const ValueKey('daily-report-site')), findsNothing);
    });

    testWidgets('an official document cannot be corrected without the '
        'permission that would be refused a second later', (tester) async {
      await openForm(
        tester,
        reportId: 1,
        permissions: const ['daily_site_reports.create'],
      );

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.findCalls, 0);
    });

    testWidgets('a refusal while saving lands as the permission screen', (
      tester,
    ) async {
      await openForm(tester);
      await fillCore(tester);
      await fillManpower(tester);

      repo.saveError = forbidden403;

      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.createCalls, 1);
    });
  });

  group('what the server is asked', () {
    testWidgets('an empty form refuses to save and names what is missing', (
      tester,
    ) async {
      await openForm(tester);

      // The workforce section is quiet until somebody asks to save: a form
      // that opens by complaining about a row nobody has touched yet is a
      // form people learn to ignore.
      expect(find.byKey(const ValueKey('manpower-error')), findsNothing);

      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(
        find.text('Choose the site this report is about.'),
        findsOneWidget,
      );
      expect(find.text('Choose the day this report covers.'), findsOneWidget);
      expect(find.text('Say what was planned for the day.'), findsOneWidget);
      expect(find.text('Say what was actually completed.'), findsOneWidget);
      expect(find.text('Row 1: category is needed.'), findsOneWidget);
      expect(find.byKey(const ValueKey('manpower-error')), findsOneWidget);
      expect(repo.createCalls, 0);
    });

    testWidgets('rows go up as child rows, and the total is their sum', (
      tester,
    ) async {
      await openForm(tester);
      await fillCore(tester);
      await fillManpower(tester);

      await tapIn(tester, find.byKey(const ValueKey('manpower-add')));
      await tester.pump();

      expect(find.byKey(const ValueKey('manpower-row-0')), findsOneWidget);
      expect(find.byKey(const ValueKey('manpower-row-1')), findsOneWidget);
      expect(rowsLabel(tester, 'manpower'), '2 rows');
      expect(rowsLabel(tester, 'materials'), '0 rows');

      await type(tester, const ValueKey('manpower-1-category'), 'Helpers');
      await type(tester, const ValueKey('manpower-1-count'), '6');
      await tester.pump();

      expect(find.text('18 people'), findsOneWidget);

      // Materials and equipment are rows too, not a text box.
      await tapIn(tester, find.byKey(const ValueKey('materials-add')));
      await tester.pump();
      await type(tester, const ValueKey('materials-0-name'), 'Cement');
      await type(tester, const ValueKey('materials-0-quantity'), '20');
      await type(tester, const ValueKey('materials-0-unit'), 'bags');

      await tapIn(tester, find.byKey(const ValueKey('equipment-add')));
      await tester.pump();
      await type(tester, const ValueKey('equipment-0-name'), 'Concrete pump');
      await type(tester, const ValueKey('equipment-0-quantity'), '2');
      await type(tester, const ValueKey('equipment-0-hours'), '3');

      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(repo.createCalls, 1);

      final body = repo.lastBody!;
      expect(body['site_id'], 1);
      expect(body['project_id'], 10);
      expect(body['report_date'], _today);
      expect(body['total_manpower'], 18);
      expect(body, isNot(contains('employee_id')));
      expect(body, isNot(contains('created_by')));
      expect(body, isNot(contains('status')));

      expect(body['manpower'], <Object?>[
        <String, Object?>{'category': 'Masons', 'count': 12},
        <String, Object?>{'category': 'Helpers', 'count': 6},
      ]);

      expect(body['materials'], <Object?>[
        <String, Object?>{
          'material_name': 'Cement',
          'quantity': 20.0,
          'unit': 'bags',
        },
      ]);

      expect(body['equipment'], <Object?>[
        <String, Object?>{
          'equipment_name': 'Concrete pump',
          'quantity': 2,
          'operating_hours': 3.0,
        },
      ]);
    });

    testWidgets('removing the last workforce row means "none", not one '
        'blank one', (tester) async {
      await openForm(tester);

      expect(find.byKey(const ValueKey('manpower-row-0')), findsOneWidget);

      await tapIn(tester, find.byKey(const ValueKey('manpower-remove-0')));
      await tester.pump();

      expect(find.byKey(const ValueKey('manpower-row-0')), findsNothing);
      expect(find.byKey(const ValueKey('manpower-empty')), findsOneWidget);
      expect(find.text('No categories yet.'), findsOneWidget);
      expect(rowsLabel(tester, 'manpower'), '0 rows');

      // Two blank rows would have been reported as two categories of zero.
      await fillCore(tester);
      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(repo.createCalls, 1);
      expect(repo.lastBody!['manpower'], isEmpty);
      expect(repo.lastBody!['total_manpower'], 0);
    });

    testWidgets('the server\'s duplicate-day refusal lands on the date, not '
        'on a banner', (tester) async {
      await openForm(tester);
      await fillCore(tester);
      await fillManpower(tester);

      repo.saveError = const ApiException(
        statusCode: 422,
        message: 'The report could not be saved.',
        errors: <String, String>{
          'report_date':
              'A report has already been written for that site '
              'on that day.',
        },
      );

      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(
        find.text(
          'A report has already been written for that site on that day.',
        ),
        findsOneWidget,
      );
      // A one-input answer stays on the input: "somebody has already
      // written this day" is not a failure of the whole form.
      expect(find.text('Daily report saved as a draft.'), findsNothing);
      expect(repo.createCalls, 1);
    });

    testWidgets('a server-side draft is corrected with PUT, and the phone\'s '
        'local copy is left alone', (tester) async {
      await openForm(
        tester,
        reportId: 1,
        permissions: const ['daily_site_reports.update'],
      );
      await advance(tester);

      expect(repo.findCalls, 1);
      expect(find.text('Edit daily site report'), findsOneWidget);
      expect(find.text('Masons'), findsOneWidget);
      expect(find.text('Helpers'), findsOneWidget);

      await type(
        tester,
        const ValueKey('daily-report-planned'),
        'Slab shuttering on the third floor, revised.',
      );
      await tapIn(tester, find.byKey(const ValueKey('save-daily-report')));
      await advance(tester);

      expect(repo.updateCalls, 1);
      expect(repo.createCalls, 0);
      expect(repo.lastId, 1);
      expect(
        repo.lastBody!['work_planned'],
        'Slab shuttering on the third floor, revised.',
      );
      expect(drafts.writeCalls, 0);
      expect(drafts.clearCalls, 0);
    });
  });

  group('submitting', () {
    testWidgets('saves and files it in one press, with no body of its own', (
      tester,
    ) async {
      await openForm(tester);
      await fillCore(tester);
      await fillManpower(tester);

      await tapIn(tester, find.byKey(const ValueKey('submit-daily-report')));
      await advance(tester);

      expect(repo.createCalls, 1);
      expect(repo.submitCalls, 1);
      expect(repo.lastSubmitId, 1);
      expect(repo.lastBody!.containsKey('submitted_at'), isFalse);
      expect(find.text('stub /daily-reports/1'), findsOneWidget);
    });

    testWidgets('a refusal from the submit leaves the document a draft and '
        'says so', (tester) async {
      await openForm(tester);
      await fillCore(tester);
      await fillManpower(tester);

      repo.submitError = forbidden403;

      await tapIn(tester, find.byKey(const ValueKey('submit-daily-report')));
      await advance(tester);

      expect(repo.createCalls, 1);
      expect(repo.submitCalls, 1);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    });
  });

  group('the local draft', () {
    testWidgets('is written after a pause, and only for a new report', (
      tester,
    ) async {
      await openForm(tester);

      await type(
        tester,
        const ValueKey('daily-report-planned'),
        'Slab shuttering on the third floor.',
      );
      expect(drafts.writeCalls, 0);

      await advance(tester);

      expect(drafts.writeCalls, 1);
      expect(drafts.lastSlot, dailyDraftSlot);
      expect(
        drafts.lastDraft!['work_planned'],
        'Slab shuttering on the third floor.',
      );
      // Photographs are deliberately not part of the snapshot: a local copy
      // of a frame the person already discarded would come back as evidence
      // of a photograph nobody took.
      expect(drafts.lastDraft!.keys, isNot(contains('photos')));
    });

    testWidgets('comes back with its workforce rows, and can be thrown away', (
      tester,
    ) async {
      drafts.slots[dailyDraftSlot] = <String, Object?>{
        'site_id': 1,
        'site_name': 'Block A',
        'project_id': 10,
        'report_date': '2026-09-28',
        'work_planned': 'Slab shuttering on the third floor.',
        'work_completed': '',
        'safety_observations': '',
        'delays': '',
        'issues': '',
        'remarks': '',
        'manpower': <dynamic>[
          <dynamic>['Masons', '12'],
        ],
        'materials': <dynamic>[],
        'equipment': <dynamic>[],
      };

      await openForm(tester);

      expect(
        find.byKey(const ValueKey('daily-report-draft-notice')),
        findsOneWidget,
      );
      expect(
        find.textContaining('Nothing has been sent to the server yet'),
        findsOneWidget,
      );
      expect(find.text('Slab shuttering on the third floor.'), findsOneWidget);
      expect(find.text('1 row'), findsOneWidget);
      expect(find.text('12 people'), findsOneWidget);
      expect(repo.createCalls, 0);

      await tapIn(
        tester,
        find.byKey(const ValueKey('daily-report-draft-start-over')),
      );
      await advance(tester);

      expect(
        find.byKey(const ValueKey('daily-report-draft-notice')),
        findsNothing,
      );
      expect(drafts.clearCalls, greaterThan(0));
      expect(find.text('1 row'), findsOneWidget);
      expect(find.text('0 people'), findsOneWidget);
    });
  });
}
