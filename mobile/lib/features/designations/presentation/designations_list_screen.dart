import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../domain/designation.dart';
import 'designations_controller.dart';

/// Every designation, with creation available only to roles allowed to
/// answer `POST /designations`.
class DesignationsListScreen extends ConsumerWidget {
  const DesignationsListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageDesignations),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Designations')),
      floatingActionButton: canManage
          ? FloatingActionButton(
              key: const ValueKey('add-designation'),
              tooltip: 'Add designation',
              onPressed: () => context.push('/designations/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Designation>(
        provider: designationsListProvider,
        searchHint: 'Search designations',
        emptyMessage: 'No designations yet.',
        emptyHint: 'A designation is a job title attached to a department.',
        itemBuilder: (context, designation, index) => ListTile(
          key: ValueKey('designation-${designation.id}'),
          title: Text(designation.name),
          subtitle: Text(designation.summary),
          trailing: designation.isActive ? null : const _InactiveTag(),
          onTap: canManage
              ? () => context.push('/designations/${designation.id}')
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
