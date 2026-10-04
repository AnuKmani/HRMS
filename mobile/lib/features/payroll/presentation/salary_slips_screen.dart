import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../data/api_payroll_repository.dart';
import '../data/slip_requests_repository.dart';
import '../domain/payroll.dart';
import 'payroll_controller.dart';

/// The payslips this session may hold, and the button that fetches one.
///
/// This is a different door from the payroll list, not a relabelling of it:
/// the route sits behind `salary_slips.view` and not `payroll.view`, so a
/// role can be given its own documents without being given the ledger. The
/// rows that come back are the same projection narrowed by the same
/// `Visibility` rules — there is no second model of "whose slip is whose".
///
/// **Every press is a request.** No slip is cached on the device, because
/// the file is rendered from the row at the moment it is asked for: a slip
/// downloaded before a permitted recalculation cannot then sit in a
/// downloads folder disagreeing with the row it came from.
class SalarySlipsScreen extends ConsumerWidget {
  const SalarySlipsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewSalarySlips),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Salary slips')),
        body: const NoPermission(module: 'salary slips'),
      );
    }

    final openIds = ref.watch(_openingProvider);
    final canRelease = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageSalarySlips),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Salary slips')),
      body: PagedListView<Payroll>(
        key: const ValueKey('salary-slip-list'),
        provider: salarySlipListProvider,
        searchHint: 'Search by employee or period',
        emptyMessage: 'No salary slips yet.',
        emptyHint: 'A payslip appears here once its month has been run.',
        filter: const _SlipPeriodNote(),
        itemBuilder: (context, payroll, index) => ListTile(
          key: ValueKey('slip-row-${payroll.id}'),
          title: Text(
            payroll.employeeName ?? 'Employee #${payroll.employeeId}',
          ),
          subtitle: Text(payroll.periodLabel),
          leading: MoneyText(
            payroll.netSalary,
            currency: payroll.currency,
            style: Theme.of(context).textTheme.titleMedium
                ?.copyWith(fontWeight: FontWeight.w600),
          ),
          trailing: openIds.contains(payroll.id)
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : _SlipAction(payroll: payroll, canRelease: canRelease),
          onTap: openIds.contains(payroll.id)
              ? null
              : () => _onTap(ref, context, payroll, canRelease),
        ),
      ),
    );
  }

  /// Download when allowed; otherwise ask for it — or, for HR looking at
  /// a pending request, decide it.
  Future<void> _onTap(
    WidgetRef ref,
    BuildContext context,
    Payroll payroll,
    bool canRelease,
  ) async {
    if (canRelease && payroll.slipRequested) {
      final choice = await showModalBottomSheet<String>(
        context: context,
        showDragHandle: true,
        builder: (context) => SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                title: Text(payroll.employeeName ?? 'Employee'),
                subtitle: Text(
                  'Asked to download the ${payroll.periodLabel} payslip.',
                ),
              ),
              ListTile(
                key: const ValueKey('slip-release'),
                leading: const Icon(Icons.check_circle, color: Colors.green),
                title: const Text('Release — let them download it'),
                onTap: () => Navigator.of(context).pop('approve'),
              ),
              ListTile(
                key: const ValueKey('slip-decline'),
                leading: const Icon(Icons.block),
                title: const Text('Decline'),
                onTap: () => Navigator.of(context).pop('reject'),
              ),
              ListTile(
                leading: const Icon(Icons.picture_as_pdf_outlined),
                title: const Text('Open the payslip'),
                onTap: () => Navigator.of(context).pop('open'),
              ),
            ],
          ),
        ),
      );
      if (choice == null || !context.mounted) return;
      if (choice == 'open') return _open(ref, context, payroll);
      return _decide(ref, context, payroll, approve: choice == 'approve');
    }

    if (payroll.canDownload) return _open(ref, context, payroll);
    if (payroll.slipRequested) {
      _snack(
        context,
        'Already requested. You will be notified when HR releases it.',
      );
      return;
    }
    return _request(ref, context, payroll);
  }

  Future<void> _request(
    WidgetRef ref,
    BuildContext context,
    Payroll payroll,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Request payslip'),
        content: Text(
          'Ask HR to release your ${payroll.periodLabel} payslip? '
          'You will get a notification when you can download it.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('slip-request-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Send request'),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(slipRequestsRepositoryProvider).request(payroll.id);
      ref.read(salarySlipListProvider.notifier).reload();
      if (context.mounted) {
        _snack(context, 'Request sent. HR has been notified.');
      }
    } catch (error) {
      if (context.mounted) _snack(context, _messageFor(error));
    }
  }

  Future<void> _decide(
    WidgetRef ref,
    BuildContext context,
    Payroll payroll, {
    required bool approve,
  }) async {
    try {
      final repository = ref.read(slipRequestsRepositoryProvider);
      if (approve) {
        await repository.approve(payroll.id);
      } else {
        await repository.reject(payroll.id);
      }
      ref.read(salarySlipListProvider.notifier).reload();
      if (context.mounted) {
        _snack(
          context,
          approve
              ? 'Released. The employee has been notified.'
              : 'Declined. The employee has been notified.',
        );
      }
    } catch (error) {
      if (context.mounted) _snack(context, _messageFor(error));
    }
  }

  void _snack(BuildContext context, String message) =>
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));

  Future<void> _open(
    WidgetRef ref,
    BuildContext context,
    Payroll payroll,
  ) async {
    final controller = ref.read(_openingProvider.notifier);

    controller.add(payroll.id);

    try {
      final bytes = await ref
          .read(payrollRepositoryProvider)
          .slipPdf(payroll.id);

      if (!context.mounted) return;

      await ref
          .read(pdfOpenerProvider)
          .openBytes(bytes, 'salary-slip-${payroll.id}.pdf');
    } catch (error) {
      if (!context.mounted) return;

      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(_messageFor(error))));
    } finally {
      controller.remove(payroll.id);
    }
  }
}

/// Which rows are mid-download. A set rather than a flag because two taps
/// two rows apart are two independent requests, and disabling the whole
/// list for one of them would be a lie about what the app is doing.
/// What tapping this row will do, shown before it is tapped.
class _SlipAction extends StatelessWidget {
  const _SlipAction({required this.payroll, required this.canRelease});

  final Payroll payroll;
  final bool canRelease;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    Widget chip(String label, Color color, IconData icon, String key) =>
        Container(
          key: ValueKey('$key-${payroll.id}'),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 16, color: color),
              const SizedBox(width: 4),
              Text(
                label,
                style: TextStyle(color: color, fontWeight: FontWeight.w700),
              ),
            ],
          ),
        );

    if (canRelease && payroll.slipRequested) {
      return chip(
        'Release?',
        Colors.orange,
        Icons.mark_email_unread,
        'slip-pending',
      );
    }
    if (payroll.canDownload) {
      return chip('Download', scheme.primary, Icons.download, 'slip-download');
    }
    if (payroll.slipRequested) {
      return chip(
        'Waiting for HR',
        Colors.orange,
        Icons.hourglass_top,
        'slip-waiting',
      );
    }
    if (payroll.slipRejected) {
      return chip('Declined', scheme.error, Icons.block, 'slip-declined');
    }
    return chip('Request', scheme.primary, Icons.send, 'slip-request');
  }
}

class _Opening extends Notifier<Set<int>> {
  @override
  Set<int> build() => <int>{};

  void add(int id) => state = <int>{...state, id};

  void remove(int id) => state = <int>{...state}..remove(id);
}

final _openingProvider = NotifierProvider<_Opening, Set<int>>(_Opening.new);

/// A single line explaining that the slip follows whatever period the list
/// is filtered to, so the two controls never appear to be at odds.
class _SlipPeriodNote extends StatelessWidget {
  const _SlipPeriodNote();

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Text(
      'Tap Request to ask HR for your payslip, then download it once '
      'released. No copy is kept on this device.',
      key: const ValueKey('slip-note'),
      style: Theme.of(context).textTheme.bodySmall,
    ),
  );
}

String _messageFor(Object error) => error is ApiException
    ? error.message
    : 'The slip could not be opened. Please try again.';
