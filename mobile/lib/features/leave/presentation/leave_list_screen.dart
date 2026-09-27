import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/leave_request.dart';
import 'leave_controller.dart';

/// Every leave request this session may read, newest decisions first.
///
/// Two doors are drawn conditionally — *Apply leave* and *Balances* — and
/// both are courtesy only: `POST /leave` is behind `permission:leave.create`
/// and `GET /leave-balances` behind `permission:leave.balance.view` whether or
/// not the button was drawn. Somebody who forces the route gets the API's 403
/// and an honest message, not a half-written record (see docs/SECURITY.md).
///
/// There is deliberately no search box: `/leave` has no `search` parameter,
/// and a text field that did nothing but change the query string would be
/// worse than no field at all.
class LeaveListScreen extends ConsumerWidget {
  const LeaveListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewLeave),
    );
    final canApply = ref.watch(
      permissionScopeProvider.select((scope) => scope.canCreateLeave),
    );
    final canSeeBalances = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewLeaveBalances),
    );

    return Scaffold(
      appBar: AppBar(
        title: const Text('Leave'),
        actions: [
          if (canSeeBalances)
            IconButton(
              key: const ValueKey('leave-balances'),
              tooltip: 'Leave balances',
              icon: const Icon(Icons.account_balance_wallet_outlined),
              onPressed: () => context.push('/leave-balances'),
            ),
        ],
      ),
      floatingActionButton: canApply
          ? FloatingActionButton(
              key: const ValueKey('apply-leave'),
              tooltip: 'Apply leave',
              onPressed: () => context.push('/leave/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: canView ? const _LeaveList() : const NoPermission(module: 'leave'),
    );
  }
}

/// The list itself, reached only when the session holds `leave.view`.
///
/// Split out rather than nested inside the Scaffold: the list controller is
/// a `Notifier`, and a session without the permission still pays for
/// building it before the gate has said no.
class _LeaveList extends ConsumerWidget {
  const _LeaveList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PagedListView<LeaveRequest>(
      provider: leaveListProvider,
      emptyMessage: 'No leave requests yet.',
      emptyHint: 'Applied leave will appear here with its approval status.',
      filter: _StatusFilter(
        value: ref.watch(
          leaveListProvider.select((s) => s.query['status'] as String?),
        ),
        onSelected: (status) =>
            ref.read(leaveListProvider.notifier).setFilter('status', status),
      ),
      itemBuilder: (context, request, index) => ListTile(
        key: ValueKey('leave-row-${request.id}'),
        title: Text(
          request.leaveTypeName == null
              ? request.dateRange
              : '${request.leaveTypeName} · ${request.dateRange}',
        ),
        subtitle: Text(
          '${request.requestedDays.toStringAsFixed(1)} days · '
          '${request.employeeName ?? 'You'}',
        ),
        trailing: StatusChip(
          label: request.statusLabel,
          tone: toneForLeaveStatus(request.status),
        ),
        onTap: () => context.push('/leave/${request.id}'),
      ),
    );
  }
}

/// The colour a leave status earns, in one place so the list, the detail
/// screen and the balance view cannot drift into three readings of `lop`.
///
/// The detail screen imports it too, so a request never wears two colours
/// for the same status depending on which screen you arrived from.
StatusTone toneForLeaveStatus(String status) => switch (status) {
  LeaveRequest.statusApproved => StatusTone.positive,
  LeaveRequest.statusRejected => StatusTone.negative,
  LeaveRequest.statusCancelled => StatusTone.negative,
  LeaveRequest.statusLop => StatusTone.negative,
  LeaveRequest.statusPending => StatusTone.info,
  _ => StatusTone.neutral,
};

/// The status filter — one dropdown rather than a row of chips, because six
/// options is more than a phone row can show without scrolling sideways,
/// and a sideways-scrolling filter is one nobody discovers.
///
/// `''` means "no filter", which is not the same as sending `status=`: the
/// controller drops the key entirely, because the API treats an absent
/// parameter and an empty one as different questions.
class _StatusFilter extends StatelessWidget {
  const _StatusFilter({required this.value, required this.onSelected});

  final String? value;

  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // A plain `DropdownButton` rather than `DropdownButtonFormField`: this
    // app has no `Form` to belong to, and the field's own state would be a
    // second copy of the selection the provider already owns — one that
    // stops tracking the moment the list is reset from anywhere else.
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
              key: const ValueKey('leave-status-filter'),
              isExpanded: true,
              value: value ?? '',
              items: const [
                DropdownMenuItem<String>(
                  value: '',
                  child: Text('All statuses'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusDraft,
                  child: Text('Draft'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusPending,
                  child: Text('Awaiting approval'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusApproved,
                  child: Text('Approved'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusRejected,
                  child: Text('Rejected'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusCancelled,
                  child: Text('Cancelled'),
                ),
                DropdownMenuItem<String>(
                  value: LeaveRequest.statusLop,
                  child: Text('Loss of pay'),
                ),
              ],
              onChanged: onSelected,
            ),
          ),
        ),
      ],
    );
  }
}
