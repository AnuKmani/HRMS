import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../domain/employee.dart';
import 'employees_controller.dart';

/// Statuses as `Employee::STATUSES` spells them on the server.
const _employeeStatuses = [
  StatusOption('active', 'Active'),
  StatusOption('inactive', 'Inactive'),
  StatusOption('on_leave', 'On leave'),
  StatusOption('resigned', 'Resigned'),
  StatusOption('terminated', 'Terminated'),
];

/// The workforce list.
///
/// The row is tappable only for a role that holds `employees.view`, and the
/// add button only for `employees.create`. Both are cosmetic in the strict
/// sense — `GET /api/v1/employees` is gated by `permission:employees.view`
/// and `POST` by `permission:employees.create`, so a hand-typed request gets
/// the API's 403 rather than data. What they buy is a screen that does not
/// offer a user something the server will refuse.
class EmployeesListScreen extends ConsumerWidget {
  const EmployeesListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scope = ref.watch(permissionScopeProvider);
    final canView = scope.canViewEmployees;
    final canCreate = scope.canCreateEmployees;

    return Scaffold(
      appBar: AppBar(title: const Text('Employees')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('add-employee'),
              tooltip: 'Add employee',
              onPressed: () => context.push('/employees/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: !canView ? const _NoPermission() : const _EmployeesList(),
    );
  }
}

/// The list itself, split out so that reading `employeesListProvider` — which
/// is what schedules the first request — can only happen in the branch where
/// the session may actually have the data.
///
/// Watching the provider in the outer `build` would have been shorter, and it
/// would have fetched the roster for a user the very next line tells cannot
/// have it: a 403-shaped request nobody needs, made before the refusal screen
/// is even drawn.
class _EmployeesList extends ConsumerWidget {
  const _EmployeesList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusFilter = ref.watch(
      employeesListProvider.select((s) => s.query['employment_status'] as String?),
    );
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewEmployees),
    );

    return PagedListView<Employee>(
      provider: employeesListProvider,
      searchHint: 'Search name, code, email or phone',
      emptyMessage: 'No employees match these filters.',
      emptyHint: 'Try a different search, or clear the status filter.',
      filter: StatusFilter(
        value: statusFilter,
        options: _employeeStatuses,
        onChanged: (value) =>
            ref.read(employeesListProvider.notifier).setFilter('employment_status', value),
      ),
      itemBuilder: (context, employee, index) => ListTile(
        key: ValueKey('employee-${employee.id}'),
        title: Text(
          employee.fullName.isEmpty ? employee.employeeCode : employee.fullName,
        ),
        subtitle: Text(employee.summary),
        trailing: employee.isActive ? null : const _StatusTag(),
        onTap: canView ? () => context.push('/employees/${employee.id}') : null,
      ),
    );
  }
}

class _StatusTag extends StatelessWidget {
  const _StatusTag();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Chip(
      label: const Text('Inactive'),
      visualDensity: VisualDensity.compact,
      backgroundColor: scheme.surfaceContainerHighest,
      side: BorderSide.none,
    );
  }
}

/// Drawn instead of a list when the session does not hold `employees.view`.
///
/// The list controller is never even built in this branch — asking the API
/// for something the session cannot have, only to paint the answer, would be
/// a request whose only possible outcomes are a 403 and a lie.
class _NoPermission extends StatelessWidget {
  const _NoPermission();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      key: const ValueKey('no-permission'),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.lock_outline, size: 48, color: theme.hintColor),
            const SizedBox(height: 12),
            Text(
              'You do not have permission to view employees.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
          ],
        ),
      ),
    );
  }
}
