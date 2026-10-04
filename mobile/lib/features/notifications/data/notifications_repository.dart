import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/app_notification.dart';

final notificationsRepositoryProvider = Provider<NotificationsRepository>(
  (ref) => ApiNotificationsRepository(ref.watch(apiClientProvider)),
);

/// The inbox. Every endpoint behind it is scoped to the caller by the server
/// — there is no `notifications.view` permission because there is nobody
/// else's inbox to be refused.
abstract class NotificationsRepository {
  Future<PageResult<AppNotification>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<int> unreadCount();

  /// Returns the unread count after the change.
  Future<int> markRead(int id);

  /// Returns the unread count after the change.
  Future<int> markAllRead();
}

class ApiNotificationsRepository implements NotificationsRepository {
  ApiNotificationsRepository(this._client);

  static const _path = '/notifications';

  final ApiClient _client;

  @override
  Future<PageResult<AppNotification>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<AppNotification>.fromEnvelope(
      envelope,
      AppNotification.fromJson,
    );
  }

  @override
  Future<int> unreadCount() async =>
      _unread((await _client.get('$_path/unread-count')).data);

  @override
  Future<int> markRead(int id) async =>
      _unread((await _client.post('$_path/$id/read')).data);

  @override
  Future<int> markAllRead() async =>
      _unread((await _client.post('$_path/read-all')).data);

  int _unread(Object? data) {
    if (data is! Map<String, dynamic> || data['unread'] is! int) {
      throw unexpectedShapeException;
    }

    return data['unread'] as int;
  }
}
