import '../network/api_client.dart';
import '../network/api_exception.dart';

/// One page of a list endpoint, with the metadata needed to fetch the next.
///
/// The server always answers with `data: {items, meta}` — see
/// `PaginatedResponse::make()` — and this class exists so that shape is
/// parsed in exactly one place rather than five repositories each deciding
/// what `has_next` means when it is absent.
class PageResult<T> {
  const PageResult({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
    required this.hasNext,
  });

  /// Reads the envelope the API actually sends.
  ///
  /// A malformed payload throws rather than degrading to an empty page: an
  /// empty list and "the server sent something we cannot read" are different
  /// facts, and showing the first as if it were the second would tell a user
  /// there is nothing in the system when in fact nothing was understood.
  factory PageResult.fromEnvelope(
    ApiEnvelope envelope,
    T Function(Map<String, dynamic> json) parse,
  ) {
    final data = envelope.data;
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    final rawItems = data['items'];
    final rawMeta = data['meta'];

    if (rawItems is! List || rawMeta is! Map) throw unexpectedShapeException;

    final items = <T>[];

    for (final raw in rawItems) {
      if (raw is! Map<String, dynamic>) throw unexpectedShapeException;
      items.add(parse(raw));
    }

    return PageResult<T>(
      items: items,
      currentPage: _int(rawMeta['current_page'], fallback: 1),
      lastPage: _int(rawMeta['last_page'], fallback: 1),
      perPage: _int(rawMeta['per_page'], fallback: items.length),
      total: _int(rawMeta['total'], fallback: items.length),
      // Derived rather than trusted: `has_next` is a convenience the server
      // may omit on an older build, and `currentPage < lastPage` is the same
      // statement without needing it.
      hasNext: rawMeta['has_next'] is bool
          ? rawMeta['has_next']! as bool
          : _int(rawMeta['current_page'], fallback: 1) <
                _int(rawMeta['last_page'], fallback: 1),
    );
  }

  final List<T> items;

  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;
  final bool hasNext;

  bool get isEmpty => items.isEmpty;

  @override
  String toString() =>
      'PageResult(page: $currentPage/$lastPage, items: ${items.length}, '
      'total: $total)';
}

/// Coerces a JSON number that may have arrived as a string, and never throws
/// on `null` — a metadata field this app does not strictly need is not worth
/// crashing a list screen over.
int _int(Object? value, {required int fallback}) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? fallback;
  return fallback;
}
