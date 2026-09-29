import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';

/// The slice of the server's settings a client is allowed to read: what the
/// company's own currency is, and which codes it will accept.
///
/// Why this exists instead of a constant in the form: the app used to fill
/// the currency box with a value somebody had typed into the source when it
/// was written. That is a configuration item wearing a code costume — it
/// survives a company moving country, a deployment to a second entity, and
/// every one of those is a wrong claim filed by a person who did exactly
/// what the screen told them.
///
/// Two values, deliberately. Anything more would be an admin settings
/// screen, which is not what a phone needs to open a form — see
/// `GET /api/v1/client-settings` for the allow-list this parses.
class ClientSettings {
  const ClientSettings({
    required this.defaultCurrency,
    required this.supportedCurrencies,
  });

  /// What a *new* claim is pre-filled with.
  ///
  /// Already reconciled server-side against [supportedCurrencies], so a
  /// mismatch between the two settings cannot hand the form a code the API
  /// would then refuse.
  final String defaultCurrency;

  /// Every code a claim may be filed in, upper-cased and de-duplicated.
  ///
  /// Never used to convert anything — there is no FX in this app, and a
  /// figure silently re-priced into another currency would be a number
  /// nobody agreed to.
  final List<String> supportedCurrencies;

  /// Whether there is a *choice* to offer at all.
  ///
  /// One configured currency is not a menu, it is a fact: a field that lets
  /// you pick between one option is a field that invites you to type over
  /// it. Two or more become selectable, and only those.
  bool get allowsCurrencyChoice => supportedCurrencies.length > 1;

  /// The codes as a sentence — "AED, USD".
  String get supportedSummary => supportedCurrencies.join(', ');

  /// Throws [FormatException] when the payload is not the object this
  /// endpoint promises. A caller treats that exactly as it treats a failed
  /// request: with no configuration, and a field left open rather than
  /// filled with a guess.
  factory ClientSettings.fromJson(Object? data) {
    if (data is! Map<String, dynamic>) {
      throw const FormatException(
        'Client settings arrived as something other than an object.',
      );
    }

    final supported = <String>[];
    final rawSupported = data['supported_currencies'];

    if (rawSupported is List) {
      for (final code in rawSupported) {
        if (code is! String) continue;

        final normalised = code.trim().toUpperCase();
        if (normalised.isNotEmpty && !supported.contains(normalised)) {
          supported.add(normalised);
        }
      }
    }

    return ClientSettings(
      defaultCurrency: (data['default_currency'] as String? ?? '')
          .trim()
          .toUpperCase(),
      supportedCurrencies: supported,
    );
  }
}

/// Where the settings come from.
///
/// An interface rather than a direct call so a widget test can say what the
/// server answered without standing a server up — the same reason every
/// repository in this app is one.
abstract class ClientSettingsSource {
  Future<ClientSettings> load();
}

/// `GET /api/v1/client-settings` over the real HTTP client.
class ApiClientSettingsSource implements ClientSettingsSource {
  const ApiClientSettingsSource(this._client);

  static const _path = '/client-settings';

  final ApiClient _client;

  @override
  Future<ClientSettings> load() async {
    final envelope = await _client.get(_path);

    return ClientSettings.fromJson(envelope.data);
  }
}

/// Watched by any screen that has to *offer* a configured value. Read (with
/// `ref.read`) rather than watched when a screen only needs it once, so a
/// background refetch cannot rewrite a form part-way through being typed in.
final clientSettingsProvider = Provider<ClientSettingsSource>(
  (ref) => ApiClientSettingsSource(ref.watch(apiClientProvider)),
);
