import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/router/return_navigation.dart';
import '../data/notifications_repository.dart';
import '../domain/app_notification.dart';
import 'notifications_controller.dart';

/// The signed-in user's inbox: leave decisions, approvals waiting on them,
/// expiring documents, published payslips.
///
/// Open to every signed-in account — the server scopes each request to the
/// caller, so there is no permission to check and nothing to hide.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(notificationsListProvider);
    final unreadOnly = state.query['unread'] == '1';

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          IconButton(
            key: const ValueKey('mark-all-read'),
            tooltip: 'Mark all as read',
            icon: const Icon(Icons.done_all),
            onPressed: () => _markAllRead(context, ref),
          ),
        ],
      ),
      body: PagedListView<AppNotification>(
        provider: notificationsListProvider,
        emptyMessage: unreadOnly ? 'Nothing unread.' : 'No notifications yet.',
        emptyHint: 'Approvals, decisions and reminders will appear here.',
        filter: SegmentedButton<bool>(
          key: const ValueKey('notification-filter'),
          segments: const [
            ButtonSegment(value: false, label: Text('All')),
            ButtonSegment(value: true, label: Text('Unread')),
          ],
          selected: {unreadOnly},
          onSelectionChanged: (selection) => ref
              .read(notificationsListProvider.notifier)
              .setFilter('unread', selection.first ? '1' : null),
        ),
        itemBuilder: (context, notification, index) => ListTile(
          key: ValueKey('notification-row-${notification.id}'),
          leading: Icon(
            notification.read
                ? Icons.notifications_none_outlined
                : Icons.notifications_active,
            color: notification.read
                ? null
                : Theme.of(context).colorScheme.primary,
          ),
          title: Text(
            notification.title,
            style: notification.read
                ? null
                : const TextStyle(fontWeight: FontWeight.w600),
          ),
          subtitle: Text(
            [
              if (notification.body.isNotEmpty) notification.body,
              if (notification.createdAt != null)
                _when(notification.createdAt!.toLocal()),
            ].join('\n'),
          ),
          isThreeLine:
              notification.body.isNotEmpty && notification.createdAt != null,
          trailing: notification.route == null
              ? null
              : const Icon(Icons.chevron_right),
          onTap: () => _open(context, ref, notification),
        ),
      ),
    );
  }

  Future<void> _open(
    BuildContext context,
    WidgetRef ref,
    AppNotification notification,
  ) async {
    if (!notification.read) {
      try {
        final unread = await ref
            .read(notificationsRepositoryProvider)
            .markRead(notification.id);
        ref.read(notificationsListProvider.notifier).showRead(notification.id);
        ref.read(unreadNotificationsProvider.notifier).set(unread);
      } on ApiException catch (failure) {
        if (!context.mounted) return;
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(failure.message)));
        return;
      }
    }

    final route = notification.route;
    if (route == null || !context.mounted) return;

    // The record's own screen decides — through the API's 403 — whether
    // this account may still read it; a notification is not a permission.
    if (route == '/home') {
      context.returnTo(route);
    } else {
      context.push(route);
    }
  }

  Future<void> _markAllRead(BuildContext context, WidgetRef ref) async {
    try {
      final unread = await ref
          .read(notificationsRepositoryProvider)
          .markAllRead();
      ref.read(notificationsListProvider.notifier).showAllRead();
      ref.read(unreadNotificationsProvider.notifier).set(unread);
    } on ApiException catch (failure) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }
  }

  static String _when(DateTime at) {
    String two(int value) => value.toString().padLeft(2, '0');

    return '${at.year}-${two(at.month)}-${two(at.day)} '
        '${two(at.hour)}:${two(at.minute)}';
  }
}
