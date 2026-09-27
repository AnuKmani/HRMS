import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/permissions/permission_scope.dart';
import '../auth/auth_controller.dart';
import '../auth/auth_models.dart';

/// Where a signed-in user lands, and the way into each module.
///
/// The tiles are filtered by [PermissionScope] — a user who cannot read
/// departments is not offered the door to them. That filtering is a
/// courtesy, not a control: `GET /api/v1/departments` is behind
/// `permission:departments.view` whether or not the tile was drawn, so
/// arriving at the URL by hand gets the API's 403 and an honest message
/// rather than data.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final scope = ref.watch(permissionScopeProvider);
    final user = auth.user;
    final subtitle = _subtitle(user?.employee);
    final theme = Theme.of(context);

    final modules = <_Module>[
      // First, and not gated on a permission: recording your own day is not
      // something a role grants. Every account with an employee record gets
      // this door — the API behind it enforces exactly the same rule.
      if (user?.employee != null)
        _Module(
          'Attendance',
          Icons.schedule_outlined,
          '/attendance',
          'Check in, visit a site, check out',
        ),
      if (scope.canViewEmployees)
        _Module(
          'Employees',
          Icons.people_outline,
          '/employees',
          'Everyone in the directory',
        ),
      if (scope.canViewDepartments)
        _Module(
          'Departments',
          Icons.account_tree_outlined,
          '/departments',
          'The organisation chart',
        ),
      if (scope.canViewDesignations)
        _Module(
          'Designations',
          Icons.badge_outlined,
          '/designations',
          'Job titles and grades',
        ),
      if (scope.canViewProjects)
        _Module(
          'Projects',
          Icons.folder_outlined,
          '/projects',
          'Work in flight',
        ),
      if (scope.canViewSites)
        _Module(
          'Sites',
          Icons.location_on_outlined,
          '/sites',
          'Where the work happens',
        ),

      // Phase 6. `Leave` and `Timesheets` are permission-gated like the
      // modules above; `Holidays` is not, because the calendar is readable by
      // every signed-in account and there is no `holidays.view` to be missing.
      if (scope.canViewLeave)
        _Module(
          'Leave',
          Icons.beach_access_outlined,
          '/leave',
          'Apply, approve and track time off',
        ),
      if (scope.canViewTimesheets)
        _Module(
          'Timesheets',
          Icons.table_chart_outlined,
          '/timesheets',
          'Working time, derived from attendance',
        ),
      if (scope.canViewOvertime)
        _Module(
          'Overtime',
          Icons.hourglass_bottom_outlined,
          '/overtime',
          'Extra hours and their approvals',
        ),
      _Module(
        'Holidays',
        Icons.calendar_month_outlined,
        '/holidays',
        'Days the organisation has declared off',
      ),
    ];

    return Scaffold(
      appBar: AppBar(
        title: const Text('HRMS'),
        actions: [
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(authControllerProvider.notifier).logout(),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Text('Welcome back', style: theme.textTheme.titleMedium),
          ),
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Text(user?.name ?? '', style: theme.textTheme.headlineSmall),
          ),
          if (subtitle != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(subtitle, style: theme.textTheme.bodyMedium),
            ),
          Padding(
            padding: const EdgeInsets.only(bottom: 24),
            child: Text(
              'Signed in as ${user?.email ?? ''}',
              style: theme.textTheme.bodySmall,
            ),
          ),
          if (modules.isEmpty)
            Padding(
              key: const ValueKey('no-modules'),
              padding: const EdgeInsets.all(24),
              child: Text(
                'This account has no modules available yet. Ask an '
                'administrator for a role.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
            )
          else
            for (final module in modules)
              Card(
                margin: const EdgeInsets.only(bottom: 12),
                child: ListTile(
                  key: ValueKey('module-${module.label}'),
                  leading: Icon(module.icon),
                  title: Text(module.label),
                  subtitle: Text(module.subtitle),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push(module.path),
                ),
              ),
        ],
      ),
    );
  }

  String? _subtitle(EmployeeBrief? employee) {
    if (employee == null) return null;

    final parts = <String>[
      if (employee.designation?.isNotEmpty ?? false) employee.designation!,
      if (employee.department?.isNotEmpty ?? false) employee.department!,
    ];

    return parts.isEmpty ? null : parts.join(' · ');
  }
}

class _Module {
  const _Module(this.label, this.icon, this.path, this.subtitle);

  final String label;
  final IconData icon;
  final String path;
  final String subtitle;
}
