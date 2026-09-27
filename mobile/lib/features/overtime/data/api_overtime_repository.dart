import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/overtime_repository.dart';
import '../domain/overtime_request.dart';

final overtimeRepositoryProvider = Provider<OvertimeRepository>(
  (ref) => ApiOvertimeRepository(ref.watch(apiClientProvider)),
);

/// `OvertimeRepository` over the real HTTP client.
///
/// Four transitions, four methods — a stringly-typed `act(action)` would
/// move a typo from the route into a parameter, where nothing catches it
/// until a 404 arrives from an endpoint that never existed.
class ApiOvertimeRepository implements OvertimeRepository {
  ApiOvertimeRepository(this._client);

  static const _path = '/overtime';

  final ApiClient _client;

  @override
  Future<PageResult<OvertimeRequest>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<OvertimeRequest>.fromEnvelope(
      envelope,
      OvertimeRequest.fromJson,
    );
  }

  @override
  Future<OvertimeRequest> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<OvertimeRequest> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<OvertimeRequest> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<OvertimeRequest> submit(int id, {String remarks = ''}) async =>
      _act(id, 'submit', <String, Object?>{'remarks': remarks});

  @override
  Future<OvertimeRequest> approve(
    int id, {
    String remarks = '',
    int? approvedMinutes,
  }) async => _act(id, 'approve', <String, Object?>{
    'remarks': remarks,
    // Sending nothing for "grant exactly what was asked" is
    // deliberate: the server treats an absent `approved_minutes` as
    // the full request, and a client that pre-filled it with the
    // requested figure would make a trim impossible to distinguish
    // from an approval.
    'approved_minutes': ?approvedMinutes,
  });

  @override
  Future<OvertimeRequest> reject(int id, {String remarks = ''}) async =>
      _act(id, 'reject', <String, Object?>{'remarks': remarks});

  @override
  Future<OvertimeRequest> cancel(int id, {String remarks = ''}) async =>
      _act(id, 'cancel', <String, Object?>{'remarks': remarks});

  Future<OvertimeRequest> _act(
    int id,
    String action,
    Map<String, Object?> body,
  ) async {
    final envelope = await _client.post('$_path/$id/$action', body: body);

    return _one(envelope.data);
  }

  OvertimeRequest _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return OvertimeRequest.fromJson(data);
  }
}
