import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/employee_training.dart';
import '../domain/training_compliance.dart';
import '../domain/training_program.dart';
import '../domain/training_repository.dart';
import '../domain/training_type.dart';

final trainingRepositoryProvider = Provider<TrainingRepository>(
  (ref) => ApiTrainingRepository(ref.watch(apiClientProvider)),
);

/// `TrainingRepository` over the real HTTP client.
///
/// Two path prefixes rather than one, because the module really is two
/// questions behind a shared vocabulary: `/employee-training` is a person's
/// own course history (and its rows are scoped per reader), while
/// `/training-programs` and `/training-types` are the catalogue an operator
/// maintains (and their rows are the same for everybody who may read them).
class ApiTrainingRepository implements TrainingRepository {
  ApiTrainingRepository(this._client);

  static const _enrolments = '/employee-training';

  static const _programs = '/training-programs';

  static const _types = '/training-types';

  final ApiClient _client;

  @override
  Future<PageResult<EmployeeTraining>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _enrolments,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<EmployeeTraining>.fromEnvelope(
      envelope,
      EmployeeTraining.fromJson,
    );
  }

  @override
  Future<PageResult<EmployeeTraining>> expiring({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '$_enrolments/expiring',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<EmployeeTraining>.fromEnvelope(
      envelope,
      EmployeeTraining.fromJson,
    );
  }

  @override
  Future<TrainingCompliance> compliance() async {
    final envelope = await _client.get('/training-compliance');

    return _one(envelope.data, TrainingCompliance.fromJson);
  }

  @override
  Future<EmployeeTraining> find(int id) async => _one(
    (await _client.get('$_enrolments/$id')).data,
    EmployeeTraining.fromJson,
  );

  @override
  Future<EmployeeTraining> enroll(Map<String, Object?> body) async => _one(
    (await _client.post(_enrolments, body: body)).data,
    EmployeeTraining.fromJson,
  );

  @override
  Future<EmployeeTraining> update(int id, Map<String, Object?> body) async =>
      _one(
        (await _client.put('$_enrolments/$id', body: body)).data,
        EmployeeTraining.fromJson,
      );

  @override
  Future<EmployeeTraining> complete(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) async {
    final path = '$_enrolments/$id/complete';

    if (file == null) {
      return _one(
        (await _client.post(path, body: body)).data,
        EmployeeTraining.fromJson,
      );
    }

    // Declared, not sniffed: the server validates the bytes themselves
    // (extension, MIME and a byte-level content check), so this label is
    // what the rules read first rather than a way past them.
    final name = (filename == null || filename.isEmpty)
        ? 'certificate.pdf'
        : filename;

    final envelope = await _client.postMultipart(
      path,
      fields: <String, Object?>{
        for (final entry in body.entries)
          if (entry.value != null) entry.key: entry.value,
      },
      files: <String, Object>{
        // `file`, not `certificate_file`: the rule that guards the upload is
        // CompleteEmployeeTrainingRequest's own `file => $this->fileRules()`
        // — the same byte-level check a passport goes through — and a part
        // named anything else arrives as an ignored field with a certificate
        // attached that nobody validated.
        'file': MultipartFile.fromBytes(
          file,
          filename: name,
          contentType: _contentTypeFor(name),
        ),
      },
    );

    return _one(envelope.data, EmployeeTraining.fromJson);
  }

  @override
  Future<EmployeeTraining> cancel(int id, {String? remarks}) async => _one(
    (await _client.post(
      '$_enrolments/$id/cancel',
      body: <String, Object?>{'remarks': remarks},
    )).data,
    EmployeeTraining.fromJson,
  );

  @override
  Future<Uint8List> file(int id) => _client.bytes('$_enrolments/$id/file');

  @override
  Future<List<TrainingType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    // **Not** `PageResult.fromEnvelope`: `GET /training-types` is
    // deliberately unpaginated — it is the vocabulary behind a picker, a
    // handful of rows that an operator changes — and its payload is a bare
    // array in `data` rather than the `{items, meta}` a table ships. Asking
    // this endpoint for a pager would be asking it to be the thing it was
    // written not to be, and parsing it as one would throw on a valid
    // response.
    final envelope = await _client.get(_types, query: query);

    final data = envelope.data;

    if (data is! List) throw unexpectedShapeException;

    return <TrainingType>[
      for (final raw in data)
        if (raw is Map<String, dynamic>) TrainingType.fromJson(raw),
    ];
  }

  @override
  Future<PageResult<TrainingProgram>> programs({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _programs,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<TrainingProgram>.fromEnvelope(
      envelope,
      TrainingProgram.fromJson,
    );
  }

  @override
  Future<TrainingProgram> findProgram(int id) async => _one(
    (await _client.get('$_programs/$id')).data,
    TrainingProgram.fromJson,
  );

  @override
  Future<TrainingProgram> createProgram(Map<String, Object?> body) async =>
      _one(
        (await _client.post(_programs, body: body)).data,
        TrainingProgram.fromJson,
      );

  @override
  Future<TrainingProgram> updateProgram(
    int id,
    Map<String, Object?> body,
  ) async => _one(
    (await _client.put('$_programs/$id', body: body)).data,
    TrainingProgram.fromJson,
  );

  DioMediaType _contentTypeFor(String filename) {
    final lower = filename.toLowerCase();

    if (lower.endsWith('.png')) return DioMediaType('image', 'png');
    if (lower.endsWith('.webp')) return DioMediaType('image', 'webp');
    if (lower.endsWith('.pdf')) return DioMediaType('application', 'pdf');

    return DioMediaType('image', 'jpeg');
  }

  T _one<T>(Object? data, T Function(Map<String, dynamic>) parse) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return parse(data);
  }
}
