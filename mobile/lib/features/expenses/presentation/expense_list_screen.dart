import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/expense.dart';
import 'expenses_controller.dart';

/// Every expense claim this session may read.
///
/// Expenses walk the same approval engine as leave and overtime, so the row
/// reads the same way: what was spent, where, and where it is in the chain.
/// The amount is rendered by `Money` from the server's decimal string — a
/// claim list is a column of figures, and re-rounding one of them through a
/// `double` would be a cent nobody could explain.
class ExpenseListScreen extends ConsumerWidget {
  const ExpenseListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the claim button, and before the list: `GET /expenses` is behind
    // `permission:expenses.view`, so a session without it would only ever
    // draw a 403 or an empty list that looks like a decision.
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewExpenses))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Expenses')),
        body: const NoPermission(module: 'expenses'),
      );
    }

    final canCreate = ref.watch(
      permissionScopeProvider.select((scope) => scope.canCreateExpenses),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Expenses')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('claim-expense'),
              tooltip: 'New expense claim',
              onPressed: () => context.push('/expenses/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Expense>(
        provider: expenseListProvider,
        emptyMessage: 'No expense claims yet.',
        emptyHint:
            'What you spend on travel, sites and food appears here with '
            'its approval status.',
        filter: _StatusFilter(
          value: ref.watch(
            expenseListProvider.select((s) => s.query['status'] as String?),
          ),
          onStatus: (status) => ref
              .read(expenseListProvider.notifier)
              .setFilter('status', status),
        ),
        itemBuilder: (context, claim, index) => ListTile(
          key: ValueKey('expense-row-${claim.id}'),
          title: Text(
            '${claim.expenseDate} · '
            '${Money.format(claim.amount, currency: claim.currency)}',
          ),
          subtitle: Text(
            [
              if (claim.categoryName.isNotEmpty) claim.categoryName,
              claim.employeeName ?? 'You',
              if (claim.siteName != null) claim.siteName!,
              if (claim.projectName != null) claim.projectName!,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(
                label: claim.statusLabel,
                tone: toneForExpenseStatus(claim.status),
              ),
              const SizedBox(height: 4),
              if ((claim.receiptCount ?? 0) > 0)
                StatusChip(label: claim.receiptLabel, tone: StatusTone.neutral),
            ],
          ),
          onTap: () => context.push('/expenses/${claim.id}'),
        ),
      ),
    );
  }
}

/// The colour an expense status earns — the leave and overtime palette's
/// twin, so the three lists in this app never disagree about what red means.
StatusTone toneForExpenseStatus(String status) => switch (status) {
  Expense.statusApproved => StatusTone.positive,
  Expense.statusRejected => StatusTone.negative,
  Expense.statusCancelled => StatusTone.negative,
  Expense.statusPending => StatusTone.info,
  _ => StatusTone.neutral,
};

class _StatusFilter extends StatelessWidget {
  const _StatusFilter({required this.value, required this.onStatus});

  final String? value;
  final ValueChanged<String?> onStatus;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Status', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              key: const ValueKey('expense-status-filter'),
              isExpanded: true,
              value: value ?? '',
              items: const [
                DropdownMenuItem<String>(
                  value: '',
                  child: Text('All statuses'),
                ),
                DropdownMenuItem<String>(
                  value: Expense.statusDraft,
                  child: Text('Draft'),
                ),
                DropdownMenuItem<String>(
                  value: Expense.statusPending,
                  child: Text('Awaiting approval'),
                ),
                DropdownMenuItem<String>(
                  value: Expense.statusApproved,
                  child: Text('Approved'),
                ),
                DropdownMenuItem<String>(
                  value: Expense.statusRejected,
                  child: Text('Rejected'),
                ),
                DropdownMenuItem<String>(
                  value: Expense.statusCancelled,
                  child: Text('Cancelled'),
                ),
              ],
              onChanged: onStatus,
            ),
          ),
        ),
      ],
    );
  }
}
