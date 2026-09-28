import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_payroll_repository.dart';
import '../domain/payroll.dart';
import 'payroll_list_screen.dart';

/// One month of one person's pay, itemised, plus the buttons this session
/// may press on it.
///
/// The itemisation is the reason this screen exists rather than a total in a
/// list: a net salary nobody can decompose is a number to take on faith, and
/// every line here carries the `quantity × rate` the server computed it
/// from — `2.00 × 1,000.00` next to the two days of loss of pay in the leave
/// history. Nothing on this screen recomputes any of it; [Money] only turns
/// the strings the server sent into text.
///
/// The four buttons are the ladder from `PayrollService`, offered one at a
/// time and only while the row still permits the step. When the row moves,
/// the detail is refetched rather than patched locally — the refusal a
/// colleague's concurrent click would produce is the server's answer, and
/// rendering our own guess of the new status would hide it.
class PayrollDetailScreen extends ConsumerStatefulWidget {
  const PayrollDetailScreen({super.key, required this.payrollId});

  final int payrollId;

  @override
  ConsumerState<PayrollDetailScreen> createState() =>
      _PayrollDetailScreenState();
}

class _PayrollDetailScreenState extends ConsumerState<PayrollDetailScreen> {
  Payroll? _payroll;
  String _error = '';
  bool _loading = true;
  bool _working = false;
  String? _banner;
  bool _openingSlip = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // The route is not guarded on a permission, so this screen can be built
    // by a session that may not read it. The API would answer 403 either
    // way, but not asking at all is both cheaper and more honest than
    // drawing an error for a row we were never going to be shown.
    if (!ref.read(permissionScopeProvider).canViewPayroll) return;

    setState(() {
      _loading = true;
      _error = '';
    });

    try {
      final payroll = await ref
          .read(payrollRepositoryProvider)
          .find(widget.payrollId);

      if (!mounted) return;

      setState(() {
        _payroll = payroll;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;

      setState(() {
        _error = _messageFor(error);
        _loading = false;
      });
    }
  }

  Future<void> _act(String action) async {
    final payroll = _payroll;

    if (payroll == null || _working) return;

    final label = switch (action) {
      'review' => 'mark this period reviewed',
      'finalize' => 'mark this period processed',
      'lock' => 'lock this period permanently',
      _ => 'recalculate this period',
    };

    final confirmed = await _confirm(
      title: 'Confirm',
      message: 'This will $label. Nobody else\'s figures change.',
      key: ValueKey('payroll-$action-confirm'),
    );

    if (confirmed != true || !mounted) return;

    setState(() {
      _working = true;
      _banner = null;
    });

    try {
      final repository = ref.read(payrollRepositoryProvider);

      final updated = switch (action) {
        'review' => await repository.review(payroll.id),
        'finalize' => await repository.finalize(payroll.id),
        'lock' => await repository.lock(payroll.id),
        _ => await repository.recalculate(payroll.id),
      };

      if (!mounted) return;

      setState(() {
        _payroll = updated;
        _working = false;
        _banner = switch (action) {
          'review' => 'Marked as reviewed.',
          'finalize' => 'Marked as processed.',
          'lock' => 'Locked. This period can no longer be recalculated.',
          _ => 'Recalculated from the current attendance, leave and loans.',
        };
      });
    } catch (error) {
      if (!mounted) return;

      // A 409 from this endpoint is not a malfunction: somebody else moved
      // the row first, or the ladder has already gone past this step. The
      // server's sentence says which, and it is shown as written rather
      // than smoothed into "something went wrong".
      setState(() {
        _working = false;
        _banner = _messageFor(error);
      });

      await _load();
    }
  }

  Future<void> _openSlip() async {
    final payroll = _payroll;

    if (payroll == null || _openingSlip) return;

    setState(() => _openingSlip = true);

    try {
      final bytes = await ref
          .read(payrollRepositoryProvider)
          .slipPdf(payroll.id);

      if (!mounted) return;

      await ref
          .read(pdfOpenerProvider)
          .openBytes(bytes, 'salary-slip-${payroll.id}.pdf');
    } catch (error) {
      if (!mounted) return;

      setState(() => _banner = _messageFor(error));
    } finally {
      if (mounted) setState(() => _openingSlip = false);
    }
  }

  Future<bool?> _confirm({
    required String title,
    required String message,
    required Key key,
  }) => showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(dialogContext).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: key,
          onPressed: () => Navigator.of(dialogContext).pop(true),
          child: const Text('Confirm'),
        ),
      ],
    ),
  );

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    if (!scope.canViewPayroll) {
      return Scaffold(
        appBar: AppBar(title: const Text('Payroll')),
        body: const NoPermission(module: 'payroll'),
      );
    }

    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Payroll')),
        body: const Center(
          child: SizedBox(
            width: 24,
            height: 24,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    final payroll = _payroll;

    if (payroll == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Payroll')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(_error, textAlign: TextAlign.center),
          ),
        ),
      );
    }

    final earnings = payroll.items.where((item) => item.isEarning).toList();
    final deductions = payroll.items.where((item) => !item.isEarning).toList();

    return Scaffold(
      appBar: AppBar(
        title: Text(payroll.periodLabel),
        actions: [
          if (scope.canViewSalarySlips)
            IconButton(
              key: const ValueKey('download-salary-slip'),
              tooltip: 'Salary slip (PDF)',
              onPressed: _openingSlip ? null : _openSlip,
              icon: _openingSlip
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.picture_as_pdf_outlined),
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null) ...[
            _Banner(
              message: _banner!,
              onDismiss: () => setState(() => _banner = null),
            ),
            const SizedBox(height: 12),
          ],
          _Header(payroll: payroll),
          if (payroll.blockedReason != null) ...[
            const SizedBox(height: 12),
            _Blocked(reason: payroll.blockedReason!),
          ],
          const SizedBox(height: 12),
          _Totals(payroll: payroll),
          if (earnings.isNotEmpty) ...[
            const SizedBox(height: 20),
            _Lines(
              title: 'Earnings',
              code: 'payroll-lines-earnings',
              payroll: payroll,
              items: earnings,
            ),
          ],
          if (deductions.isNotEmpty) ...[
            const SizedBox(height: 20),
            _Lines(
              title: 'Deductions',
              code: 'payroll-lines-deductions',
              payroll: payroll,
              items: deductions,
            ),
          ],
          const SizedBox(height: 24),
          _Actions(
            payroll: payroll,
            scope: scope,
            working: _working,
            onAction: _act,
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.payroll});

  final Payroll payroll;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
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
                    payroll.employeeName ?? 'Employee #${payroll.employeeId}',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                StatusChip(
                  key: const ValueKey('payroll-status'),
                  label: payroll.statusLabel,
                  tone: toneForPayrollStatus(payroll.status),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [
                payroll.periodLabel,
                if (payroll.employeeCode != null) payroll.employeeCode!,
              ].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
            if (payroll.lockedAt != null) ...[
              const SizedBox(height: 8),
              Text(
                'Locked — this period is the record and cannot be restated.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Blocked extends StatelessWidget {
  const _Blocked({required this.reason});

  final String reason;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('payroll-blocked'),
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        reason,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onErrorContainer,
        ),
      ),
    );
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.payroll});

  final Payroll payroll;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            _Row(
              label: 'Gross salary',
              value: payroll.grossSalary,
              payroll: payroll,
            ),
            const SizedBox(height: 6),
            _Row(
              label: 'Total deductions',
              value: payroll.totalDeductions,
              payroll: payroll,
            ),
            const Divider(height: 24),
            _Row(
              key: const ValueKey('payroll-net'),
              label: 'Net salary',
              value: payroll.netSalary,
              payroll: payroll,
              emphasise: true,
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    super.key,
    required this.label,
    required this.value,
    required this.payroll,
    this.emphasise = false,
  });

  final String label;
  final String value;
  final Payroll payroll;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

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
          currency: payroll.currency,
          style: emphasise
              ? theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                )
              : theme.textTheme.bodyLarge,
        ),
      ],
    );
  }
}

class _Lines extends StatelessWidget {
  const _Lines({
    required this.title,
    required this.code,
    required this.payroll,
    required this.items,
  });

  final String title;
  final String code;
  final Payroll payroll;
  final List<PayrollItem> items;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        Card(
          margin: EdgeInsets.zero,
          child: Column(
            children: [
              for (var i = 0; i < items.length; i++) ...[
                if (i > 0) const Divider(height: 1),
                Padding(
                  key: i == 0 ? ValueKey('$code-first') : null,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 12,
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              items[i].description.isEmpty
                                  ? items[i].code
                                  : items[i].description,
                              style: theme.textTheme.bodyMedium,
                            ),
                            if (items[i].working != null)
                              Text(
                                items[i].working!,
                                key: ValueKey('${items[i].code}-working'),
                                style: theme.textTheme.bodySmall,
                              ),
                          ],
                        ),
                      ),
                      MoneyText(
                        items[i].amount,
                        currency: payroll.currency,
                        style: theme.textTheme.bodyLarge,
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Actions extends StatelessWidget {
  const _Actions({
    required this.payroll,
    required this.scope,
    required this.working,
    required this.onAction,
  });

  final Payroll payroll;
  final PermissionScope scope;
  final bool working;
  final ValueChanged<String> onAction;

  @override
  Widget build(BuildContext context) {
    final next = payroll.nextAction;

    if (next == null) {
      return const SizedBox.shrink();
    }

    // Each button asks two questions and both must be yes: may this session
    // press it at all (the permission), and does the row still accept it
    // (`can_lock` is the server's, `review` and `finalize` are refused by
    // the ladder itself). A button drawn without both answers would be a
    // 409 waiting to happen.
    final allowed = switch (next) {
      'review' => scope.canManagePayroll,
      'finalize' => scope.canManagePayroll,
      'lock' => scope.canLockPayroll && payroll.canLock,
      _ => scope.canProcessPayroll && payroll.canRecalculate,
    };

    if (!allowed) return const SizedBox.shrink();

    final canRecalculate =
        scope.canProcessPayroll && payroll.canRecalculate && payroll.isDraft;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FilledButton(
          key: ValueKey('payroll-action-$next'),
          onPressed: working ? null : () => onAction(next),
          child: Text(payroll.nextActionLabel ?? next),
        ),
        if (canRecalculate)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: OutlinedButton(
              key: const ValueKey('payroll-action-recalculate'),
              onPressed: working ? null : () => onAction('recalculate'),
              child: const Text('Recalculate'),
            ),
          ),
      ],
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.message, required this.onDismiss});

  final String message;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 4, 4, 4),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              message,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSecondaryContainer,
              ),
            ),
          ),
          IconButton(
            onPressed: onDismiss,
            icon: const Icon(Icons.close, size: 18),
          ),
        ],
      ),
    );
  }
}

String _messageFor(Object error) {
  if (error is ApiException) return error.message;

  return 'Something went wrong. Please try again.';
}
