import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/expenses/domain/expense_receipt.dart';

ExpenseReceipt receipt({
  String mime = 'image/jpeg',
  bool isImage = true,
  bool isPdf = false,
  int bytes = 245760,
  String name = 'IMG_0142.jpg',
}) => ExpenseReceipt.fromJson(<String, dynamic>{
  'id': 5,
  'expense_id': 4,
  'original_name': name,
  'mime_type': mime,
  'size_bytes': bytes,
  'is_image': isImage,
  'is_pdf': isPdf,
  'uploaded_by': 7,
  'created_at': '2026-09-28T09:15:00Z',
});

void main() {
  group('what a receipt payload is allowed to carry', () {
    test('it carries ids, labels, size and type — and no storage path', () {
      // `ExpenseReceipt` has no `path` field, so no JSON can populate one:
      // the only route back to the bytes is the id-based, policy-checked
      // endpoint. A server that *did* send `path` would have it dropped
      // here, which is the cheapest possible proof that no screen could
      // render a location it was never given.
      final row = ExpenseReceipt.fromJson(<String, dynamic>{
        'id': 5,
        'expense_id': 4,
        'original_name': 'IMG_0142.jpg',
        'mime_type': 'image/jpeg',
        'size_bytes': 245760,
        'is_image': true,
        'is_pdf': false,
        'uploaded_by': 7,
        'created_at': '2026-09-28T09:15:00Z',
        'path': 'expense-receipts/4/secret.pdf',
      });

      expect(row.id, 5);
      expect(row.expenseId, 4);
      expect(row.originalName, 'IMG_0142.jpg');
      expect(row.mimeType, 'image/jpeg');
      expect(row.sizeBytes, 245760);
      expect(row.uploadedBy, 7);
      expect(row.createdAt, '2026-09-28T09:15:00Z');
      expect('$row', isNot(contains('secret.pdf')));
    });
  });

  group('what the screen may do with it', () {
    test('only an image is drawn inline; a document is handed to the '
        'platform', () {
      expect(receipt().canPreview, isTrue);
      expect(
        receipt(
          mime: 'application/pdf',
          isImage: false,
          isPdf: true,
        ).canPreview,
        isFalse,
      );
    });

    test('the size reads in the unit a person compares', () {
      expect(receipt(bytes: 512).sizeLabel, '512 B');
      expect(receipt(bytes: 245760).sizeLabel, '240.0 KB');
      expect(receipt(bytes: 1572864).sizeLabel, '1.5 MB');
    });

    test('a row with no name still draws as something', () {
      final nameless = ExpenseReceipt.fromJson(<String, dynamic>{
        'id': 6,
        'expense_id': 4,
        'original_name': '',
        'mime_type': 'image/jpeg',
        'size_bytes': 10,
        'is_image': true,
        'is_pdf': false,
      });

      expect(nameless.originalName, isEmpty);
      expect(nameless.sizeLabel, '10 B');
    });
  });
}
