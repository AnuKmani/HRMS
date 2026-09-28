import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/site_reports/data/report_draft_store.dart';
import 'package:mobile/features/site_reports/domain/site_activity_report.dart';
import 'package:mobile/features/site_reports/presentation/site_activity_form_screen.dart';

import '../../../support/attendance.dart';
import '../../../support/site_reports.dart';

/// Today, as the date field will have written it.
String get _today {
  final now = DateTime.now();

  return '${now.year.toString().padLeft(4, '0')}-'
      '${now.month.toString().padLeft(2, '0')}-'
      '${now.day.toString().padLeft(2, '0')}';
}

void main() {
  late ScriptedSiteActivityReports repo;
  late MemoryReportDraftStore drafts;

  setUp(() {
    repo = ScriptedSiteActivityReports(
      idOf: (report) => report.id,
      items: <SiteActivityReport>[testActivityReport()],
    );
    drafts = MemoryReportDraftStore();
  });

  Future<GoRouter> open(
    WidgetTester tester, {
    int? reportId,
    List<String> permissions = const ['site_activity_reports.create'],
  }) async {
    useTallScreen(tester);

    final location = reportId == null
        ? '/site-reports/new'
        : '/site-reports/$reportId/edit';

    final router = siteReportsRouter(
      location: location,
      screen: reportId == null
          ? const SiteActivityFormScreen()
          : SiteActivityFormScreen(reportId: reportId),
    );

    await tester.pumpWidget(
      scopedSiteReports(
        permissions: permissions,
        siteActivity: repo,
        drafts: drafts,
        child: MaterialApp.router(routerConfig: router),
      ),
    );

    await advance(tester);

    return router;
  }

  /// Everything a valid report needs, minus whatever the test is about.
  Future<void> fillValid(WidgetTester tester) async {
    await chooseSite(tester);
    await pickDate(tester, const ValueKey('site-report-date'));
    await type(tester, const ValueKey('site-report-work-category'), 'RCC');
    await type(
      tester,
      const ValueKey('site-report-work-performed'),
      'Slab pour on the third floor.',
    );
  }

  group('getting in', () {
    testWidgets('a session that may not create a report is never shown the '
        'form', (tester) async {
      await open(tester, permissions: const ['site_activity_reports.view']);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.findCalls, 0);
      expect(find.byKey(const ValueKey('save-site-report')), findsNothing);
      expect(find.byKey(const ValueKey('submit-site-report')), findsNothing);
    });

    testWidgets('the form cannot be corrected without the permission that '
        'would be refused a second later', (tester) async {
      await open(
        tester,
        reportId: 1,
        permissions: const ['site_activity_reports.create'],
      );

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.findCalls, 0);
    });

    testWidgets('a refusal while saving lands as the permission screen, not '
        'as a silent no-op', (tester) async {
      await open(tester);
      await fillValid(tester);

      repo.saveError = forbidden403;

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
      expect(repo.createCalls, 1);
    });
  });

  group('what the server is asked', () {
    testWidgets('an empty form refuses to save and says which four things '
        'are missing', (tester) async {
      await open(tester);

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(
        find.text('Choose the site this report is about.'),
        findsOneWidget,
      );
      expect(find.text('Choose the day this work happened.'), findsOneWidget);
      expect(find.text('Say what kind of work this was.'), findsOneWidget);
      expect(find.text('Describe the work that was done.'), findsOneWidget);
      expect(repo.createCalls, 0);
      expect(repo.lastBody, isNull);
    });

    testWidgets('a work category nobody could read is refused on the spot', (
      tester,
    ) async {
      await open(tester);
      await type(
        tester,
        const ValueKey('site-report-work-category'),
        'a'.padRight(61, 'b'),
      );

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(find.text('Keep this to 60 characters or fewer.'), findsOneWidget);
      expect(repo.createCalls, 0);
    });

    testWidgets('the project comes from the site, and the author never '
        'leaves the phone', (tester) async {
      await open(tester);
      await fillValid(tester);

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(repo.createCalls, 1);

      final body = repo.lastBody!;
      expect(body['site_id'], 1);
      expect(body['project_id'], 10);
      expect(body['report_date'], _today);
      expect(body['progress_percentage'], 0);
      expect(body, isNot(contains('employee_id')));
      expect(body, isNot(contains('status')));

      // Saved, then walked to the report it created.
      expect(find.text('Report saved as a draft.'), findsOneWidget);
      expect(find.text('stub /site-reports/1'), findsOneWidget);
      expect(drafts.clearCalls, greaterThan(0));
    });

    testWidgets('a server-side draft is corrected with PUT, never with a '
        'second POST', (tester) async {
      await open(
        tester,
        reportId: 1,
        permissions: const ['site_activity_reports.update'],
      );
      await advance(tester);

      // Loaded from the server rather than restored from the phone.
      expect(repo.findCalls, 1);
      expect(find.text('Edit site report'), findsOneWidget);
      expect(
        tester
            .widget<EditableText>(
              find.descendant(
                of: find.byKey(const ValueKey('site-report-work-performed')),
                matching: find.byType(EditableText),
              ),
            )
            .controller
            .text,
        'Slab pour on the third floor.',
      );

      await type(
        tester,
        const ValueKey('site-report-work-category'),
        'Blockwork',
      );
      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(repo.updateCalls, 1);
      expect(repo.createCalls, 0);
      expect(repo.lastId, 1);
      expect(repo.lastBody!['work_category'], 'Blockwork');
      // Editing an existing report must not touch the phone's local copy.
      expect(drafts.writeCalls, 0);
      expect(drafts.clearCalls, 0);
    });

    testWidgets('the server\'s field error lands on the field, and the text '
        'nobody should lose is still there', (tester) async {
      await open(tester);
      await fillValid(tester);

      repo.saveError = const ApiException(
        statusCode: 422,
        message: 'The report could not be saved.',
        errors: <String, String>{
          'report_date':
              'Somebody has already reported that site for that day.',
        },
      );

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(
        find.text('Somebody has already reported that site for that day.'),
        findsOneWidget,
      );
      expect(find.text('Slab pour on the third floor.'), findsOneWidget);
      expect(drafts.writeCalls, greaterThan(0));
      expect(find.byKey(const ValueKey('no-permission')), findsNothing);
    });
  });

  group('progress', () {
    testWidgets('is a whole-number slider that cannot leave 0 to 100', (
      tester,
    ) async {
      await open(tester);

      final value = find.byKey(const ValueKey('site-report-progress-value'));
      expect(tester.widget<Text>(value).data, '0%');

      await tester.drag(
        find.byKey(const ValueKey('site-report-progress')),
        const Offset(4000, 0),
      );
      await tester.pump();
      expect(tester.widget<Text>(value).data, '100%');

      await tester.drag(
        find.byKey(const ValueKey('site-report-progress')),
        const Offset(-4000, 0),
      );
      await tester.pump();
      expect(tester.widget<Text>(value).data, '0%');
    });

    testWidgets('travels to the server as the number on screen', (
      tester,
    ) async {
      await open(tester);
      await fillValid(tester);

      await tester.drag(
        find.byKey(const ValueKey('site-report-progress')),
        const Offset(4000, 0),
      );
      await tester.pump();

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(repo.lastBody!['progress_percentage'], 100);
    });
  });

  group('the location reading', () {
    testWidgets('is refused before submit, and the refusal is on screen', (
      tester,
    ) async {
      await open(tester);
      await fillValid(tester);

      await tapIn(tester, find.byKey(const ValueKey('submit-site-report')));
      await advance(tester);

      expect(
        find.textContaining('A location reading is needed'),
        findsOneWidget,
      );
      expect(repo.submitCalls, 0);
      expect(repo.createCalls, 0);
    });

    testWidgets('travels with the submission, taken at that moment', (
      tester,
    ) async {
      await open(tester);
      await fillValid(tester);

      await tapIn(tester, find.byKey(const ValueKey('report-gps-capture')));
      await advance(tester);

      expect(find.byKey(const ValueKey('report-gps-taken')), findsOneWidget);
      expect(find.textContaining('±9 m accuracy'), findsOneWidget);

      await tapIn(tester, find.byKey(const ValueKey('submit-site-report')));
      await advance(tester);

      expect(repo.submitCalls, 1);
      expect(repo.lastSubmitId, 1);
      expect(repo.lastLatitude, 12.9716);
      expect(repo.lastLongitude, 77.5946);
      expect(repo.lastAccuracy, 9);
      expect(find.text('stub /site-reports/1'), findsOneWidget);
    });

    testWidgets('a phone that cannot get a reading says which button would '
        'help', (tester) async {
      final location = ScriptedLocation()..serviceEnabled = false;

      useTallScreen(tester);

      await tester.pumpWidget(
        scopedSiteReports(
          permissions: const ['site_activity_reports.create'],
          siteActivity: repo,
          drafts: drafts,
          location: location,
          child: MaterialApp.router(
            routerConfig: siteReportsRouter(
              location: '/site-reports/new',
              screen: const SiteActivityFormScreen(),
            ),
          ),
        ),
      );
      await advance(tester);

      await tapIn(tester, find.byKey(const ValueKey('report-gps-capture')));
      await advance(tester);

      expect(find.text('Turn on GPS'), findsOneWidget);
      expect(find.byKey(const ValueKey('report-gps-taken')), findsNothing);
    });
  });

  group('photographs', () {
    /// Opens the camera, takes one frame and accepts it.
    Future<void> takePhoto(WidgetTester tester) async {
      await tapIn(tester, find.byKey(const ValueKey('report-photo-add')));
      await tester.pumpAndSettle();

      expect(find.text('Site photograph'), findsOneWidget);

      await tapIn(tester, find.byType(FloatingActionButton));
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('Retake'), findsOneWidget);

      await tapIn(tester, find.text('Use photo'));
      await tester.pumpAndSettle();
    }

    testWidgets('waits on this phone until the report exists, then goes up '
        'as one batch', (tester) async {
      await open(tester);

      expect(find.byKey(const ValueKey('report-photos-empty')), findsOneWidget);

      await takePhoto(tester);

      expect(
        find.byKey(const ValueKey('report-photo-pending-0')),
        findsOneWidget,
      );
      expect(find.text('1 of 12'), findsOneWidget);
      // Nothing has been sent: there is no report to attach it to yet.
      expect(repo.addPhotosCalls, 0);

      await fillValid(tester);
      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(repo.addPhotosCalls, 1);
      expect(repo.lastPhotosFor, 1);
      expect(repo.lastPhotoCount, 1);
      expect(repo.lastPhotos!.single, isNotEmpty);
      // The screen walked to the report once the batch was away, which is
      // the only sign of "attached" observable from here: the strip lives
      // on the form it has just left.
      expect(find.text('stub /site-reports/1'), findsOneWidget);
    });

    testWidgets('a frame that has not been sent can be dropped with no '
        'confirmation at all', (tester) async {
      await open(tester);
      await takePhoto(tester);

      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('report-photo-pending-0')),
          matching: find.byIcon(Icons.close),
        ),
      );
      await tester.pump();

      expect(
        find.byKey(const ValueKey('report-photo-pending-0')),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('report-photos-empty')), findsOneWidget);
      expect(find.text('Remove this photograph?'), findsNothing);
    });

    testWidgets('an upload that fails says the report is there and the '
        'frames are not', (tester) async {
      await open(tester);
      await takePhoto(tester);
      await fillValid(tester);

      repo.photosError = const ApiException(
        statusCode: 422,
        message: 'That file is not an accepted image.',
      );

      await tapIn(tester, find.byKey(const ValueKey('save-site-report')));
      await advance(tester);

      expect(find.text('That file is not an accepted image.'), findsOneWidget);
      // The report itself was created before the frames were attempted.
      expect(repo.createCalls, 1);
    });
  });

  group('the local draft', () {
    testWidgets('is written after a pause, not after every keystroke', (
      tester,
    ) async {
      await open(tester);

      await type(
        tester,
        const ValueKey('site-report-work-performed'),
        'Left wall taken up to sill level.',
      );
      expect(drafts.writeCalls, 0);

      await advance(tester);

      expect(drafts.writeCalls, 1);
      expect(drafts.lastSlot, activityDraftSlot);
      expect(
        drafts.lastDraft!['work_performed'],
        'Left wall taken up to sill level.',
      );
      expect(drafts.lastDraft!.keys, isNot(contains('photos')));
    });

    testWidgets('comes back on the next visit and says it is local, and can '
        'be thrown away', (tester) async {
      drafts.slots[activityDraftSlot] = <String, Object?>{
        'site_id': 1,
        'site_name': 'Block A',
        'project_id': 10,
        'report_date': '2026-09-28',
        'work_category': 'Blockwork',
        'work_performed': 'Left wall taken up to sill level.',
        'progress': 40,
        'manpower': '',
        'materials_used': '',
        'equipment_used': '',
        'issues': '',
        'safety_issues': '',
        'remarks': '',
      };

      await open(tester);

      expect(
        find.byKey(const ValueKey('site-report-draft-notice')),
        findsOneWidget,
      );
      expect(
        find.textContaining('Nothing has been sent to the server yet'),
        findsOneWidget,
      );
      expect(find.text('Left wall taken up to sill level.'), findsOneWidget);
      expect(find.text('40%'), findsOneWidget);
      expect(repo.createCalls, 0);

      await tapIn(
        tester,
        find.byKey(const ValueKey('site-report-draft-start-over')),
      );
      await advance(tester);

      expect(
        find.byKey(const ValueKey('site-report-draft-notice')),
        findsNothing,
      );
      expect(drafts.clearCalls, greaterThan(0));
      expect(
        tester
            .widget<EditableText>(
              find.descendant(
                of: find.byKey(const ValueKey('site-report-work-performed')),
                matching: find.byType(EditableText),
              ),
            )
            .controller
            .text,
        isEmpty,
      );
    });
  });
}
