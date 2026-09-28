import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../data/api_payroll_repository.dart';
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
              : const Icon(Icons.picture_as_pdf_outlined),
          onTap: openIds.contains(payroll.id)
              ? null
              : () => _open(ref, context, payroll),
        ),
      ),
    );
  }

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
      'Rendered on demand — no copy is kept on this device.',
      key: const ValueKey('slip-note'),
      style: Theme.of(context).textTheme.bodySmall,
    ),
  );
}

String _messageFor(Object error) => error is ApiException
    ? error.message
    : 'The slip could not be opened. Please try again.';
