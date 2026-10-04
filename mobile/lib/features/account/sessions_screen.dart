import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import 'account_repository.dart';

final deviceSessionsProvider = FutureProvider.autoDispose<List<DeviceSession>>(
  (ref) => ref.watch(accountRepositoryProvider).sessions(),
);

/// Where this account is signed in, and a way to end any *other* session —
/// a lost phone, a shared tablet.
///
/// The current device has no revoke button: ending the session you are
/// using is "Sign out", which also clears the token from this phone. Revoking
/// it from here would leave a dead token in secure storage until the next
/// request bounced.
class SessionsScreen extends ConsumerWidget {
  const SessionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sessions = ref.watch(deviceSessionsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Signed-in devices')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(deviceSessionsProvider.future),
        child: switch (sessions) {
          AsyncData(:final value) when value.isEmpty => ListView(
            children: const [
              Padding(
                padding: EdgeInsets.all(24),
                child: Text('No active sessions.', textAlign: TextAlign.center),
              ),
            ],
          ),
          AsyncData(:final value) => ListView(
            children: [
              for (final session in value)
                ListTile(
                  key: ValueKey('session-${session.id}'),
                  leading: Icon(
                    session.isCurrent
                        ? Icons.smartphone
                        : Icons.devices_other_outlined,
                  ),
                  title: Text(session.device),
                  subtitle: Text(_describe(session)),
                  trailing: session.isCurrent
                      ? const Chip(label: Text('This device'))
                      : IconButton(
                          key: ValueKey('revoke-session-${session.id}'),
                          tooltip: 'Sign this device out',
                          icon: const Icon(Icons.logout),
                          onPressed: () => _revoke(context, ref, session),
                        ),
                ),
            ],
          ),
          AsyncError(:final error) => ListView(
            children: [
              Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  children: [
                    Text(
                      error is ApiException
                          ? error.message
                          : 'Could not load your devices.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: () => ref.invalidate(deviceSessionsProvider),
                      child: const Text('Try again'),
                    ),
                  ],
                ),
              ),
            ],
          ),
          _ => const Center(child: CircularProgressIndicator()),
        },
      ),
    );
  }

  Future<void> _revoke(
    BuildContext context,
    WidgetRef ref,
    DeviceSession session,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign this device out?'),
        content: Text(
          '"${session.device}" will need to sign in again to use HRMS.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('confirm-revoke'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Sign out device'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) return;

    try {
      final message = await ref
          .read(accountRepositoryProvider)
          .revokeSession(session.id);
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
    } on ApiException catch (failure) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }

    ref.invalidate(deviceSessionsProvider);
  }

  static String _describe(DeviceSession session) {
    String when(DateTime at) {
      final local = at.toLocal();
      String two(int value) => value.toString().padLeft(2, '0');
      return '${local.year}-${two(local.month)}-${two(local.day)} '
          '${two(local.hour)}:${two(local.minute)}';
    }

    final lastUsed = session.lastUsedAt;
    final created = session.createdAt;

    return [
      if (lastUsed != null) 'Last active ${when(lastUsed)}',
      if (created != null) 'Signed in ${when(created)}',
    ].join(' · ');
  }
}
