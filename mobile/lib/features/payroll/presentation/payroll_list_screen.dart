import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_payroll_repository.dart';
import '../domain/payroll.dart';
import 'payroll_controller.dart';

/// Every payroll row this session may read, for one period at a time.
///
/// The screen opens on the current month and says so, because that is the
/// question behind the door: "has September been run, and what does it come
/// to?". Two things it deliberately does *not* do:
///
///  - **nothing here computes a figure.** Every number on this screen came
///    out of `PayrollCalculationService` on the server and is rendered from
///    a decimal string through [Money]. An app that recomputed a net salary
///    to save a request would be a second payroll engine, and the two would
///    drift the first time a setting changed.
///
///  - **the buttons are the server's, not ours.** `can_recalculate` and
///    `can_lock` arrive with the row, so the screen cannot offer a transition
///    the API would refuse — it just asks, and shows the refusal verbatim if
///    somebody else got there first.
class PayrollListScreen extends ConsumerWidget {
  const PayrollListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewPayroll),
    );
    final canSummary = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewPayrollSummary),
    );

    // Management holds `payroll.summary.view` and not `payroll.view` on
    // purpose: it gets the totals and no rows. Rather than pretend the
    // module is closed — which would look like the summary never worked —
    // the list is drawn only for sessions that may actually see rows.
    if (!canView && !canSummary) {
      return Scaffold(
        appBar: AppBar(title: const Text('Payroll')),
        body: const NoPermission(module: 'payroll'),
      );
    }

    final canRun = ref.watch(
      permissionScopeProvider.select((scope) => scope.canRunPayroll),
    );
    final canSlips = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewSalarySlips),
    );

    // Only a session that may read rows reads the *list's* query — creating
    // that provider also schedules its first fetch, and a totals-only reader
    // would then be asking for a ledger it is never shown. Its period is the
    // opening one, and there is no filter to change it because no filter is
    // drawn.
    final period = canView
        ? ref.watch(
            payrollListProvider.select((state) => _periodOf(state.query)),
          )
        : _periodOf(currentPayrollQuery());

    return Scaffold(
      appBar: AppBar(
        title: const Text('Payroll'),
        actions: [
          if (canSlips)
            IconButton(
              key: const ValueKey('open-salary-slips'),
              tooltip: 'Salary slips',
              icon: const Icon(Icons.receipt_long_outlined),
              onPressed: () => context.push('/salary-slips'),
            ),
          if (canRun)
            IconButton(
              key: const ValueKey('run-payroll'),
              tooltip: 'Run payroll for this month',
              icon: const Icon(Icons.play_arrow),
              onPressed: canView ? () => _run(context, ref, period) : null,
            ),
        ],
      ),
      body: canView
          ? PagedListView<Payroll>(
              key: const ValueKey('payroll-list'),
              provider: payrollListProvider,
              emptyMessage: 'No payroll run for ${period.label}.',
              emptyHint:
                  'Run the month from the play button above, or pick '
                  'another period.',
              filter: _PeriodFilter(
                period: period,
                onPeriod: (next) => ref
                    .read(payrollListProvider.notifier)
                    .setQuery(<String, Object?>{
                      if (next.year != null) 'year': next.year,
                      if (next.month != null) 'month': next.month,
                    }),
              ),
              itemBuilder: (context, payroll, index) => ListTile(
                key: ValueKey('payroll-row-${payroll.id}'),
                title: Text(
                  payroll.employeeName ?? 'Employee #${payroll.employeeId}',
                ),
                subtitle: Text(
                  [
                    payroll.periodLabel,
                    if (payroll.employeeCode != null) payroll.employeeCode!,
                    if (payroll.isBlocked) payroll.blockedReason!,
                  ].join(' · '),
                ),
                isThreeLine: payroll.isBlocked,
                leading: MoneyText(
                  payroll.netSalary,
                  currency: payroll.currency,
                  style: Theme.of(context).textTheme.titleMedium
                      ?.copyWith(fontWeight: FontWeight.w600),
                ),
                trailing: StatusChip(
                  label: payroll.statusLabel,
                  tone: toneForPayrollStatus(payroll.status),
                ),
                onTap: () => context.push('/payroll/${payroll.id}'),
              ),
            )
          : ListView(children: [_SummaryCard(period: period)]),
    );
  }

  Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    PayrollPeriod period,
  ) async {
    if (period.year == null || period.month == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pick a single month before running payroll.'),
        ),
      );

      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Run payroll?'),
        content: Text(
          'This calculates ${period.label} for every employee with a salary '
          'on record. Rows that already exist are recalculated, and a row '
          'that is reviewed, processed or locked is left alone.',
        ),
        actions: [
          TextButton(
            key: const ValueKey('run-payroll-cancel'),
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('run-payroll-confirm'),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Run'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) return;

    // The spinner is a ScaffoldMessenger banner rather than a blocking
    // dialog: a run over a few hundred rows takes a second or two, and a
    // modal that cannot be dismissed would make a slow month feel stuck.
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('Running payroll…')));

    try {
      final report = await ref
          .read(payrollRepositoryProvider)
          .process(year: period.year!, month: period.month!);

      if (!context.mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const ValueKey('payroll-run-report'),
          content: Text(
            '${report.calculated} calculated · ${report.created} new · '
            '${report.skipped} skipped · ${report.draft} without a salary',
          ),
        ),
      );

      await ref.read(payrollListProvider.notifier).reload();
    } catch (error) {
      if (!context.mounted) return;

      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(_messageFor(error))));
    }
  }
}

/// The colour a payroll status earns.
///
/// The ladder runs grey → blue → blue → green → green-locked: a period moving
/// *forward* is never red. Only `draft` needs attention, and it gets the
/// warning tone rather than an error — nothing has gone wrong yet, the month
/// simply has not been run.
StatusTone toneForPayrollStatus(String status) => switch (status) {
  Payroll.statusDraft => StatusTone.warning,
  Payroll.statusCalculated => StatusTone.info,
  Payroll.statusReviewed => StatusTone.info,
  Payroll.statusProcessed => StatusTone.positive,
  Payroll.statusLocked => StatusTone.positive,
  _ => StatusTone.neutral,
};

/// One period, expressed twice: as the query the list asks for and as the
/// sentence the screen shows when it has nothing.
class PayrollPeriod {
  const PayrollPeriod({
    required this.year,
    required this.month,
    required this.label,
  });

  final int? year;
  final int? month;
  final String label;

  static const all = PayrollPeriod(
    year: null,
    month: null,
    label: 'all periods',
  );
}

PayrollPeriod _periodOf(Map<String, Object?> query) {
  final year = query['year'] is int ? query['year'] as int : null;
  final month = query['month'] is int ? query['month'] as int : null;

  if (year == null || month == null) return PayrollPeriod.all;

  const names = [
    '',
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
  ];

  if (month < 1 || month > 12) return PayrollPeriod.all;

  return PayrollPeriod(
    year: year,
    month: month,
    label: '${names[month]} $year',
  );
}

/// The month picker: one year, one month, and an honest "all periods" at the
/// top of the month list.
///
/// The year window is a decade ending at next year rather than every year
/// since 1970 — a payroll system whose runs are older than that is reaching
/// for an archive, not a filter, and scrolling forty dead years to find the
/// one before last is worse than the (documented) limit.
class _PeriodFilter extends StatelessWidget {
  const _PeriodFilter({required this.period, required this.onPeriod});

  final PayrollPeriod period;
  final ValueChanged<PayrollPeriod> onPeriod;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final thisYear = DateTime.now().year;
    final years = <int>[
      for (var year = thisYear + 1; year > thisYear - 10; year--) year,
    ];
    final selectedYear = period.year;
    final selectedMonth = period.month;

    return Row(
      children: [
        Expanded(
          child: InputDecorator(
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              contentPadding: EdgeInsets.symmetric(
                horizontal: 12,
                vertical: 12,
              ),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<int>(
                key: const ValueKey('payroll-year-filter'),
                isExpanded: true,
                value: selectedYear,
                items: <DropdownMenuItem<int>>[
                  const DropdownMenuItem<int>(value: -1, child: Text('All')),
                  for (final year in years)
                    DropdownMenuItem<int>(value: year, child: Text('$year')),
                ],
                onChanged: (year) {
                  if (year == null) return;

                  onPeriod(
                    year == -1
                        ? PayrollPeriod.all
                        : PayrollPeriod(
                            year: year,
                            month: monthOrNull(selectedYear, selectedMonth),
                            label: '',
                          ),
                  );
                },
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: InputDecorator(
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              contentPadding: EdgeInsets.symmetric(
                horizontal: 12,
                vertical: 12,
              ),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<int>(
                key: const ValueKey('payroll-month-filter'),
                isExpanded: true,
                value: selectedMonth ?? -1,
                items: const <DropdownMenuItem<int>>[
                  DropdownMenuItem<int>(value: -1, child: Text('All months')),
                  DropdownMenuItem<int>(value: 1, child: Text('January')),
                  DropdownMenuItem<int>(value: 2, child: Text('February')),
                  DropdownMenuItem<int>(value: 3, child: Text('March')),
                  DropdownMenuItem<int>(value: 4, child: Text('April')),
                  DropdownMenuItem<int>(value: 5, child: Text('May')),
                  DropdownMenuItem<int>(value: 6, child: Text('June')),
                  DropdownMenuItem<int>(value: 7, child: Text('July')),
                  DropdownMenuItem<int>(value: 8, child: Text('August')),
                  DropdownMenuItem<int>(value: 9, child: Text('September')),
                  DropdownMenuItem<int>(value: 10, child: Text('October')),
                  DropdownMenuItem<int>(value: 11, child: Text('November')),
                  DropdownMenuItem<int>(value: 12, child: Text('December')),
                ],
                onChanged: (month) {
                  if (month == null || selectedYear == null) return;

                  onPeriod(
                    month == -1
                        ? PayrollPeriod(
                            year: selectedYear,
                            month: null,
                            label: '$selectedYear',
                          )
                        : PayrollPeriod(
                            year: selectedYear,
                            month: month,
                            label: '',
                          ),
                  );
                },
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        Text(
          period.label,
          key: const ValueKey('payroll-period-label'),
          style: theme.textTheme.labelLarge,
        ),
      ],
    );
  }

  static int? monthOrNull(int? year, int? month) => month;
}

/// `GET /payroll/summary`, drawn only for the sessions that hold
/// `payroll.summary.view`.
///
/// Its whole point is that it returns *aggregates*: three totals and a head
/// count, with no employee-level row anywhere in the response. Drawing it
/// above a list that Management cannot see is not a leak — it is the one
/// screen that role is granted.
class _SummaryCard extends ConsumerStatefulWidget {
  const _SummaryCard({required this.period});

  final PayrollPeriod period;

  @override
  ConsumerState<_SummaryCard> createState() => _SummaryCardState();
}

class _SummaryCardState extends ConsumerState<_SummaryCard> {
  PayrollSummary? _summary;
  String _error = '';
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _SummaryCard oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.period.year != widget.period.year ||
        oldWidget.period.month != widget.period.month) {
      _load();
    }
  }

  Future<void> _load() async {
    final year = widget.period.year;
    final month = widget.period.month;

    // The summary endpoint takes a year and refuses an unspecified one; with
    // no period chosen there is nothing to ask for, and inventing "the
    // current year" would show a figure the header does not describe.
    if (year == null) {
      setState(() {
        _summary = null;
        _error = '';
        _loading = false;
      });

      return;
    }

    setState(() {
      _loading = true;
      _error = '';
    });

    try {
      final summary = await ref
          .read(payrollRepositoryProvider)
          .summary(year: year, month: month);

      if (!mounted) return;

      setState(() {
        _summary = summary;
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(
          child: SizedBox(
            width: 24,
            height: 24,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    if (_error.isNotEmpty) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_error, style: theme.textTheme.bodyMedium),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final summary = _summary;

    if (summary == null) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Text(
          'Pick a year to see the company total.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return Card(
      margin: const EdgeInsets.all(16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${summary.year}${summary.month == null ? '' : ' · ${summary.month}'}'
              '  ·  ${summary.employeeCount} employees',
              key: const ValueKey('payroll-summary-head'),
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 12),
            _Total(
              label: 'Gross payroll',
              value: summary.grossPayroll,
              currency: summary.currency,
            ),
            _Total(
              label: 'Deductions',
              value: summary.totalDeductions,
              currency: summary.currency,
            ),
            const Divider(height: 24),
            _Total(
              label: 'Net payroll',
              value: summary.netPayroll,
              currency: summary.currency,
              emphasise: true,
            ),
            const SizedBox(height: 8),
            Text(
              'Totals only — this screen deliberately carries no employee '
              'figures.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({
    required this.label,
    required this.value,
    required this.currency,
    this.emphasise = false,
  });

  final String label;
  final String value;
  final String currency;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodyMedium)),
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
      ),
    );
  }
}

String _messageFor(Object error) => error is ApiException
    ? error.message
    : 'Something went wrong. Please try again.';
