import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/config/client_settings.dart';
import 'package:mobile/core/network/api_client.dart';

import '../../support/site_reports.dart' show ScriptedApiClient;

/// `GET /client-settings`, parsed.
///
/// The two things a form depends on that are easy to get subtly wrong: that
/// the codes arrive normalised the way the *validator* spells them (a menu
/// offering `aed` and an API accepting only `AED` is a rejection waiting to
/// happen), and that a payload which is not the promised object is refused
/// rather than quietly turned into an empty configuration — an empty
/// configuration reads as "no currencies configured", which is a different
/// and much worse thing to believe.
void main() {
  ClientSettings parse(Object? data) => ClientSettings.fromJson(data);

  Map<String, dynamic> payload(Object? defaultCurrency, Object? supported) =>
      <String, dynamic>{
        'default_currency': defaultCurrency,
        'supported_currencies': supported,
      };

  test('the configured default and the supported codes are both read', () {
    final settings = parse(payload('AED', <Object?>['AED']));

    expect(settings.defaultCurrency, 'AED');
    expect(settings.supportedCurrencies, ['AED']);
    expect(settings.allowsCurrencyChoice, isFalse);
  });

  test('codes arrive the way the server spells them, once', () {
    final settings = parse(
      payload(' usd ', <Object?>[' aed', 'USD', '', 'eur', 'EUR']),
    );

    expect(settings.defaultCurrency, 'USD');
    expect(settings.supportedCurrencies, ['AED', 'USD', 'EUR']);
    expect(settings.supportedSummary, 'AED, USD, EUR');
    expect(settings.allowsCurrencyChoice, isTrue);
  });

  test('anything that is not a code is dropped rather than offered', () {
    final settings = parse(payload('AED', <Object?>['AED', 7, null, true]));

    expect(settings.supportedCurrencies, ['AED']);
  });

  test('a payload that is not the promised object is refused, not assumed', () {
    expect(() => parse(null), throwsFormatException);
    expect(() => parse(<Object?>['AED']), throwsFormatException);
    expect(() => parse('AED'), throwsFormatException);

    // An absent list is *not* a refusal: the endpoint always sends one, and
    // a missing key inside an otherwise valid object is a smaller problem
    // than throwing away the default that did arrive.
    expect(parse(payload('AED', null)).supportedCurrencies, isEmpty);
  });

  test('one configured currency is not a choice', () {
    expect(
      parse(payload('AED', <Object?>['AED'])).allowsCurrencyChoice,
      isFalse,
    );
    expect(parse(payload('AED', <Object?>[])).allowsCurrencyChoice, isFalse);
    expect(
      parse(payload('AED', <Object?>['AED', 'USD'])).allowsCurrencyChoice,
      isTrue,
    );
  });

  test(
    'the repository asks for exactly one path and parses what it gets',
    () async {
      final client = ScriptedApiClient(
        reply: ApiEnvelope(
          message: 'Client settings retrieved.',
          data: payload('AED', <Object?>['AED', 'USD']),
        ),
      );

      final settings = await ApiClientSettingsSource(client).load();

      expect(client.calls, hasLength(1));
      expect(client.last!.verb, 'GET');
      expect(client.last!.path, '/client-settings');
      expect(client.last!.query, isNull);
      expect(settings.defaultCurrency, 'AED');
      expect(settings.supportedCurrencies, ['AED', 'USD']);
    },
  );

  test(
    'a failed fetch is an exception the screen decides about, not a default',
    () async {
      final client = ScriptedApiClient()..error = StateError('offline');

      await expectLater(
        ApiClientSettingsSource(client).load(),
        throwsA(isA<StateError>()),
      );

      // In particular: nothing is invented. A caller that catches this leaves
      // its field open; a caller that did not would rather crash than file a
      // claim in a currency somebody guessed.
    },
  );
}
