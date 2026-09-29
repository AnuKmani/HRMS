import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/expenses/domain/expense_category.dart';

ExpenseCategory category({
  bool requiresReceipt = false,
  String? maximumAmount,
  String status = 'active',
}) => ExpenseCategory.fromJson(<String, dynamic>{
  'id': 3,
  'name': 'Food',
  'code': 'FOOD',
  'description': 'Meals while travelling or on site.',
  'status': status,
  'requires_receipt': requiresReceipt,
  'maximum_amount': maximumAmount,
});

void main() {
  group('the two rules a claim has to satisfy', () {
    test('a category that demands paper says so in the picker', () {
      final needsPaper = category(requiresReceipt: true);

      expect(needsPaper.requiresReceipt, isTrue);
      expect(needsPaper.ruleSummary, 'Needs a receipt');
    });

    test(
      'a ceiling is shown as the figure the server will compare against',
      () {
        final capped = category(maximumAmount: '1000.00');

        expect(capped.maximumAmount, '1000.00');
        expect(capped.ruleSummary, 'Up to 1000.00');
      },
    );

    test('both rules together read as one line', () {
      final both = category(requiresReceipt: true, maximumAmount: '500.00');

      expect(both.ruleSummary, 'Needs a receipt · Up to 500.00');
    });

    test('a category with neither rule says nothing rather than '
        'something misleading', () {
      final free = category();

      expect(free.ruleSummary, isEmpty);
      expect(free.maximumAmount, isNull);
    });

    test('no ceiling is not a ceiling of zero', () {
      // `null` means the server skips the check entirely; `0.00` would
      // refuse every claim. The form must never render them the same way,
      // so the model keeps the distinction as it arrives.
      final uncapped = category(maximumAmount: null);

      expect(uncapped.maximumAmount, isNull);
      expect(uncapped.ruleSummary, isEmpty);
    });
  });

  group('what the form will offer', () {
    test('a retired category is not active, and so not pickable', () {
      expect(category(status: 'active').isActive, isTrue);
      expect(category(status: 'inactive').isActive, isFalse);
    });

    test('the vocabulary arrives with the code a person recognises', () {
      final row = category();

      expect(row.code, 'FOOD');
      expect(row.name, 'Food');
      expect(row.description, 'Meals while travelling or on site.');
    });

    test('an absent status is read as inactive rather than active', () {
      final row = ExpenseCategory.fromJson(<String, dynamic>{
        'id': 1,
        'name': 'Other',
        'code': 'OTHER',
      });

      expect(row.isActive, isFalse);
    });
  });
}
