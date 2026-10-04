import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'my_summary.dart';

/// The top of the dashboard: your own day in four numbers.
///
/// Each stat is drawn only if the server sent its block, and each one opens
/// the module it summarises — through [onOpen], so the dashboard's own
/// "refresh on return" applies. A failed fetch draws nothing rather than an
/// error: the module tiles below are the way in either way.
class MySummaryCard extends ConsumerWidget {
  const MySummaryCard({super.key, required this.onOpen});

  final ValueChanged<String> onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(mySummaryProvider).value;
    if (summary == null || summary.isEmpty) return const SizedBox.shrink();

    final attendance = summary.attendance;
    final payslip = summary.payslip;

    final stats = <Widget>[
      if (attendance != null)
        _Stat(
          key: const ValueKey('summary-attendance'),
          icon: Icons.schedule_outlined,
          label: 'Today',
          value: _attendanceText(attendance),
          onTap: () => onOpen('/attendance'),
        ),
      if (summary.leaveAvailable != null)
        _Stat(
          key: const ValueKey('summary-leave-available'),
          icon: Icons.beach_access_outlined,
          label: 'Leave left',
          value: '${_days(summary.leaveAvailable!)} days',
          onTap: () => onOpen('/leave-balances'),
        ),
      if (summary.leavePending != null)
        _Stat(
          key: const ValueKey('summary-leave-pending'),
          icon: Icons.pending_actions_outlined,
          label: 'Leave pending',
          value: '${summary.leavePending}',
          onTap: () => onOpen('/leave'),
        ),
      if (payslip != null)
        _Stat(
          key: const ValueKey('summary-payslip'),
          icon: Icons.receipt_long_outlined,
          label: payslip.period,
          value: '${payslip.currency} ${payslip.netSalary.toStringAsFixed(2)}'
              .trim(),
          onTap: () => onOpen('/salary-slips'),
        ),
    ];

    return Card(
      key: const ValueKey('my-summary'),
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(8),
        // Two to a row on a phone, four on anything wider.
        child: LayoutBuilder(
          builder: (context, constraints) {
            final columns = constraints.maxWidth >= 600 ? 4 : 2;
            final width = constraints.maxWidth / columns;

            return Wrap(
              children: [
                for (final stat in stats) SizedBox(width: width, child: stat),
              ],
            );
          },
        ),
      ),
    );
  }

  static String _attendanceText(TodayAttendance attendance) {
    final inAt = attendance.checkedInAt;
    final outAt = attendance.checkedOutAt;

    if (inAt == null) return 'Not checked in';
    if (outAt == null) return 'In since ${_time(inAt)}';
    return 'Out at ${_time(outAt)}';
  }

  static String _time(DateTime at) {
    final local = at.toLocal();
    return '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}';
  }

  static String _days(num value) =>
      value == value.roundToDouble() ? value.toInt().toString() : '$value';
}

class _Stat extends StatelessWidget {
  const _Stat({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 16, color: theme.colorScheme.primary),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    label,
                    style: theme.textTheme.labelMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(value, style: theme.textTheme.titleMedium),
          ],
        ),
      ),
    );
  }
}
