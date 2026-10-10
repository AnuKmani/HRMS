import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/api_leave_repository.dart';
import '../domain/leave_balance.dart';

/// This year's leave for one employee, keyed by employee id.
final myLeaveBalancesProvider = FutureProvider.autoDispose
    .family<List<LeaveBalance>, int>((ref, employeeId) async {
      final page = await ref
          .watch(leaveRepositoryProvider)
          .balances(
            query: <String, Object?>{
              'employee_id': employeeId,
              'year': DateTime.now().year,
              'per_page': 50,
            },
          );
      return page.items;
    });

/// "My leave left" on the dashboard: each leave type and what remains.
class MyLeaveCard extends ConsumerWidget {
  const MyLeaveCard({
    super.key,
    required this.employeeId,
    required this.onOpen,
  });

  final int employeeId;
  final void Function(String path) onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balances = ref.watch(myLeaveBalancesProvider(employeeId));

    // Nothing to show (or no access): the dashboard simply goes without.
    final rows = balances.value ?? const <LeaveBalance>[];
    if (rows.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Card(
      key: const ValueKey('my-leave-card'),
      margin: const EdgeInsets.only(bottom: 16),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => onOpen('/leave-balances'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.beach_access_outlined),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'My leave left (${DateTime.now().year})',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  TextButton(
                    key: const ValueKey('my-leave-apply'),
                    onPressed: () => onOpen('/leave/new'),
                    child: const Text('Apply'),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              for (final b in rows)
                Padding(
                  key: ValueKey('my-leave-${b.leaveTypeId}'),
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Expanded(child: Text(b.leaveType ?? 'Leave')),
                      Text(
                        b.unlimited
                            ? 'Unlimited'
                            : '${b.remainingLabel} of '
                                  '${b.entitlement + b.carryForward + b.adjustment} left',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
              if (rows.any((b) => b.pending > 0))
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'Includes days waiting for approval.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
