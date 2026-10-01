import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../domain/onboarding.dart';
import '../domain/onboarding_repository.dart';

final onboardingRepositoryProvider = Provider<OnboardingRepository>(
  (ref) => ApiOnboardingRepository(ref.watch(apiClientProvider)),
);

/// `OnboardingRepository` over the real HTTP client.
class ApiOnboardingRepository implements OnboardingRepository {
  ApiOnboardingRepository(this._client);

  static const _path = '/onboarding';

  final ApiClient _client;

  @override
  Future<PageResult<Onboarding>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Onboarding>.fromEnvelope(envelope, Onboarding.fromJson);
  }

  @override
  Future<Onboarding> find(int employeeId) async =>
      _one((await _client.get('$_path/$employeeId')).data);

  @override
  Future<Onboarding> update(int employeeId, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$employeeId', body: body)).data);

  @override
  Future<Onboarding> complete(int employeeId) async =>
      _one((await _client.post('$_path/$employeeId/complete')).data);

  Onboarding _one(Object? data) {
    if (data is! Map<String, dynamic>) {
      throw StateError('Unexpected onboarding payload.');
    }

    return Onboarding.fromJson(data);
  }
}
