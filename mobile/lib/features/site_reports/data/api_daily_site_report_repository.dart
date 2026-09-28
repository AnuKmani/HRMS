import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/daily_site_report.dart';
import '../domain/daily_site_report_repository.dart';
import '../domain/site_report_photo.dart';

final dailySiteReportRepositoryProvider = Provider<DailySiteReportRepository>(
  (ref) => ApiDailySiteReportRepository(ref.watch(apiClientProvider)),
);

/// `DailySiteReportRepository` over the real HTTP client.
class ApiDailySiteReportRepository implements DailySiteReportRepository {
  ApiDailySiteReportRepository(this._client);

  static const _path = '/daily-site-reports';

  final ApiClient _client;

  @override
  Future<PageResult<DailySiteReport>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<DailySiteReport>.fromEnvelope(
      envelope,
      DailySiteReport.fromJson,
    );
  }

  @override
  Future<DailySiteReport> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<DailySiteReport> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<DailySiteReport> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<DailySiteReport> submit(int id) async =>
      _one((await _client.post('$_path/$id/submit')).data);

  @override
  Future<List<SiteReportPhoto>> addPhotos(
    int reportId,
    List<Uint8List> photos, {
    String? caption,
  }) async {
    final fields = <String, Object?>{};
    if (caption != null) fields['caption'] = caption;

    final envelope = await _client.postMultipart(
      '$_path/$reportId/photos',
      fields: fields,
      files: <String, Object>{
        'photos': <MultipartFile>[
          for (var index = 0; index < photos.length; index++)
            MultipartFile.fromBytes(
              photos[index],
              filename: 'site-photo-${index + 1}.jpg',
            ),
        ],
      },
    );

    final data = envelope.data;
    if (data is! List) throw unexpectedShapeException;

    return data
        .whereType<Map<String, dynamic>>()
        .map(SiteReportPhoto.fromJson)
        .toList(growable: false);
  }

  @override
  Future<void> removePhoto(int reportId, int photoId) async {
    await _client.delete('$_path/$reportId/photos/$photoId');
  }

  @override
  Future<Uint8List> pdf(int id) => _client.bytes('$_path/$id/pdf');

  DailySiteReport _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return DailySiteReport.fromJson(data);
  }
}
