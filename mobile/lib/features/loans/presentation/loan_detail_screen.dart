import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_loan_repository.dart';
import '../domain/loan.dart';
import '../domain/loan_repository.dart';
import 'loan_controller.dart';
import 'loan_list_screen.dart';

/// One loan, its schedule, and the decisions this session may make on it.
///
/// The three figures are shown together — principal, repaid, outstanding —
/// because any one of them alone invites a question the other two answer,
/// and the progress bar under them is drawn from *the balance* rather than
/// from counting `deducted` rows. That matters when an installment was
/// skipped or adjusted: counting statuses would still say "two of six taken"
/// while the balance said otherwise, and the balance is the one payroll
/// decrements.
///
/// The schedule itself appears only when the server sent it. The list
/// endpoint does not carry it and `LoanResource` omits the key rather than
/// sending `null`, so "no schedule yet" (a draft has none — it is minted by
/// the approval) and "not loaded" look the same here on purpose: this screen
/// always loads with the relation.
class LoanDetailScreen extends ConsumerStatefulWidget {
  const LoanDetailScreen({super.key, required this.loanId});

  final int loanId;

  @override
  ConsumerState<LoanDetailScreen> createState() => _LoanDetailScreenState();
}

class _LoanDetailScreenState extends ConsumerState<LoanDetailScreen> {
  Loan? _loan;
  String? _error;
  bool _loading = true;
  bool _working = false;
  String? _banner;

  LoanRepository get _repository => ref.read(loanRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // The route carries no permission, so a session that may not read loans
    // can still reach this URL. Not asking at all is better than drawing a
    // 403 as though something had gone wrong.
    if (!ref.read(permissionScopeProvider).canViewLoans) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final loan = await _repository.find(widget.loanId);

      if (!mounted) return;

      setState(() {
        _loan = loan;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _error = failure is ApiException
            ? failure.message
            : 'Something went wrong. Please try again.';
        _loading = false;
      });
    }
  }

  /// One transition, then redraw from the server's answer.
  ///
  /// The list is reloaded beside the row for the same reason the row is
  /// refetched: an approval changes the outstanding balance the list is
  /// showing, and a stale row there would contradict this screen.
  Future<void> _run(
    Future<Loan> Function(String? remarks) run, {
    required String title,
    required String message,
    String confirmLabel = 'Confirm',
    bool remarksRequired = false,
  }) async {
    final answer = await _ask(
      title: title,
      message: message,
      confirmLabel: confirmLabel,
      remarksRequired: remarksRequired,
    );

    // `null` means Back was pressed. An empty string means "confirmed with
    // nothing to add", which is not the same thing and must not be sent as
    // though the caller had typed a blank line.
    if (answer == null || !mounted) return;

    final typed = answer.trim();
    final remarks = typed.isEmpty ? null : typed;

    setState(() {
      _working = true;
      _banner = null;
    });

    try {
      await run(remarks);
      await _load();

      ref.read(loanListProvider.notifier).reload();

      if (!mounted) return;

      setState(() => _working = false);
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _working = false;
        // A 409 from this endpoint is not a malfunction: the loan moved
        // under us, or the state no longer allows the step. The server's
        // sentence says which, and it is shown as written.
        _banner = _messageFor(failure);
      });

      await _load();
    }
  }

  Future<String?> _ask({
    required String title,
    required String message,
    required String confirmLabel,
    bool remarksRequired = false,
  }) {
    final remarks = TextEditingController();
    String? error;

    return showDialog<String>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: Text(title),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(message),
              const SizedBox(height: 16),
              TextField(
                controller: remarks,
                maxLines: 3,
                decoration: InputDecoration(
                  labelText: remarksRequired ? 'Remarks *' : 'Remarks',
                  errorText: error,
                  border: const OutlineInputBorder(),
                  isDense: true,
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('Back'),
            ),
            FilledButton(
              onPressed: () {
                final text = remarks.text.trim();

                if (remarksRequired && text.isEmpty) {
                  setDialogState(() => error = 'Say why before confirming.');
                  return;
                }

                Navigator.of(dialogContext).pop(text);
              },
              child: Text(confirmLabel),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text('Loan #${widget.loanId}'),
        actions: [
          if (_loan != null && _loan!.isEditable)
            IconButton(
              key: const ValueKey('edit-loan'),
              tooltip: 'Edit this draft',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () => context.push('/loans/${widget.loanId}/edit'),
            ),
        ],
      ),
      body: _body(scope),
    );
  }

  Widget _body(PermissionScope scope) {
    // Drawn *before* the loading state: `_load` does not ask for a row it
    // may not read, so without this the screen would spin forever on a
    // refusal that never arrives.
    if (!scope.canViewLoans) {
      return const NoPermission(module: 'loans');
    }

    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('loan-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('loan-error'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                label: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    final loan = _loan!;
    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              key: const ValueKey('loan-banner'),
              padding: const EdgeInsets.only(bottom: 16),
              child: Material(
                color: theme.colorScheme.errorContainer,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _banner!,
                          style: TextStyle(
                            color: theme.colorScheme.onErrorContainer,
                          ),
                        ),
                      ),
                      TextButton(
                        onPressed: () => setState(() => _banner = null),
                        child: const Text('Dismiss'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          [
                            loan.typeLabel,
                            if (loan.reference != null) loan.reference!,
                          ].join(' · '),
                          style: theme.textTheme.titleLarge,
                        ),
                      ),
                      StatusChip(
                        key: const ValueKey('loan-status'),
                        label: loan.statusLabel,
                        tone: toneForLoanStatus(loan.status),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _row(theme, 'Person', loan.employeeName),
                  _row(theme, 'Since', loan.startDate),
                  _row(
                    theme,
                    'Each',
                    '${loan.numberOfInstallments} × ${loan.installmentAmount}',
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          _balance(loan, theme),
          if (loan.nextInstallment != null) ...[
            const SizedBox(height: 12),
            Card(
              key: const ValueKey('loan-next'),
              margin: EdgeInsets.zero,
              color: theme.colorScheme.secondaryContainer,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(
                  // `remaining_amount`, not the schedule's figure: a payment
                  // a floor-capped run only partly took is still the next
                  // thing to come out of this salary, and it comes out at
                  // what is *left* of it.
                  'Next payment: ${loan.nextInstallment!.remainingAmount} on '
                  '${loan.nextInstallment!.dueDate ?? 'the next payroll run'}.',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSecondaryContainer,
                  ),
                ),
              ),
            ),
          ],
          if (loan.installments.isNotEmpty) ...[
            const SizedBox(height: 16),
            _schedule(loan, theme),
          ],
          if (loan.remarks != null && loan.remarks!.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Remarks', style: theme.textTheme.labelLarge),
            Text(loan.remarks!, style: theme.textTheme.bodySmall),
          ],
          const SizedBox(height: 16),
          _actions(loan, scope),
        ],
      ),
    );
  }

  Widget _balance(Loan loan, ThemeData theme) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _moneyRow(theme, 'Advanced', loan.principalAmount, loan.currency),
            const SizedBox(height: 6),
            _moneyRow(theme, 'Repaid', loan.repaidAmount, loan.currency),
            const Divider(height: 24),
            _moneyRow(
              theme,
              'Still owing',
              loan.outstandingBalance,
              loan.currency,
              emphasise: true,
            ),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                key: const ValueKey('loan-progress'),
                value: loan.progress,
                minHeight: 6,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              '${(loan.progress * 100).round()}% repaid',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  Widget _schedule(Loan loan, ThemeData theme) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Repayment schedule', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (var i = 0; i < loan.installments.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              _installmentRow(loan.installments[i], loan.currency, theme),
            ],
          ],
        ),
      ),
    );
  }

  Widget _installmentRow(
    LoanInstallment installment,
    String currency,
    ThemeData theme,
  ) {
    return Padding(
      key: ValueKey('installment-${installment.sequence}'),
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          SizedBox(
            width: 34,
            child: Text(
              '${installment.sequence}',
              style: theme.textTheme.bodySmall,
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  installment.dueDate ?? 'On the next run',
                  style: theme.textTheme.bodyMedium,
                ),

                // Scheduled, taken and left are three different figures, so
                // a payment the run could only partly take says so in the
                // row rather than showing the schedule's amount as though
                // the whole of it had moved.
                if (installment.isPartial)
                  MoneyText(
                    installment.remainingAmount,
                    currency: currency,
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
          ),
          StatusChip(
            label: installment.statusLabel,
            tone: installment.status == LoanInstallment.statusDeducted
                ? StatusTone.positive
                : installment.status == LoanInstallment.statusPending
                ? StatusTone.neutral
                : StatusTone.warning,
          ),
          const SizedBox(width: 12),
          MoneyText(
            installment.amount,
            currency: currency,
            style: theme.textTheme.bodyLarge,
          ),
        ],
      ),
    );
  }

  Widget _row(ThemeData theme, String label, String? value) {
    if (value == null || value.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 70,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }

  Widget _moneyRow(
    ThemeData theme,
    String label,
    String value,
    String currency, {
    bool emphasise = false,
  }) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: emphasise
                ? theme.textTheme.titleSmall
                : theme.textTheme.bodyMedium,
          ),
        ),
        MoneyText(
          value,
          currency: currency,
          style: emphasise
              ? theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                )
              : theme.textTheme.bodyLarge,
        ),
      ],
    );
  }

  Widget _actions(Loan loan, PermissionScope scope) {
    final canAnswer = scope.canApproveLoans && loan.canBeAnswered && !_working;
    final canCancel =
        loan.isWithdrawable && !canAnswer && scope.canViewLoans && !_working;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (loan.canSubmit && scope.canCreateLoans && !_working) ...[
          FilledButton.icon(
            key: const ValueKey('submit-loan'),
            onPressed: () => _run(
              (remarks) => _repository.submit(loan.id),
              title: 'Submit this request?',
              message:
                  'It goes for approval and can no longer be edited. '
                  'You can still withdraw it until it is decided.',
              confirmLabel: 'Submit',
            ),
            icon: const Icon(Icons.send_outlined),
            label: const Text('Submit'),
          ),
          const SizedBox(height: 8),
        ],
        if (canAnswer) ...[
          FilledButton.icon(
            key: const ValueKey('approve-loan'),
            onPressed: () => _run(
              (remarks) => _repository.approve(loan.id, remarks: remarks),
              title: 'Approve this loan?',
              message:
                  'The repayment schedule is created now and frozen with '
                  'the approval. Nobody may approve their own loan.',
              confirmLabel: 'Approve',
            ),
            icon: const Icon(Icons.check_outlined),
            label: const Text('Approve'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('reject-loan'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _run(
              (remarks) => _repository.reject(loan.id, remarks: remarks),
              title: 'Refuse this loan?',
              message: 'Nothing is repaid and nothing is owed.',
              confirmLabel: 'Refuse',
              remarksRequired: true,
            ),
            icon: const Icon(Icons.close_outlined),
            label: const Text('Refuse'),
          ),
          const SizedBox(height: 8),
        ],
        if (canCancel)
          OutlinedButton.icon(
            key: const ValueKey('cancel-loan'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _run(
              (remarks) => _repository.cancel(loan.id),
              title: 'Withdraw this request?',
              message: loan.isDraft
                  ? 'The draft is discarded.'
                  : 'The request leaves the approval queue.',
              confirmLabel: 'Withdraw',
            ),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Withdraw'),
          ),
      ],
    );
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) {
      final fieldErrors = failure.errors.values;

      if (fieldErrors.isNotEmpty) return fieldErrors.join('\n');

      return failure.message;
    }

    return 'Something went wrong. Please try again.';
  }
}
