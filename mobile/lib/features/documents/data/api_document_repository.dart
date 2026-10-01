import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/document_repository.dart';
import '../domain/document_type.dart';
import '../domain/employee_document.dart';

final documentRepositoryProvider = Provider<DocumentRepository>(
  (ref) => ApiDocumentRepository(ref.watch(apiClientProvider)),
);

/// `DocumentRepository` over the real HTTP client.
///
/// Eight verbs, eight methods — a stringly-typed `act(action)` would move a
/// typo from the route into a parameter, where nothing catches it until a
/// 404 arrives from an endpoint that never existed.
class ApiDocumentRepository implements DocumentRepository {
  ApiDocumentRepository(this._client);

  static const _path = '/employee-documents';

  final ApiClient _client;

  @override
  Future<PageResult<EmployeeDocument>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<EmployeeDocument>.fromEnvelope(
      envelope,
      EmployeeDocument.fromJson,
    );
  }

  @override
  Future<PageResult<EmployeeDocument>> expiring({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '$_path/expiring',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<EmployeeDocument>.fromEnvelope(
      envelope,
      EmployeeDocument.fromJson,
    );
  }

  @override
  Future<EmployeeDocument> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<EmployeeDocument> create(
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) => _write(_path, body, file: file, filename: filename);

  @override
  Future<EmployeeDocument> update(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) => _write('$_path/$id', body, file: file, filename: filename, put: true);

  @override
  Future<EmployeeDocument> verify(int id) async =>
      _one((await _client.post('$_path/$id/verify')).data);

  @override
  Future<EmployeeDocument> reject(int id, {required String reason}) async =>
      _one(
        (await _client.post(
          '$_path/$id/reject',
          body: <String, Object?>{'reason': reason},
        )).data,
      );

  @override
  Future<EmployeeDocument> archive(int id) async =>
      _one((await _client.delete('$_path/$id')).data);

  @override
  Future<Uint8List> file(int id) => _client.bytes('$_path/$id/file');

  @override
  Future<List<DocumentType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    // One request, one page, and no "load more": the catalogue is a fixed
    // vocabulary an operator maintains rather than a table anybody can add
    // a thousand rows to. `per_page` asks the server for the whole thing so
    // a picker never shows nine of eleven types.
    final envelope = await _client.get(
      '/document-types',
      query: <String, Object?>{...query, 'per_page': 100},
    );

    final page = PageResult<DocumentType>.fromEnvelope(
      envelope,
      DocumentType.fromJson,
    );

    return page.items;
  }

  /// One write, two transports.
  ///
  /// A file turns the call into `FormData`; without one it stays JSON. The
  /// split is here rather than in `ApiClient` because `post`/`put` send JSON
  /// and a `FormData` handed to them would arrive as an empty body with a
  /// multipart header — the kind of failure that looks like a server bug for
  /// an hour.
  Future<EmployeeDocument> _write(
    String path,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
    bool put = false,
  }) async {
    if (file == null) {
      final envelope = put
          ? await _client.put(path, body: body)
          : await _client.post(path, body: body);

      return _one(envelope.data);
    }

    // Declared, not sniffed: the server validates the bytes themselves
    // (extension, MIME and a byte-level content check), and a name with no
    // extension at all fails the `mimes` rule before that check is ever
    // reached. This label is what the rules read first rather than a way
    // past them.
    final name = (filename == null || filename.isEmpty)
        ? 'document.pdf'
        : filename;

    // Nulls dropped rather than sent as empty parts: a form part for a
    // field the person never touched is a value the server has to
    // distinguish from an absence, and `nullable` in the rules is written
    // for the second one.
    final fields = <String, Object?>{
      for (final entry in body.entries)
        if (entry.value != null) entry.key: entry.value,
    };

    final files = <String, Object>{
      'file': MultipartFile.fromBytes(
        file,
        filename: name,
        contentType: _contentTypeFor(name),
      ),
    };

    final envelope = put
        ? await _client.putMultipart(path, fields: fields, files: files)
        : await _client.postMultipart(path, fields: fields, files: files);

    return _one(envelope.data);
  }

  DioMediaType _contentTypeFor(String filename) {
    final lower = filename.toLowerCase();

    if (lower.endsWith('.png')) return DioMediaType('image', 'png');
    if (lower.endsWith('.webp')) return DioMediaType('image', 'webp');
    if (lower.endsWith('.pdf')) return DioMediaType('application', 'pdf');

    return DioMediaType('image', 'jpeg');
  }

  EmployeeDocument _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return EmployeeDocument.fromJson(data);
  }
}
