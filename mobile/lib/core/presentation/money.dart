import 'package:flutter/widgets.dart';

/// One place every figure in this app is turned into text.
///
/// Money arrives from the API as a decimal **string** (`"30000.00"`), never as
/// a JSON number: `PayrollResource` documents why, and this class is the other
/// half of the same decision. Turning `30000.00` into a Dart `double` and
/// printing it with `toStringAsFixed` would re-round a figure the server had
/// already settled, and two screens would then disagree about a payslip by a
/// cent — which on a payroll screen is a bug a person will notice.
///
/// So the round-trip is: parse the decimal string into an integer of *minor
/// units* (paise), format those, and never hold a `double` at all. The parsing
/// rule is **half away from zero**, matching PHP's `round()` in `App\Support\Money`,
/// so the app and the PDF agree on `0.125 → 0.13`.
///
/// `Money.format()` is the only function allowed to print a currency figure in
/// this codebase. A screen that wants `"₹ 30,000"` written by hand can, and it
/// will be wrong the first time the organisation's `system.currency` changes.
class Money {
  const Money._();

  /// Decimal places the server and this formatter both work to.
  static const int scale = 2;

  static const int _minorFactor = 100;

  /// A decimal string in, an integer of minor units out.
  ///
  /// `null`, `''` and anything unparsable are `0` rather than an exception: a
  /// figure that failed to arrive must not take the screen showing it down
  /// with it, and `0.00` is the honest rendering of "nothing was said".
  static int toMinorUnits(Object? value) {
    if (value == null) return 0;

    var text = value.toString().trim();

    if (text.isEmpty) return 0;

    var negative = false;

    if (text.startsWith('-')) {
      negative = true;
      text = text.substring(1);
    } else if (text.startsWith('+')) {
      text = text.substring(1);
    }

    final dot = text.indexOf('.');
    final before = dot < 0 ? text : text.substring(0, dot);
    final after = dot < 0 ? '' : text.substring(dot + 1);

    final whole = _digits(before);
    final fraction = _digits(after);

    if (whole.isEmpty && fraction.isEmpty) return 0;

    var units = (int.tryParse(whole.isEmpty ? '0' : whole) ?? 0) * _minorFactor;

    // The two digits that survive; everything past them is a rounding
    // decision, not a digit worth keeping.
    final padded = fraction.padRight(scale, '0');

    units += int.tryParse(padded.substring(0, scale)) ?? 0;

    if (fraction.length > scale) {
      // Half away from zero: `0.125` becomes `0.13`, `0.124` stays `0.12`,
      // and the sign of the number never changes which way it goes.
      if (fraction.codeUnitAt(scale) >= 0x35) units += 1;
    }

    return negative ? -units : units;
  }

  /// `30000.00` → `INR 30,000.00`.
  ///
  /// The currency code comes from the row that arrived, not from a constant
  /// here, so a company that changes `system.currency` gets a payslip with the
  /// new code on it without an app release.
  static String format(
    Object? value, {
    String currency = 'INR',
    bool withCurrency = true,
  }) => _fromMinorUnits(
    toMinorUnits(value),
    currency: currency,
    withCurrency: withCurrency,
  );

  /// The same figure without the currency code — for a column of values that
  /// are all the same currency and would read `INR` forty times over.
  static String plain(Object? value) => format(value, withCurrency: false);

  /// The sign, for a delta where the colour carries the meaning and the text
  /// must still say which way it went.
  static String delta(Object? value, {String currency = 'INR'}) {
    final units = toMinorUnits(value);

    if (units == 0) return '$currency 0.00';

    // Built from the minor units directly rather than by formatting the
    // *count* of them — `format(1300)` would render 1,300.00, and the whole
    // point of keeping one integer around is that nothing can mistake paise
    // for rupees on the way back out.
    final amount = _fromMinorUnits(units.abs(), withCurrency: false);

    return units > 0 ? '+$amount' : '-$amount';
  }

  /// Minor units as text — the last step, and the only one that knows about
  /// the decimal point.
  static String _fromMinorUnits(
    int units, {
    String currency = 'INR',
    bool withCurrency = true,
  }) {
    final sign = units < 0 ? '-' : '';
    final digits = units.abs().toString().padLeft(scale + 1, '0');
    final whole = digits.substring(0, digits.length - scale);
    final cents = digits.substring(digits.length - scale);

    final amount = '${_group(whole)}.$cents';

    if (!withCurrency || currency.isEmpty) return '$sign$amount';

    return '$sign$currency $amount';
  }

  static String _digits(String text) {
    if (text.isEmpty) return '';

    final buffer = StringBuffer();

    for (var i = 0; i < text.length; i++) {
      final code = text.codeUnitAt(i);

      if (code >= 0x30 && code <= 0x39) buffer.writeCharCode(code);
    }

    return buffer.toString();
  }

  /// Thousands separators, three digits at a time from the right.
  static String _group(String whole) {
    if (whole.length <= 3) return whole;

    final buffer = StringBuffer();

    for (var i = 0; i < whole.length; i++) {
      if (i > 0 && (whole.length - i) % 3 == 0) buffer.write(',');
      buffer.write(whole[i]);
    }

    return buffer.toString();
  }
}

/// A figure and its currency code, rendered the one way this app renders them.
///
/// A [StatelessWidget] rather than a top-level function so it can be given a
/// `style` — a net salary on a payslip is large and a deduction is small, and
/// the size belongs to the caller, the *text* never does.
class MoneyText extends StatelessWidget {
  const MoneyText(
    this.value, {
    super.key,
    this.currency = 'INR',
    this.withCurrency = true,
    this.style,
    this.textAlign,
  });

  /// The raw value as the API sent it: a decimal string, a number, or null.
  final Object? value;

  final String currency;
  final bool withCurrency;
  final TextStyle? style;
  final TextAlign? textAlign;

  @override
  Widget build(BuildContext context) => Text(
    Money.format(value, currency: currency, withCurrency: withCurrency),
    style: style,
    textAlign: textAlign,
  );
}
