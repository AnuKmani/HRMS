import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/presentation/money.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';

void main() {
  group('Money.format', () {
    test('renders a decimal string with its group separators and code', () {
      expect(Money.format('30000.00'), 'INR 30,000.00');
      expect(Money.format('1000000'), 'INR 1,000,000.00');
      expect(Money.format('1234.5'), 'INR 1,234.50');
    });

    test('takes the currency code from the row, never from a constant', () {
      // `system.currency` is a setting; a screen that printed `INR` from a
      // literal would show the old code on a payslip the moment it changed.
      expect(Money.format('30000.00', currency: 'AED'), 'AED 30,000.00');
      expect(Money.format('30000.00', withCurrency: false), '30,000.00');
    });

    test('treats nothing-arrived as zero rather than as a crash', () {
      expect(Money.format(null), 'INR 0.00');
      expect(Money.format(''), 'INR 0.00');
      expect(Money.format('not a number'), 'INR 0.00');
    });

    test('rounds half away from zero, the way the PDF does', () {
      expect(Money.format('0.125'), 'INR 0.13');
      expect(Money.format('0.124'), 'INR 0.12');
      expect(Money.format('1234.567'), 'INR 1,234.57');

      // The sign never pulls a half towards zero — `-0.125` and `0.125`
      // travel the same distance, which is what PHP's round() does too.
      expect(Money.format('-0.125'), '-INR 0.13');
      expect(Money.format('-0.124'), '-INR 0.12');
    });

    test('keeps negative figures negative instead of swallowing the sign', () {
      expect(Money.format('-4500'), '-INR 4,500.00');
      expect(Money.plain('-0.05'), '-0.05');
    });
  });

  group('Money.plain', () {
    test('drops the code for a column of same-currency figures', () {
      expect(Money.plain('1234.5'), '1,234.50');
      expect(Money.plain('0'), '0.00');
    });
  });

  group('Money.delta', () {
    test('says which way it went', () {
      expect(Money.delta('12.34'), '+12.34');
      expect(Money.delta('-12.34'), '-12.34');
      expect(Money.delta('0'), 'INR 0.00');
    });
  });

  testWidgets('MoneyText draws the one string the formatter produces', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: MoneyText('30000.00', currency: 'AED')),
      ),
    );

    expect(find.text('AED 30,000.00'), findsOneWidget);
  });

  group('safePdfFilename', () {
    test('strips anything a path could be made of', () {
      final cleaned = safePdfFilename('../../etc/passwd');

      expect(cleaned, isNot(contains('/')));
      expect(cleaned, isNotEmpty);
    });

    test('falls back rather than emptying to a directory', () {
      expect(safePdfFilename('..'), 'document.pdf');
      expect(safePdfFilename(''), 'document.pdf');
    });

    test('keeps an ordinary generated name intact', () {
      expect(
        safePdfFilename('salary-slip-2026-09.pdf'),
        'salary-slip-2026-09.pdf',
      );
    });
  });
}
