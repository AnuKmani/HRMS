import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/overtime_request.dart';
import 'overtime_controller.dart';

/// Every overtime claim this session may read.
///
/// Overtime walks the same approval engine as leave, so the row reads the
/// same way: what was asked, what was granted, and where it is in the chain.
/// `payroll_eligible` earns its own chip rather than being folded into the
/// status, because it is the one flag a payroll run would read and an
/// approved claim with a trimmed grant is still the only thing that gets
/// there.
class OvertimeListScreen extends ConsumerWidget {
  const OvertimeListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the claim button, and before the list: `GET /overtime` is
    // behind `permission:overtime.view`, so a session without it would only
    // ever draw a 403 or an empty list that looks like a decision.
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewOvertime))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Overtime')),
        body: const NoPermission(module: 'overtime'),
      );
    }

    final canCreate = ref.watch(
      permissionScopeProvider.select((scope) => scope.canCreateOvertime),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Overtime')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('claim-overtime'),
              tooltip: 'Claim overtime',
              onPressed: () => context.push('/overtime/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<OvertimeRequest>(
        provider: overtimeListProvider,
        emptyMessage: 'No overtime claimed yet.',
        emptyHint:
            'Extra hours you worked will appear here with their '
            'approval status.',
        filter: _StatusFilter(
          value: ref.watch(
            overtimeListProvider.select((s) => s.query['status'] as String?),
          ),
          payrollOnly: ref.watch(
            overtimeListProvider.select(
              (s) => s.query['payroll_eligible'] == 'true',
            ),
          ),
          onStatus: (status) => ref
              .read(overtimeListProvider.notifier)
              .setFilter('status', status),
          onPayrollOnly: (on) => ref
              .read(overtimeListProvider.notifier)
              .setFilter('payroll_eligible', on ? 'true' : null),
        ),
        itemBuilder: (context, request, index) => ListTile(
          key: ValueKey('overtime-row-${request.id}'),
          title: Text('${request.overtimeDate} · ${request.requestedLabel}'),
          subtitle: Text(
            [
              request.employeeName ?? 'You',
              if (request.siteName != null) request.siteName!,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(
                label: request.statusLabel,
                tone: toneForOvertimeStatus(request.status),
              ),
              const SizedBox(height: 4),
              if (request.payrollEligible)
                const StatusChip(label: 'Payroll', tone: StatusTone.positive),
            ],
          ),
          onTap: () => context.push('/overtime/${request.id}'),
        ),
      ),
    );
  }
}

/// The colour an overtime status earns — the leave palette's twin, so the
/// two lists in this app never disagree about what red means.
StatusTone toneForOvertimeStatus(String status) => switch (status) {
  OvertimeRequest.statusApproved => StatusTone.positive,
  OvertimeRequest.statusRejected => StatusTone.negative,
  OvertimeRequest.statusCancelled => StatusTone.negative,
  OvertimeRequest.statusPending => StatusTone.info,
  _ => StatusTone.neutral,
};

class _StatusFilter extends StatelessWidget {
  const _StatusFilter({
    required this.value,
    required this.payrollOnly,
    required this.onStatus,
    required this.onPayrollOnly,
  });

  final String? value;
  final bool payrollOnly;
  final ValueChanged<String?> onStatus;
  final ValueChanged<bool> onPayrollOnly;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: Text('Status', style: theme.textTheme.labelLarge)),
            FilterChip(
              key: const ValueKey('overtime-payroll-filter'),
              label: const Text('Payroll only'),
              selected: payrollOnly,
              onSelected: onPayrollOnly,
            ),
          ],
        ),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              key: const ValueKey('overtime-status-filter'),
              isExpanded: true,
              value: value ?? '',
              items: const [
                DropdownMenuItem<String>(
                  value: '',
                  child: Text('All statuses'),
                ),
                DropdownMenuItem<String>(
                  value: OvertimeRequest.statusDraft,
                  child: Text('Draft'),
                ),
                DropdownMenuItem<String>(
                  value: OvertimeRequest.statusPending,
                  child: Text('Awaiting approval'),
                ),
                DropdownMenuItem<String>(
                  value: OvertimeRequest.statusApproved,
                  child: Text('Approved'),
                ),
                DropdownMenuItem<String>(
                  value: OvertimeRequest.statusRejected,
                  child: Text('Rejected'),
                ),
                DropdownMenuItem<String>(
                  value: OvertimeRequest.statusCancelled,
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
