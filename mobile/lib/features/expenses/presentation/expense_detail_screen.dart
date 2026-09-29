import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/data/approval_step.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_expense_repository.dart';
import '../domain/expense.dart';
import '../domain/expense_receipt.dart';
import '../domain/expense_repository.dart';
import 'expense_receipt_capture_sheet.dart';
import 'expense_list_screen.dart';
import 'expenses_controller.dart';

/// One expense claim, everything the server knows about it, and the actions
/// this session may attempt.
///
/// Three things this screen is careful about:
///
///  - **the amount is printed by `Money` from the decimal string.** It is the
///    one figure on the screen a person will compare against a paper receipt,
///    so it is never parsed into a `double` and re-rounded on the way out.
///
///  - **receipts are rows, not links.** Each one is opened by id through the
///    policy-checked route; there is no path in the payload to show, copy or
///    guess at, and a receipt that fails to open says so rather than drawing
///    an empty frame.
///
///  - **state comes back from the server.** Every transition re-fetches the
///    claim rather than patching the local copy: the status, the approval
///    step, the receipt count and the timestamps all move together, and a
///    model updated from what the request *sent* would be showing the
///    intention instead of the outcome.
class ExpenseDetailScreen extends ConsumerStatefulWidget {
  const ExpenseDetailScreen({super.key, required this.expenseId});

  final int expenseId;

  @override
  ConsumerState<ExpenseDetailScreen> createState() =>
      _ExpenseDetailScreenState();
}

class _ExpenseDetailScreenState extends ConsumerState<ExpenseDetailScreen> {
  Expense? _claim;
  String? _error;
  bool _loading = true;
  bool _working = false;
  String? _banner;

  ExpenseRepository get _repository => ref.read(expenseRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Checked before the fetch rather than after it: `GET /expenses/{id}`
    // answers 403 to a session without `expenses.view`, so a URL typed by
    // hand would draw a spinner, wait for a refusal, and then show a message
    // about permissions either way.
    if (ref.read(permissionScopeProvider).canViewExpenses) {
      _load();
    } else {
      _loading = false;
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final claim = await _repository.find(widget.expenseId);
      if (!mounted) return;
      setState(() {
        _claim = claim;
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

  /// Runs one transition, then redraws from the server's answer.
  Future<void> _run(
    Future<Expense> Function(ExpenseRepository repository) run,
  ) async {
    setState(() => _working = true);

    try {
      await run(_repository);
      await _load();
      ref.read(expenseListProvider.notifier).reload();
      if (!mounted) return;
      setState(() => _working = false);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _act(
    Future<Expense> Function(ExpenseRepository repository, String remarks)
    run, {
    required String title,
    required String message,
    required String confirmLabel,
    bool remarksRequired = false,
  }) async {
    final remarks = await _ask(
      title: title,
      message: message,
      confirmLabel: confirmLabel,
      remarksRequired: remarksRequired,
    );

    if (remarks == null || !mounted) return;

    // The typed remarks travel all the way to the call: a refusal whose
    // reason was collected in a dialog and then dropped on the way to the
    // request is a refusal the API refuses to accept.
    await _run((repository) => run(repository, remarks));
  }

  Future<String?> _ask({
    required String title,
    required String message,
    required String confirmLabel,
    required bool remarksRequired,
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

  /* --------------------------------------------------------------- receipts */

  /// Photographs one frame and attaches it to this draft.
  ///
  /// The bytes go straight from the camera to the repository — nothing is
  /// written to disk here, and nothing is ever asked of a URL: the only way
  /// back in is by id, through the policy that already governs the claim.
  Future<void> _addReceipt(Expense claim) async {
    final bytes = await ExpenseReceiptCaptureSheet.show(context);
    if (bytes == null || !mounted) return;

    await _run(
      (repository) => repository.addReceipts(claim.id, <Uint8List>[bytes]),
    );
  }

  Future<void> _viewReceipt(ExpenseReceipt receipt) async {
    setState(() => _working = true);

    try {
      final bytes = await _repository.receipt(widget.expenseId, receipt.id);
      if (!mounted) return;
      setState(() => _working = false);

      if (!receipt.canPreview) {
        await ref
            .read(pdfOpenerProvider)
            .openBytes(
              bytes,
              receipt.originalName.isEmpty
                  ? 'receipt.pdf'
                  : receipt.originalName,
            );
        return;
      }

      await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(receipt.originalName),
          content: SizedBox(
            width: double.maxFinite,
            child: Image.memory(bytes, fit: BoxFit.contain),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      );
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _removeReceipt(Expense claim, ExpenseReceipt receipt) async {
    final answer = await _ask(
      title: 'Remove this receipt?',
      message:
          'The file is deleted from private storage. The claim keeps its '
          'amount and its history.',
      confirmLabel: 'Remove',
      remarksRequired: false,
    );

    if (answer == null || !mounted) return;

    await _run((repository) => repository.removeReceipt(claim.id, receipt.id));
  }

  /* ------------------------------------------------------------------ build */

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: Text('Expense #${widget.expenseId}')),
      body: _body(scope),
    );
  }

  Widget _body(PermissionScope scope) {
    if (!scope.canViewExpenses) {
      return const NoPermission(module: 'expenses');
    }

    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('expense-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('expense-error'),
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

    final claim = _claim!;
    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              key: const ValueKey('expense-banner'),
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
                          Money.format(claim.amount, currency: claim.currency),
                          style: theme.textTheme.headlineSmall,
                        ),
                      ),
                      StatusChip(
                        label: claim.statusLabel,
                        tone: toneForExpenseStatus(claim.status),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _row(theme, 'Date', claim.expenseDate),
                  _row(theme, 'Category', claim.categoryName),
                  _row(theme, 'Person', claim.employeeName),
                  _row(theme, 'Site', claim.siteName),
                  _row(theme, 'Project', claim.projectName),
                  if (claim.needsReceipt && (claim.receiptCount ?? 0) == 0)
                    Padding(
                      key: const ValueKey('receipt-required'),
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        'This category needs a receipt before the claim '
                        'can be submitted.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.error,
                        ),
                      ),
                    ),
                  const SizedBox(height: 8),
                  Text(claim.description, style: theme.textTheme.bodyMedium),
                ],
              ),
            ),
          ),
          if (claim.approvalChain != null &&
              claim.approvalChain!.isNotEmpty) ...[
            const SizedBox(height: 16),
            _chain(claim.approvalChain!, theme),
          ],
          const SizedBox(height: 16),
          _receipts(claim, theme),
          const SizedBox(height: 16),
          _actions(claim, scope),
        ],
      ),
    );
  }

  Widget _receipts(Expense claim, ThemeData theme) {
    final receipts = claim.receipts ?? const <ExpenseReceipt>[];

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Receipts', style: theme.textTheme.titleMedium),
            Text(claim.receiptLabel, style: theme.textTheme.bodySmall),
            const SizedBox(height: 8),
            for (final receipt in receipts)
              Padding(
                key: ValueKey('receipt-${receipt.id}'),
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    Icon(
                      receipt.isImage
                          ? Icons.image_outlined
                          : Icons.picture_as_pdf_outlined,
                      size: 20,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            receipt.originalName.isEmpty
                                ? 'Receipt ${receipt.id}'
                                : receipt.originalName,
                            style: theme.textTheme.bodyMedium,
                          ),
                          Text(
                            receipt.sizeLabel,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    IconButton(
                      key: ValueKey('view-receipt-${receipt.id}'),
                      tooltip: 'Open receipt',
                      icon: const Icon(Icons.open_in_new_outlined),
                      onPressed: _working ? null : () => _viewReceipt(receipt),
                    ),
                    if (claim.isEditable)
                      IconButton(
                        key: ValueKey('remove-receipt-${receipt.id}'),
                        tooltip: 'Remove receipt',
                        icon: const Icon(Icons.delete_outline),
                        onPressed: _working
                            ? null
                            : () => _removeReceipt(claim, receipt),
                      ),
                  ],
                ),
              ),
            if (claim.isEditable)
              FilledButton.icon(
                key: const ValueKey('add-receipt'),
                onPressed: _working ? null : () => _addReceipt(claim),
                icon: const Icon(Icons.camera_alt_outlined),
                label: const Text('Photograph a receipt'),
              ),
          ],
        ),
      ),
    );
  }

  Widget _chain(List<ApprovalStep> chain, ThemeData theme) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Approval', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final step in chain)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      step.isApproved
                          ? Icons.check_circle_outline
                          : step.isRejected
                          ? Icons.cancel_outlined
                          : step.isCurrent
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      size: 20,
                      color: step.isApproved
                          ? const Color(0xFF136C39)
                          : step.isRejected
                          ? theme.colorScheme.error
                          : theme.colorScheme.outline,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${step.sequence}. '
                            '${step.name.isEmpty ? 'Step ${step.sequence}' : step.name}',
                            style: theme.textTheme.bodyMedium,
                          ),
                          Text(
                            step.approverLabel,
                            style: theme.textTheme.bodySmall,
                          ),
                          if (step.remarks != null && step.remarks!.isNotEmpty)
                            Text(
                              step.remarks!,
                              style: theme.textTheme.bodySmall?.copyWith(
                                fontStyle: FontStyle.italic,
                              ),
                            ),
                        ],
                      ),
                    ),
                    StatusChip(
                      label: step.statusLabel,
                      tone: step.isApproved
                          ? StatusTone.positive
                          : step.isRejected
                          ? StatusTone.negative
                          : step.isCurrent
                          ? StatusTone.info
                          : StatusTone.neutral,
                    ),
                  ],
                ),
              ),
          ],
        ),
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
            width: 90,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }

  Widget _actions(Expense claim, PermissionScope scope) {
    final canApprove =
        scope.canApproveExpenses && claim.isAwaitingDecision && !_working;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (claim.isDraft) ...[
          FilledButton.icon(
            key: const ValueKey('submit-expense'),
            onPressed: _working
                ? null
                : () => _act(
                    (repository, remarks) => repository.submit(claim.id),
                    title: 'Submit this claim?',
                    message:
                        'It goes to your approvers and can no longer '
                        'be edited. '
                        '${claim.needsReceipt && (claim.receiptCount ?? 0) == 0 ? 'This category needs a receipt first.' : ''}',
                    confirmLabel: 'Submit',
                    remarksRequired: false,
                  ),
            icon: const Icon(Icons.send_outlined),
            label: const Text('Submit'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('edit-expense'),
            onPressed: _working
                ? null
                : () => context.push('/expenses/${claim.id}/edit'),
            icon: const Icon(Icons.edit_outlined),
            label: const Text('Edit'),
          ),
        ],
        if (canApprove) ...[
          FilledButton.icon(
            key: const ValueKey('approve-expense'),
            onPressed: () => _act(
              (repository, remarks) =>
                  repository.approve(claim.id, remarks: remarks),
              title: 'Approve this claim?',
              message:
                  'Your approval is one link of the chain; finance and '
                  'HR still sign after you.',
              confirmLabel: 'Approve',
              remarksRequired: false,
            ),
            icon: const Icon(Icons.check_outlined),
            label: const Text('Approve'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('reject-expense'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository, remarks) =>
                  repository.reject(claim.id, remarks: remarks),
              title: 'Reject this claim?',
              message: 'Nothing is paid, and the claimant sees your remarks.',
              confirmLabel: 'Reject',
              remarksRequired: true,
            ),
            icon: const Icon(Icons.close_outlined),
            label: const Text('Reject'),
          ),
        ],
        if (claim.isOpen && !_working && !canApprove) ...[
          OutlinedButton.icon(
            key: const ValueKey('cancel-expense'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository, remarks) => repository.cancel(claim.id),
              title: 'Cancel this claim?',
              message: claim.isDraft
                  ? 'The draft is discarded.'
                  : 'The claim leaves the approval chain.',
              confirmLabel: 'Cancel claim',
              remarksRequired: false,
            ),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Cancel claim'),
          ),
        ],
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
