import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/loan.dart';
import 'loan_controller.dart';

/// Every loan and salary advance this session may read.
///
/// The list is deliberately quiet about money it does not owe you: what a
/// borrower sees is *their* outstanding balance, and what an approver sees
/// is the row with the employee's name on it. Which of those two it is
/// comes from the API's `Visibility` narrowing, not from a client-side
/// filter — a screen that hid rows by guessing would still have to fetch
/// them to hide them.
class LoanListScreen extends ConsumerWidget {
  const LoanListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewLoans),
    );

    if (!canView) {
      return Scaffold(
        appBar: AppBar(title: const Text('Loans')),
        body: const NoPermission(module: 'loans'),
      );
    }

    final canCreate = ref.watch(
      permissionScopeProvider.select((scope) => scope.canCreateLoans),
    );

    final status = ref.watch(
      loanListProvider.select((state) => state.query['status'] as String?),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Loans')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('new-loan'),
              tooltip: 'Ask for a loan or salary advance',
              onPressed: () => context.push('/loans/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Loan>(
        key: const ValueKey('loan-list'),
        provider: loanListProvider,
        searchHint: 'Search by reference or employee',
        emptyMessage: 'No loans yet.',
        emptyHint:
            'A loan you ask for appears here while it is a draft, '
            'then follows its repayment here.',
        filter: _StatusFilter(
          value: status,
          onStatus: (next) =>
              ref.read(loanListProvider.notifier).setFilter('status', next),
        ),
        itemBuilder: (context, loan, index) => ListTile(
          key: ValueKey('loan-row-${loan.id}'),
          title: Text(
            [
              loan.typeLabel,
              if (loan.reference != null) loan.reference!,
            ].join(' · '),
          ),
          subtitle: Text(
            [
              if (loan.employeeName != null) loan.employeeName!,
              if (loan.startDate != null) 'from ${loan.startDate}',
              '${loan.numberOfInstallments} × ${loan.installmentAmount}',
            ].join(' · '),
          ),
          isThreeLine: true,
          leading: MoneyText(
            loan.outstandingBalance,
            currency: loan.currency,
            style: Theme.of(context).textTheme.titleMedium
                ?.copyWith(fontWeight: FontWeight.w600),
          ),
          trailing: StatusChip(
            label: loan.statusLabel,
            tone: toneForLoanStatus(loan.status),
          ),
          onTap: () => context.push('/loans/${loan.id}'),
        ),
      ),
    );
  }
}

/// How much attention a repayment state deserves.
///
/// `completed` and `rejected` get the two decisive tones; `active` reads as
/// info because a loan being repaid is the ordinary case and colouring it
/// would make a normal book look like a problem. `draft` is a warning only
/// because nothing about it exists yet.
StatusTone toneForLoanStatus(String status) => switch (status) {
  Loan.statusDraft => StatusTone.warning,
  Loan.statusPending => StatusTone.info,
  Loan.statusApproved => StatusTone.info,
  Loan.statusActive => StatusTone.info,
  Loan.statusCompleted => StatusTone.positive,
  Loan.statusRejected => StatusTone.negative,
  Loan.statusCancelled => StatusTone.neutral,
  _ => StatusTone.neutral,
};

/// Six states, one row of chips. The server accepts a bare `status=` query,
/// so this is a filter rather than a decoration: choosing one *is* the
/// request.
class _StatusFilter extends StatelessWidget {
  const _StatusFilter({required this.value, required this.onStatus});

  final String? value;
  final ValueChanged<String?> onStatus;

  @override
  Widget build(BuildContext context) {
    final options = <(String?, String)>[
      (null, 'All'),
      (Loan.statusPending, 'Awaiting'),
      (Loan.statusActive, 'Repaying'),
      (Loan.statusCompleted, 'Completed'),
      (Loan.statusDraft, 'Draft'),
    ];

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final (key, label) in options)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                key: ValueKey('loan-filter-${key ?? 'all'}'),
                label: Text(label),
                selected: value == key,
                onSelected: (_) => onStatus(key),
              ),
            ),
        ],
      ),
    );
  }
}
