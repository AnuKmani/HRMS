import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/api_attendance_repository.dart';
import '../domain/today_status.dart';
import 'attendance_screen.dart';

/// Today's attendance for the signed-in employee, read fresh each time the
/// dashboard asks.
final attendanceTodayProvider = FutureProvider.autoDispose<TodayStatus>(
  (ref) => ref.watch(attendanceRepositoryProvider).today(),
);

/// The dashboard's one button for the working day: CHECK IN in the
/// morning, CHECK OUT in the evening, and a quiet "done" after that.
///
/// It does not record anything itself. It opens the Attendance screen
/// with the action pre-selected, and that screen takes the GPS fix, checks
/// the geofence, takes the selfie and submits — the one path every
/// check-in goes through, whichever button started it. What the button
/// says comes from the server's `can_check_in` / `can_check_out`, never
/// from a guess here.
class AttendanceQuickCard extends ConsumerWidget {
  const AttendanceQuickCard({super.key, required this.onOpen});

  /// The dashboard's navigator, so returning here refreshes the card.
  final ValueChanged<String> onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final today = ref.watch(attendanceTodayProvider);

    final Widget body = switch (today) {
      AsyncData(:final value) => _ready(context, value),
      AsyncError() => _message(
        context,
        'Could not load today\'s attendance.',
        action: TextButton(
          onPressed: () => ref.invalidate(attendanceTodayProvider),
          child: const Text('Try again'),
        ),
      ),
      _ => const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      ),
    };

    return Card(
      key: const ValueKey('attendance-quick'),
      margin: const EdgeInsets.only(bottom: 16),
      color: theme.colorScheme.primaryContainer.withValues(alpha: 0.35),
      child: Padding(padding: const EdgeInsets.all(16), child: body),
    );
  }

  Widget _ready(BuildContext context, TodayStatus today) {
    final theme = Theme.of(context);
    final attendance = today.attendance;

    final String headline;
    final String? detail;
    if (today.canCheckOut) {
      headline = 'You are checked in';
      detail = 'Since ${_hhmm(attendance?.checkInAt)}';
    } else if (today.canCheckIn) {
      headline = 'You have not checked in today';
      detail = 'Tap CHECK IN — a selfie and your location, from anywhere.';
    } else if (today.checkedOut) {
      headline = 'Done for today';
      detail =
          'In ${_hhmm(attendance?.checkInAt)} · '
          'Out ${_hhmm(attendance?.checkOutAt)}';
    } else {
      headline = 'Attendance';
      detail = null;
    }

    final Widget? button = today.canCheckOut && today.canStartSiteVisit
        ? Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const ValueKey('quick-site-visit'),
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(56),
                  ),
                  icon: const Icon(Icons.directions_walk),
                  label: const Text('SITE VISIT'),
                  onPressed: () =>
                      onOpen('/attendance?start=${AttendanceScreen.visit}'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(child: _checkOutButton(theme)),
            ],
          )
        : today.canCheckOut
        ? _checkOutButton(theme)
        : today.canCheckIn
        ? FilledButton.icon(
            key: const ValueKey('quick-check-in'),
            style: FilledButton.styleFrom(
              minimumSize: const Size.fromHeight(56),
            ),
            icon: const Icon(Icons.login),
            label: const Text('CHECK IN'),
            onPressed: () =>
                onOpen('/attendance?start=${AttendanceScreen.checkIn}'),
          )
        : null;

    return _layout(theme, headline, detail, button, today);
  }

  Widget _checkOutButton(ThemeData theme) => FilledButton.icon(
    key: const ValueKey('quick-check-out'),
    style: FilledButton.styleFrom(
      minimumSize: const Size.fromHeight(56),
      backgroundColor: theme.colorScheme.error,
      foregroundColor: theme.colorScheme.onError,
    ),
    icon: const Icon(Icons.logout),
    label: const Text('CHECK OUT'),
    onPressed: () => onOpen('/attendance?start=${AttendanceScreen.checkOut}'),
  );

  Widget _layout(
    ThemeData theme,
    String headline,
    String? detail,
    Widget? button,
    TodayStatus today,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(headline, style: theme.textTheme.titleMedium),
        if (detail != null) ...[
          const SizedBox(height: 4),
          Text(detail, style: theme.textTheme.bodyMedium),
        ],
        if (button != null) ...[
          const SizedBox(height: 12),
          button,
          const SizedBox(height: 4),
          Text(
            today.canCheckOut
                ? 'Your location is recorded when you check out.'
                : 'Your location and a selfie are recorded when you check in.',
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }

  Widget _message(BuildContext context, String text, {Widget? action}) => Row(
    children: [
      Expanded(child: Text(text)),
      ?action,
    ],
  );

  static String _hhmm(String? iso) {
    final at = DateTime.tryParse(iso ?? '');
    if (at == null) return '—';
    final local = at.toLocal();
    return '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}';
  }
}
