import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/asset.dart';
import '../domain/asset_assignment.dart';
import '../domain/asset_repository.dart';
import '../domain/asset_type.dart';

final assetRepositoryProvider = Provider<AssetRepository>(
  (ref) => ApiAssetRepository(ref.watch(apiClientProvider)),
);

/// `AssetRepository` over the real HTTP client.
///
/// Three path prefixes, and they are three because the API is three: the
/// register (`/assets`), the log of who held what (`/asset-assignments`, read
/// only, with `assets.history.view` on top), and the vocabulary behind a
/// picker (`/asset-types`, unpaginated). Collapsing them into one `_path`
/// plus a string would trade three honest names for one dishonest one.
class ApiAssetRepository implements AssetRepository {
  ApiAssetRepository(this._client);

  static const _assets = '/assets';

  static const _assignments = '/asset-assignments';

  static const _types = '/asset-types';

  final ApiClient _client;

  @override
  Future<PageResult<Asset>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _assets,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Asset>.fromEnvelope(envelope, Asset.fromJson);
  }

  @override
  Future<Asset> find(int id) async =>
      _one((await _client.get('$_assets/$id')).data, Asset.fromJson);

  @override
  Future<Asset> create(Map<String, Object?> body) async =>
      _one((await _client.post(_assets, body: body)).data, Asset.fromJson);

  @override
  Future<Asset> update(int id, Map<String, Object?> body) async => _one(
    (await _client.put('$_assets/$id', body: body)).data,
    Asset.fromJson,
  );

  @override
  Future<Asset> assign(int id, Map<String, Object?> body) async => _one(
    (await _client.post('$_assets/$id/assign', body: body)).data,
    Asset.fromJson,
  );

  @override
  Future<Asset> returnAsset(int id, Map<String, Object?> body) async => _one(
    (await _client.post('$_assets/$id/return', body: body)).data,
    Asset.fromJson,
  );

  @override
  Future<Asset> changeStatus(
    int id, {
    required String status,
    String? notes,
  }) async => _one(
    (await _client.patch(
      '$_assets/$id/status',
      body: <String, Object?>{'status': status, 'notes': notes},
    )).data,
    Asset.fromJson,
  );

  @override
  Future<PageResult<AssetAssignment>> assignments({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _assignments,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<AssetAssignment>.fromEnvelope(
      envelope,
      AssetAssignment.fromJson,
    );
  }

  @override
  Future<AssetAssignment> findAssignment(int id) async => _one(
    (await _client.get('$_assignments/$id')).data,
    AssetAssignment.fromJson,
  );

  @override
  Future<List<AssetType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    // **Not** `PageResult.fromEnvelope`: `GET /asset-types` is deliberately
    // unpaginated — the vocabulary behind a picker, a handful of rows an
    // operator maintains — and its payload is a bare array in `data`
    // rather than the `{items, meta}` a table ships.
    final envelope = await _client.get(_types, query: query);

    final data = envelope.data;

    if (data is! List) throw unexpectedShapeException;

    return <AssetType>[
      for (final raw in data)
        if (raw is Map<String, dynamic>) AssetType.fromJson(raw),
    ];
  }

  T _one<T>(Object? data, T Function(Map<String, dynamic>) parse) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return parse(data);
  }
}
