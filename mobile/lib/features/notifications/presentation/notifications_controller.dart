import 'dart:async';

import 'package:flutter/widgets.dart';
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

/// How often the bell asks the server for its number while the app is open,
/// so a leave request (or its answer) shows up without reopening anything.
/// Null switches polling off.
final notificationPollIntervalProvider = Provider<Duration?>(
  (ref) => const Duration(seconds: 30),
);

class UnreadNotificationsController extends AsyncNotifier<int> {
  Timer? _timer;

  @override
  Future<int> build() {
    final every = ref.watch(notificationPollIntervalProvider);
    if (every != null) {
      void start() {
        _timer?.cancel();
        _timer = Timer.periodic(every, (_) => _poll());
      }

      void stop() {
        _timer?.cancel();
        _timer = null;
      }

      start();
      // Only while the app is on screen: in the background the phone is
      // left alone (no network, no battery), and coming back checks once
      // straight away so the bell is current.
      final lifecycle = AppLifecycleListener(
        onResume: () {
          _poll();
          start();
        },
        onHide: stop,
      );
      ref.onDispose(() {
        stop();
        lifecycle.dispose();
      });
    }
    return ref.watch(notificationsRepositoryProvider).unreadCount();
  }

  /// A quiet re-read: a failed poll keeps the last number rather than
  /// turning the bell into an error.
  Future<void> _poll() async {
    try {
      final unread = await ref
          .read(notificationsRepositoryProvider)
          .unreadCount();
      if (state.value != unread) state = AsyncData(unread);
    } catch (_) {}
  }

  void set(int unread) => state = AsyncData(unread);
}
