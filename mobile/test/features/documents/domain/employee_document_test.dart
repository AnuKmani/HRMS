import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/documents/domain/employee_document.dart';

import '../../../support/phase10.dart';

/// Scope item U — every expiry label carries its meaning in words — and the
/// rule that the server's `expiry_state` and `days_until_expiry` are rendered
/// rather than re-derived here.
void main() {
  group('the expiry label', () {
    test('a document with no date says so instead of counting', () {
      final document = EmployeeDocument.fromJson(
        documentRow(expiry: null, expiryState: EmployeeDocument.expiryNone),
      );

      expect(document.expiryText, 'No expiry date');
    });

    test('the sign of the day count decides which way the words point', () {
      final soon = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-10-06',
          expiryState: EmployeeDocument.expirySoon,
          daysUntil: 5,
        ),
      );
      final lapsed = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-09-19',
          expiryState: EmployeeDocument.expiryExpired,
          daysUntil: -12,
        ),
      );

      expect(soon.expiryText, 'Expires in 5 days');
      expect(lapsed.expiryText, 'Expired 12 days ago');
    });

    test('one day and today are spelled out rather than counted', () {
      final tomorrow = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-10-02',
          expiryState: EmployeeDocument.expirySoon,
          daysUntil: 1,
        ),
      );
      final today = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-10-01',
          expiryState: EmployeeDocument.expirySoon,
          daysUntil: 0,
        ),
      );
      final yesterday = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-09-30',
          expiryState: EmployeeDocument.expiryExpired,
          daysUntil: -1,
        ),
      );

      // "Expires in 1 days" reads as a bug and makes the reader distrust the
      // number beside it.
      expect(tomorrow.expiryText, 'Expires tomorrow');
      expect(today.expiryText, 'Expires today');
      expect(yesterday.expiryText, 'Expired yesterday');
    });

    test('an unknown count falls back to the date rather than a number', () {
      final document = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-12-31',
          expiryState: EmployeeDocument.expiryValid,
          daysUntil: null,
        ),
      );

      expect(document.expiryText, 'Expires 2026-12-31');
    });

    test('the words come from the server\'s state, not from today\'s date', () {
      // A `valid` document whose date passed yesterday reports `expired`
      // from the payload — this screen never recomputes it, so a scheduler
      // that has not run cannot make the app tell a lie in either direction.
      final document = EmployeeDocument.fromJson(
        documentRow(
          expiry: '2026-01-01',
          expiryState: EmployeeDocument.expiryValid,
          daysUntil: 5,
        ),
      );

      expect(document.expiryText, 'Expires in 5 days');
      expect(document.expiryDate, '2026-01-01');
    });
  });

  group('status', () {
    test('each stored status has its own sentence', () {
      String textOf(String status) =>
          EmployeeDocument.fromJson(documentRow(status: status)).statusText;

      expect(textOf(EmployeeDocument.statusPending), 'Awaiting verification');
      expect(textOf(EmployeeDocument.statusValid), 'Verified');
      expect(textOf(EmployeeDocument.statusExpired), 'Expired');
      expect(textOf(EmployeeDocument.statusRejected), 'Rejected');
      expect(textOf(EmployeeDocument.statusArchived), 'Archived');
    });

    test('the two facts stay separate: a verified document can be lapsed', () {
      final document = EmployeeDocument.fromJson(
        documentRow(
          status: EmployeeDocument.statusValid,
          expiryState: EmployeeDocument.expiryExpired,
          daysUntil: -3,
        ),
      );

      expect(document.statusText, 'Verified');
      expect(document.expiryText, 'Expired 3 days ago');
    });
  });

  group('the payload', () {
    test('carries a file route and never a stored path', () {
      final document = EmployeeDocument.fromJson(documentRow(id: 12));

      expect(document.hasFile, isTrue);
      expect(document.fileUrl, '/api/v1/employee-documents/12/file');

      // The whole private-storage approach rests on this: nothing in the
      // payload names a place on the server's disk.
      final keys = documentRow(id: 12).keys.join(' ');
      expect(keys.contains('path'), isFalse);
      expect(keys.contains('disk'), isFalse);
    });

    test('a row with no file says so rather than offering one', () {
      final document = EmployeeDocument.fromJson(documentRow(hasFile: false));

      expect(document.hasFile, isFalse);
      expect(document.fileUrl, isNull);
      expect(document.sizeLabel, 'No file attached');
    });

    test('sizes are printed in the unit a person recognises', () {
      String sizeOf(int bytes) =>
          EmployeeDocument.fromJson(documentRow(fileSize: bytes)).sizeLabel;

      expect(sizeOf(512), '512 B');
      expect(sizeOf(245 * 1024), '245 KB');
      expect(sizeOf(3 * 1024 * 1024 + 146800), '3.1 MB');
    });

    test('an image is decided from type and name, not extension alone', () {
      expect(
        EmployeeDocument.fromJson(documentRow(mime: 'image/png')).isImage,
        isTrue,
      );
      expect(
        EmployeeDocument.fromJson(documentRow(mime: null, fileName: 'scan.PNG'))
            .isImage,
        isTrue,
      );
      expect(
        EmployeeDocument.fromJson(
          documentRow(mime: 'application/pdf', fileName: 'contract.pdf'),
        ).isImage,
        isFalse,
      );
    });
  });
}
