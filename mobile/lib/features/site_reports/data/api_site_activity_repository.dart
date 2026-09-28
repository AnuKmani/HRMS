import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../../sites/domain/site.dart';
import '../domain/site_activity_report.dart';
import '../domain/site_activity_repository.dart';
import '../domain/site_report_photo.dart';

final siteActivityRepositoryProvider = Provider<SiteActivityRepository>(
  (ref) => ApiSiteActivityRepository(ref.watch(apiClientProvider)),
);

/// `SiteActivityRepository` over the real HTTP client.
class ApiSiteActivityRepository implements SiteActivityRepository {
  ApiSiteActivityRepository(this._client);

  static const _path = '/site-activity-reports';

  final ApiClient _client;

  @override
  Future<PageResult<SiteActivityReport>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<SiteActivityReport>.fromEnvelope(
      envelope,
      SiteActivityReport.fromJson,
    );
  }

  @override
  Future<SiteActivityReport> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<SiteActivityReport> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<SiteActivityReport> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<SiteActivityReport> submit(
    int id, {
    required double latitude,
    required double longitude,
    required double accuracy,
  }) async {
    // The whole body. `submit` accepts three fields and nothing else, so
    // anything added to this map would be silently dropped by the request
    // class rather than rejected — sending exactly what is read is what
    // makes "there is no status field in a payload" checkable by looking
    // at this method.
    final envelope = await _client.post(
      '$_path/$id/submit',
      body: <String, Object?>{
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy': accuracy,
      },
    );

    return _one(envelope.data);
  }

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
            // The server mints the stored filename — this one is only ever
            // read for its extension, and a name the phone chose would be
            // one more string that has to survive a sanitiser.
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
  Future<PageResult<Site>> reportableSites({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '$_path/reportable-sites',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Site>.fromEnvelope(envelope, Site.fromJson);
  }

  SiteActivityReport _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return SiteActivityReport.fromJson(data);
  }
}
