import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/notifications_repository.dart';
import '../domain/app_notification.dart';

final notificationsListProvider =
    NotifierProvider<NotificationsListController, ListState<AppNotification>>(
      NotificationsListController.new,
    );

class NotificationsListController extends PagedListController<AppNotification> {
  @override
  Future<PageResult<AppNotification>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(notificationsRepositoryProvider).list(page: page, query: query);

  /// Reflect a read the server has already accepted, without refetching the
  /// page the user is scrolled half-way down.
  void showRead(int id) {
    state = state.copyWith(
      items: [
        for (final item in state.items)
          item.id == id ? item.markedRead() : item,
      ],
    );
  }

  void showAllRead() {
    state = state.copyWith(
      items: [for (final item in state.items) item.markedRead()],
    );
  }
}

/// The number on the dashboard's bell.
///
/// Always the server's figure: every write in this feature returns the new
/// count, and [set] takes it from there rather than counting locally — a
/// notification can arrive between two taps.
final unreadNotificationsProvider =
    AsyncNotifierProvider<UnreadNotificationsController, int>(
      UnreadNotificationsController.new,
    );

class UnreadNotificationsController extends AsyncNotifier<int> {
  @override
  Future<int> build() =>
      ref.watch(notificationsRepositoryProvider).unreadCount();

  void set(int unread) => state = AsyncData(unread);
}
