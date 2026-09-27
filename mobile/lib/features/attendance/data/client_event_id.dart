import 'dart:math';

/// A fresh `client_event_id` — the key that makes a retry safe.
///
/// Generated **once, on the device, before the request is ever sent**, and
/// then reused for every attempt of that same act. The server stores it under
/// a unique index, so a second arrival with the same id finds the first row
/// and answers with it instead of recording the day twice. That only works
/// if the id is stable: regenerating it per attempt would turn one event into
/// however many times the network blipped.
///
/// A UUID v4 by construction — RFC 4122 variant and version bits set —
/// written here rather than pulled in as a package, because the whole of what
/// this app needs is sixteen random bytes and four string replacements.
String newClientEventId([Random? random]) {
  final rng = random ?? Random.secure();

  final bytes = List<int>.generate(16, (_) => rng.nextInt(256));

  // RFC 4122 version 4 …
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  // … and the 10xx variant.
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();

  return '${hex.substring(0, 8)}-'
      '${hex.substring(8, 12)}-'
      '${hex.substring(12, 16)}-'
      '${hex.substring(16, 20)}-'
      '${hex.substring(20)}';
}
