import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';
import 'package:mobile/features/site_reports/domain/site_activity_report.dart';
import 'package:mobile/features/site_reports/domain/site_report_photo.dart';
import 'package:mobile/features/site_reports/presentation/report_pdf_opener.dart';

import '../../../support/site_reports.dart';

void main() {
  group('the activity report', () {
    test('the author is read for display and never written back', () {
      final report = testActivityReport();

      expect(report.employeeId, 1);
      expect(report.employeeName, 'Anu Kmani');

      // The server derives the author from the bearer token. A response
      // that omits it is not a response that fails to parse — there is no
      // form with a field for this, so nothing downstream needs the id to
      // be present in order to work.
      final anonymous = SiteActivityReport.fromJson(<String, dynamic>{
        'id': 4,
        'report_date': '2026-09-28',
        'work_category': 'RCC',
        'work_performed': 'Slab pour on the third floor.',
        'status': 'draft',
        'is_draft': true,
        'is_editable': true,
      });

      expect(anonymous.employeeId, 0);
      expect(anonymous.employeeName, isNull);
      expect(anonymous.workCategory, 'RCC');
    });

    test('a location reading counts only when all three arrived together', () {
      expect(testActivityReport(hasGps: true).hasUsableGps, isTrue);
      expect(testActivityReport().hasUsableGps, isFalse);

      // `has_gps_fix` is the server's opinion; `hasUsableGps` is what the
      // detail screen prints coordinates from. A half-populated fix is one
      // the screen must describe as "Not recorded" rather than print as
      // `12.97160, null`.
      final partial = SiteActivityReport.fromJson(<String, dynamic>{
        'id': 1,
        'has_gps_fix': true,
        'latitude': 12.9716,
        'longitude': 77.5946,
      });

      expect(partial.hasGpsFix, isTrue);
      expect(partial.hasUsableGps, isFalse);
    });

    test(
      'draft and submitted are the server\'s labels, not a form\'s field',
      () {
        expect(testActivityReport().statusLabel, 'Draft');
        expect(testActivityReport().isDraft, isTrue);
        expect(testActivityReport().isEditable, isTrue);

        final filed = testActivityReport(
          status: SiteActivityReport.statusSubmitted,
        );

        expect(filed.statusLabel, 'Submitted');
        expect(filed.isSubmitted, isTrue);
        expect(filed.isDraft, isFalse);
        expect(filed.submittedAt, '2026-09-28 18:05:00');
      },
    );
  });

  group('the daily report', () {
    test('the total shown prefers the rows it can add up', () {
      expect(testDailyReport().displayTotalManpower, 18);

      // A list response omits the children, so the stored figure has to
      // stand in — and it is the figure the service derived from exactly
      // these rows when the document was written.
      final listRow = DailySiteReport.fromJson(<String, dynamic>{
        'id': 2,
        'report_date': '2026-09-28',
        'total_manpower': 42,
        'manpower': <dynamic>[],
        'status': 'draft',
      });

      expect(listRow.manpower, isEmpty);
      expect(listRow.displayTotalManpower, 42);
    });

    test('a workforce category is a counted label and nothing more', () {
      final row = ManpowerRow.fromJson(<String, dynamic>{
        'category': 'Shuttering hands',
        'count': 4,
      });

      expect(row.category, 'Shuttering hands');
      expect(row.count, 4);
    });

    test('a quantity reads the same way whichever report prints it', () {
      // The server casts to three decimals, so `toString()` would spell
      // the same quantity `20.000` beside `2`.
      expect(
        MaterialRow.fromJson(<String, dynamic>{
          'material_name': 'Cement',
          'quantity': '20.000',
          'unit': 'bags',
        }).quantityLabel,
        '20',
      );

      expect(
        EquipmentRow.fromJson(<String, dynamic>{
          'equipment_name': 'Concrete pump',
          'quantity': '2.5',
        }).quantityLabel,
        '2.5',
      );

      // A pump with no meter reading is not a pump with zero hours.
      expect(
        EquipmentRow.fromJson(<String, dynamic>{
          'equipment_name': 'Tower crane',
          'quantity': 1,
        }).operatingHours,
        isNull,
      );
    });
  });

  group('photographs', () {
    test('a photo knows the endpoint, never the file', () {
      expect(
        SiteReportPhoto.pathFor('/site-activity-reports', 7, 901),
        '/site-activity-reports/7/photos/901',
      );
      expect(
        SiteReportPhoto.pathFor('/daily-site-reports', 3, 12),
        '/daily-site-reports/3/photos/12',
      );

      // Unknown keys are ignored rather than surfaced: the server sends
      // ids, sizes and a mime type, and anything else it might one day add
      // does not become a property a screen can render by accident.
      final photo = SiteReportPhoto.fromJson(<String, dynamic>{
        'id': 901,
        'caption': 'The work front',
        'mime_type': 'image/jpeg',
        'size_bytes': 2048,
        'path': '/var/www/private/site-report-photos/1/a.jpg',
        'url': 'https://example.invalid/a.jpg',
      });

      expect(photo.id, 901);
      expect(photo.caption, 'The work front');
      expect(photo.mimeType, 'image/jpeg');
      expect(photo.sizeBytes, 2048);
    });

    test('anything that is not a row is dropped rather than guessed at', () {
      expect(photosFrom(<dynamic>['junk', 3]), isEmpty);
      expect(photosFrom(null), isEmpty);
      expect(
        photosFrom(<dynamic>[
          <String, dynamic>{'id': 1},
        ]).single.id,
        1,
      );
    });
  });

  group('the download name', () {
    test('carries the report\'s own day, not the clock\'s', () {
      expect(
        reportPdfFilename(3, '2026-09-28'),
        'daily-site-report-3-28092026.pdf',
      );
      expect(
        reportPdfFilename(12, '2026-12-01'),
        'daily-site-report-12-01122026.pdf',
      );
    });

    test('degrades to the id rather than to a name built from nonsense', () {
      expect(reportPdfFilename(3, 'soon'), 'daily-site-report-3.pdf');
      expect(reportPdfFilename(3, ''), 'daily-site-report-3.pdf');
    });
  });
}
