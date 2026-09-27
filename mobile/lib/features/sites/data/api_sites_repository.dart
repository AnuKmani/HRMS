import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/site.dart';
import '../domain/sites_repository.dart';

final sitesRepositoryProvider = Provider<SitesRepository>(
  (ref) => ApiSitesRepository(ref.watch(apiClientProvider)),
);

class ApiSitesRepository implements SitesRepository {
  ApiSitesRepository(this._client);

  static const _path = '/sites';

  final ApiClient _client;

  @override
  Future<PageResult<Site>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Site>.fromEnvelope(envelope, Site.fromJson);
  }

  @override
  Future<Site> find(int id) async {
    final envelope = await _client.get('$_path/$id');

    return _one(envelope.data);
  }

  @override
  Future<Site> create(Map<String, Object?> body) async {
    final envelope = await _client.post(_path, body: body);

    return _one(envelope.data);
  }

  @override
  Future<Site> update(int id, Map<String, Object?> body) async {
    final envelope = await _client.put('$_path/$id', body: body);

    return _one(envelope.data);
  }

  @override
  Future<void> remove(int id) => _client.delete('$_path/$id');

  Site _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Site.fromJson(data);
  }
}
