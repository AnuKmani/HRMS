import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../domain/department.dart';
import 'departments_controller.dart';

/// Every department, with a create button shown only to roles that would be
/// allowed to answer it.
///
/// The absence of the button is cosmetic and the server enforces the same
/// rule on `POST /departments` independently — see docs/SECURITY.md. A user
/// who forces the route and submits gets the API's 403, not a half-written
/// record.
class DepartmentsListScreen extends ConsumerWidget {
  const DepartmentsListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageDepartments),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Departments')),
      floatingActionButton: canManage
          ? FloatingActionButton(
              key: const ValueKey('add-department'),
              tooltip: 'Add department',
              onPressed: () => context.push('/departments/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Department>(
        provider: departmentsListProvider,
        searchHint: 'Search departments',
        emptyMessage: 'No departments yet.',
        emptyHint: 'Departments are the first thing an organisation chart needs.',
        itemBuilder: (context, department, index) => ListTile(
          key: ValueKey('department-${department.id}'),
          title: Text(department.name),
          subtitle: Text(department.summary),
          trailing: department.isActive ? null : const _InactiveTag(),
          onTap: () => canManage
              ? context.push('/departments/${department.id}')
              : null,
        ),
      ),
    );
  }
}

class _InactiveTag extends StatelessWidget {
  const _InactiveTag();

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
