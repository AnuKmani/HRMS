import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../domain/project.dart';
import 'projects_controller.dart';

final _projectStatuses = [
  for (final (value, label) in projectStatuses) StatusOption(value, label),
];

/// Every project the session is allowed to see.
///
/// `Visibility::projectsAndSites()` narrows this server-side for Site
/// Supervisor and Site Engineer to the work they actually run, so two people
/// on the same list screen can honestly be looking at different projects —
/// and neither is told the other's exist.
class ProjectsListScreen extends ConsumerWidget {
  const ProjectsListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewProjects),
    );
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageProjects),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Projects')),
      floatingActionButton: canManage
          ? FloatingActionButton(
              key: const ValueKey('add-project'),
              tooltip: 'Add project',
              onPressed: () => context.push('/projects/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: !canView ? const _NoPermission() : const _ProjectsList(),
    );
  }
}

/// The list itself, split out so that reading `projectsListProvider` — which
/// is what schedules the first request — happens only in the branch where the
/// session may actually have the data. Watching it in the outer `build` would
/// have fetched the project list for a user the very next line tells cannot
/// have it.
class _ProjectsList extends ConsumerWidget {
  const _ProjectsList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusFilter = ref.watch(
      projectsListProvider.select((s) => s.query['status'] as String?),
    );
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewProjects),
    );

    return PagedListView<Project>(
      provider: projectsListProvider,
      searchHint: 'Search projects, codes or clients',
      emptyMessage: 'No projects match these filters.',
      filter: StatusFilter(
        value: statusFilter,
        options: _projectStatuses,
        onChanged: (value) =>
            ref.read(projectsListProvider.notifier).setFilter('status', value),
      ),
      itemBuilder: (context, project, index) {
        final next = project.upcomingEvents.isEmpty
            ? null
            : project.upcomingEvents.first;
        return ListTile(
          key: ValueKey('project-${project.id}'),
          isThreeLine: next != null,
          title: Text(project.name),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                project.projectManagerName == null
                    ? project.code
                    : '${project.code} · PM: ${project.projectManagerName}',
              ),
              if (next != null)
                Row(
                  key: ValueKey('project-next-event-${project.id}'),
                  children: [
                    const Icon(Icons.event, size: 16, color: Colors.deepOrange),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(
                        '${next.label} · ${next.prettyDate}'
                        '${project.upcomingEvents.length > 1 ? ' (+${project.upcomingEvents.length - 1} more)' : ''}',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
            ],
          ),
          trailing: _StatusTag(project.status),
          onTap: canView ? () => context.push('/projects/${project.id}') : null,
        );
      },
    );
  }
}

class _StatusTag extends StatelessWidget {
  const _StatusTag(this.status);

  final String status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = switch (status) {
      'active' => const Color(0xFF2563EB),
      'handing_over' => const Color(0xFFF59E0B),
      'completed' => const Color(0xFF16A34A),
      _ => scheme.outline,
    };

    return Chip(
      key: ValueKey('project-status-$status'),
      label: Text(
        projectStatusLabel(status),
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w600,
        ),
      ),
      visualDensity: VisualDensity.compact,
      backgroundColor: color,
      side: BorderSide.none,
    );
  }
}

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
              'You do not have permission to view projects.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
          ],
        ),
      ),
    );
  }
}
