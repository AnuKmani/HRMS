import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/salary_certificate.dart';
import '../domain/salary_certificate_repository.dart';

/// `/api/v1/salary-certificate-requests`.
final salaryCertificateRepositoryProvider =
    Provider<SalaryCertificateRepository>(
      (ref) => ApiSalaryCertificateRepository(ref.watch(apiClientProvider)),
    );

class ApiSalaryCertificateRepository implements SalaryCertificateRepository {
  ApiSalaryCertificateRepository(this._client);

  static const _path = '/salary-certificate-requests';

  final ApiClient _client;

  @override
  Future<PageResult<SalaryCertificateRequest>> list({
    required int page,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<SalaryCertificateRequest>.fromEnvelope(
      envelope,
      SalaryCertificateRequest.fromJson,
    );
  }

  @override
  Future<SalaryCertificateRequest> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<SalaryCertificateRequest> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<SalaryCertificateRequest> approve(int id, {String? remarks}) async =>
      _decide(id, 'approve', remarks);

  @override
  Future<SalaryCertificateRequest> reject(int id, {String? remarks}) async =>
      _decide(id, 'reject', remarks);

  @override
  Future<SalaryCertificateRequest> cancel(int id) async =>
      _one((await _client.post('$_path/$id/cancel')).data);

  @override
  Future<Uint8List> pdf(int id) => _client.bytes('$_path/$id/pdf');

  /// Approve and refuse are one request shape — a transition name and an
  /// optional note — so they share the one place that decides whether a
  /// remarks field is sent at all.
  Future<SalaryCertificateRequest> _decide(
    int id,
    String action,
    String? remarks,
  ) async {
    final envelope = await _client.post(
      '$_path/$id/$action',
      body: remarks == null ? null : <String, Object?>{'remarks': remarks},
    );

    return _one(envelope.data);
  }

  SalaryCertificateRequest _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return SalaryCertificateRequest.fromJson(data);
  }
}
