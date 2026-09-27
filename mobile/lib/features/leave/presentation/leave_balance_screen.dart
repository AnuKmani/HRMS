import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/leave_balance.dart';
import 'leave_controller.dart';

/// How much leave is left — the numbers, and the arithmetic behind them.
///
/// `remaining` is the server's figure and is drawn as sent. The formula
/// (`entitlement + carry_forward + adjustment - used - pending`) is not
/// repeated here: a second implementation would be a second place for it to
/// be wrong, and the two would disagree precisely when an adjustment had
/// been made by hand.
///
/// `used` and `pending` are shown as separate lines rather than summed into
/// one "gone" figure, because an approved absence and a request still in
/// flight are different facts — that is why there are two columns at all.
class LeaveBalanceScreen extends ConsumerWidget {
  const LeaveBalanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Leave balances')),
      body: PagedListView<LeaveBalance>(
        provider: leaveBalancesProvider,
        emptyMessage: 'No leave has been allocated yet.',
        emptyHint: 'Balances appear once HR sets entitlement for the year.',
        itemBuilder: (context, balance, index) => ListTile(
          key: ValueKey('balance-row-${balance.id}'),
          title: Text(
            balance.leaveType ?? 'Leave type #${balance.leaveTypeId}',
          ),
          subtitle: Text(balance.summary),
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                balance.remainingLabel,
                style: Theme.of(context).textTheme.titleMedium,
              ),
              if (balance.isNegative)
                const StatusChip(label: 'Over', tone: StatusTone.negative),
            ],
          ),
        ),
      ),
    );
  }
}
