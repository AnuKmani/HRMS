import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../domain/site.dart';
import 'sites_controller.dart';

const _siteStatuses = [
  StatusOption('active', 'Active'),
  StatusOption('inactive', 'Inactive'),
];

/// Every site the session is allowed to see.
///
/// Visibility follows the same server-side rule as projects: a Site
/// Supervisor sees the sites they run, not the whole estate.
class SitesListScreen extends ConsumerWidget {
  const SitesListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewSites),
    );
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageSites),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Sites')),
      floatingActionButton: canManage
          ? FloatingActionButton(
              key: const ValueKey('add-site'),
              tooltip: 'Add site',
              onPressed: () => context.push('/sites/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: !canView ? const _NoPermission() : const _SitesList(),
    );
  }
}

/// The list itself, split out so that reading `sitesListProvider` — which is
/// what schedules the first request — happens only in the branch where the
/// session may actually have the data. Watching it in the outer `build` would
/// have fetched the site list for a user the very next line tells cannot have
/// it.
class _SitesList extends ConsumerWidget {
  const _SitesList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusFilter = ref.watch(
      sitesListProvider.select((s) => s.query['status'] as String?),
    );
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewSites),
    );

    return PagedListView<Site>(
      provider: sitesListProvider,
      searchHint: 'Search sites, codes or addresses',
      emptyMessage: 'No sites match these filters.',
      filter: StatusFilter(
        value: statusFilter,
        options: _siteStatuses,
        onChanged: (value) =>
            ref.read(sitesListProvider.notifier).setFilter('status', value),
      ),
      itemBuilder: (context, site, index) => ListTile(
        key: ValueKey('site-${site.id}'),
        title: Text(site.name),
        subtitle: Text(site.summary),
        trailing: site.isActive ? null : const _InactiveTag(),
        onTap: canView ? () => context.push('/sites/${site.id}') : null,
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
              'You do not have permission to view sites.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
          ],
        ),
      ),
    );
  }
}
