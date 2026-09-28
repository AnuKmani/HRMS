import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/loan.dart';
import '../domain/loan_repository.dart';

/// `/api/v1/loans`, exactly as the routes are registered.
///
/// The lifecycle verbs stay as their own methods rather than folded into a
/// generic `update`, so a screen reads like the API and a test can assert
/// *which* transition was asked for without inspecting a body.
final loanRepositoryProvider = Provider<LoanRepository>(
  (ref) => ApiLoanRepository(ref.watch(apiClientProvider)),
);

class ApiLoanRepository implements LoanRepository {
  ApiLoanRepository(this._client);

  static const _path = '/loans';

  final ApiClient _client;

  @override
  Future<PageResult<Loan>> list({
    required int page,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Loan>.fromEnvelope(envelope, Loan.fromJson);
  }

  @override
  Future<Loan> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<Loan> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<Loan> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<Loan> submit(int id) async =>
      _one((await _client.post('$_path/$id/submit')).data);

  @override
  Future<Loan> approve(int id, {String? remarks}) async =>
      _decide(id, 'approve', remarks);

  @override
  Future<Loan> reject(int id, {String? remarks}) async =>
      _decide(id, 'reject', remarks);

  @override
  Future<Loan> cancel(int id) async =>
      _one((await _client.post('$_path/$id/cancel')).data);

  /// Approving and refusing are one request shape — the transition name and
  /// an optional note — so they share the one place that decides whether a
  /// remarks field is sent at all. Sending `remarks: null` would trip
  /// `nullable` fine but would still be a key the server never asked for.
  Future<Loan> _decide(int id, String action, String? remarks) async {
    final envelope = await _client.post(
      '$_path/$id/$action',
      body: remarks == null ? null : <String, Object?>{'remarks': remarks},
    );

    return _one(envelope.data);
  }

  Loan _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Loan.fromJson(data);
  }
}
