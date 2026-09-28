import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/leave_balance.dart';
import '../domain/leave_request.dart';
import '../domain/leave_repository.dart';
import '../domain/leave_type.dart';

final leaveRepositoryProvider = Provider<LeaveRepository>(
  (ref) => ApiLeaveRepository(ref.watch(apiClientProvider)),
);

/// `LeaveRepository` over the real HTTP client.
///
/// Every verb translates the envelope once and lets `PageResult` / the model
/// decide what a payload that does not match means — no repository here
/// swallows a malformed response and hands back an empty list, because
/// "nothing exists" and "nothing was understood" have to stay different
/// answers for the screens to draw them differently.
///
/// The four transitions (`submit`, `approve`, `reject`, `cancel`) are four
/// methods rather than one `act(action)` because the server has four
/// endpoints and a stringly-typed action would only move the typo from the
/// route to a parameter — where nothing catches it until the 404 arrives.
class ApiLeaveRepository implements LeaveRepository {
  ApiLeaveRepository(this._client);

  static const _path = '/leave';

  final ApiClient _client;

  @override
  Future<PageResult<LeaveRequest>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<LeaveRequest>.fromEnvelope(
      envelope,
      LeaveRequest.fromJson,
    );
  }

  @override
  Future<LeaveRequest> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<LeaveRequest> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<LeaveRequest> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<LeaveRequest> submit(int id, {String remarks = ''}) async =>
      _transition(id, 'submit', remarks);

  @override
  Future<LeaveRequest> approve(int id, {String remarks = ''}) async =>
      _transition(id, 'approve', remarks);

  @override
  Future<LeaveRequest> reject(int id, {String remarks = ''}) async =>
      _transition(id, 'reject', remarks);

  @override
  Future<LeaveRequest> cancel(int id, {String remarks = ''}) async =>
      _transition(id, 'cancel', remarks);

  Future<LeaveRequest> _transition(
    int id,
    String action,
    String remarks,
  ) async {
    final envelope = await _client.post(
      '$_path/$id/$action',
      body: remarks.isEmpty
          ? <String, Object?>{}
          : <String, Object?>{'remarks': remarks},
    );

    return _one(envelope.data);
  }

  @override
  Future<LeaveRequest> fileCertificate(
    int id,
    Uint8List bytes, {
    String filename = 'certificate.jpg',
  }) async {
    final envelope = await _client.postMultipart(
      '$_path/$id/certificate',
      fields: const <String, Object?>{},
      files: <String, MultipartFile>{
        'certificate': MultipartFile.fromBytes(
          bytes,
          filename: filename,
          // Declared, not sniffed. The server accepts PDF, JPG, PNG and
          // WebP and validates the bytes as well as the label, so a name
          // that says `.jpg` around PNG data is not a way past anything —
          // but a name with no extension at all fails the `mimes` rule
          // before it ever reaches that check.
          contentType: DioMediaType('image', 'jpeg'),
        ),
      },
    );

    return _one(envelope.data);
  }

  @override
  Future<Uint8List> certificate(int id) =>
      _client.bytes('$_path/$id/certificate');

  @override
  Future<PageResult<LeaveType>> types({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '/leave-types',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<LeaveType>.fromEnvelope(envelope, LeaveType.fromJson);
  }

  @override
  Future<PageResult<LeaveBalance>> balances({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '/leave-balances',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<LeaveBalance>.fromEnvelope(
      envelope,
      LeaveBalance.fromJson,
    );
  }

  LeaveRequest _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return LeaveRequest.fromJson(data);
  }
}
