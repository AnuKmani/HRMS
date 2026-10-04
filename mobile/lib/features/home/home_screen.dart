import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/config/app_config.dart';
import '../../core/permissions/permission_scope.dart';
import '../../core/presentation/app_theme.dart';
import '../auth/auth_controller.dart';
import '../attendance/presentation/attendance_quick_card.dart';
import '../auth/auth_models.dart';
import '../dashboard/my_summary.dart';
import '../dashboard/my_summary_card.dart';
import '../notifications/presentation/notifications_controller.dart';

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
    // A failed count is not worth an error on the dashboard: the bell just
    // goes without a number until the next successful refresh.
    final unread = ref.watch(unreadNotificationsProvider).value ?? 0;

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
      // Who checked in, where and when — with the selfie. HR sees the
      // workforce; an employee sees their own history. Same door, and the
      // server decides whose rows are behind it.
      if (scope.canViewAttendance)
        _Module(
          'Attendance records',
          Icons.fact_check_outlined,
          '/attendance/records',
          'Check-ins, check-outs, locations and selfies',
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
      // The one-tap way to make someone able to check in: create the site
      // where HR is standing and post the employee to it.
      if (scope.canManageSites && scope.canManageAssignments)
        _Module(
          'Quick site setup',
          Icons.bolt,
          '/site-assignments/quick',
          'Set up an office or site here and assign staff',
        ),
      if (scope.canViewAssignments)
        _Module(
          'Site assignments',
          Icons.assignment_ind_outlined,
          '/site-assignments',
          'Who works at which site',
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
      // Phase 9. Money already spent, filed against a place and a project,
      // with the receipts that prove it — a different door from overtime's
      // because it is a different question: not "how long did you work?"
      // but "what did you spend, and who signed it off?".
      if (scope.showsExpensesModule)
        _Module(
          'Expenses',
          Icons.receipt_long_outlined,
          '/expenses',
          'Claims, receipts and approvals',
        ),
      // Phase 7. The two report trees are separate doors because they are
      // separate questions: one is "what did I do today?", the other is
      // "what is the site's day, as a document?". Only the second is
      // withheld from an Employee — see DailySiteReportListScreen for why,
      // and the seeders for which roles hold each.
      if (scope.canViewSiteActivityReports)
        _Module(
          'Site reports',
          Icons.assignment_outlined,
          '/site-reports',
          'What you did at a site, day by day',
        ),
      if (scope.canViewDailySiteReports)
        _Module(
          'Daily site reports',
          Icons.description_outlined,
          '/daily-reports',
          'The official record of a site-day',
        ),

      // Phase 8. Four doors, and four permissions — `payroll.view`,
      // `payroll.summary.view`, `salary_slips.view` and `loans.view` are
      // separate grants on purpose. The summary-only door is what lets
      // Management in at all (it gets totals and no rows), and the slips
      // door is a different question from the ledger: "what is my pay" and
      // "give me the document" are asked by different people for different
      // reasons, and a role may hold one without the other.
      if (scope.showsPayrollModule)
        _Module(
          'Payroll',
          Icons.account_balance_outlined,
          '/payroll',
          'Monthly runs, deductions and totals',
        ),
      if (scope.canViewSalarySlips)
        _Module(
          'Salary slips',
          Icons.receipt_long_outlined,
          '/salary-slips',
          'Your payslips, rendered when you ask',
        ),
      if (scope.canViewLoans)
        _Module(
          'Loans',
          Icons.account_balance_wallet_outlined,
          '/loans',
          'Advances and how they are being repaid',
        ),
      if (scope.canViewSalaryCertificates)
        _Module(
          'Salary certificates',
          Icons.workspace_premium_outlined,
          '/salary-certificates',
          'Proof of salary for a bank or landlord',
        ),

      // Phase 10. Two doors, two permissions — and the reason both are here
      // rather than one behind the other: `documents.view` is *your* file
      // (and a colleague's only with `documents.manage`), while
      // `onboarding.view` is a directory of who is joining. A person can
      // hold either, both, or neither, and hiding one behind the other
      // would say something untrue about which question they were asking.
      if (scope.showsDocumentsModule)
        _Module(
          'Documents',
          Icons.folder_outlined,
          '/documents',
          'Passports, IDs, visas and contracts',
        ),
      if (scope.showsOnboardingModule)
        _Module(
          'Onboarding',
          Icons.person_add_alt_outlined,
          '/onboarding',
          'Where new joiners stand',
        ),

      // Phase 11. Two doors, two permissions, and neither implies the
      // other: `training.view` reads a course history (and `Visibility`
      // narrows it to your own unless you hold `training.manage`), while
      // `assets.view` reads the register (and narrows it to the kit handed
      // to you without `assets.manage`). A Site Engineer holding the first
      // and HR holding the second are two people reading two different
      // pages — so they are two tiles, not one behind the other.
      if (scope.showsTrainingModule)
        _Module(
          'Training',
          Icons.school_outlined,
          '/training',
          'Courses, certificates and expiry',
        ),
      if (scope.canViewAssets)
        _Module(
          'Assets',
          Icons.inventory_2_outlined,
          '/assets',
          'Company kit and who is holding it',
        ),

      _Module(
        'Holidays',
        Icons.calendar_month_outlined,
        '/holidays',
        'Days the organisation has declared off',
      ),
    ];

    final sections = <String, List<_Module>>{};
    for (final module in modules) {
      sections.putIfAbsent(_sectionOf(module.label), () => []).add(module);
    }

    return Scaffold(
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          _Header(
            name: user?.name ?? '',
            subtitle: subtitle,
            email: user?.email ?? '',
            roles: user?.roles ?? const [],
            actions: [
              IconButton(
                key: const ValueKey('open-notifications'),
                tooltip: 'Notifications',
                color: Colors.white,
                icon: Badge(
                  isLabelVisible: unread > 0,
                  backgroundColor: const Color(0xFFFACC15),
                  textColor: const Color(0xFF1E1B4B),
                  label: Text(unread > 99 ? '99+' : '$unread'),
                  child: const Icon(Icons.notifications_outlined),
                ),
                onPressed: () => _open(context, ref, '/notifications'),
              ),
              PopupMenuButton<String>(
                key: const ValueKey('account-menu'),
                tooltip: 'Account',
                iconColor: Colors.white,
                icon: const Icon(Icons.account_circle_outlined),
                onSelected: (path) => _open(context, ref, path),
                itemBuilder: (context) => const [
                  PopupMenuItem(
                    key: ValueKey('change-password'),
                    value: '/account/password',
                    child: ListTile(
                      leading: Icon(Icons.password),
                      title: Text('Change password'),
                    ),
                  ),
                  PopupMenuItem(
                    key: ValueKey('signed-in-devices'),
                    value: '/account/sessions',
                    child: ListTile(
                      leading: Icon(Icons.devices_outlined),
                      title: Text('Signed-in devices'),
                    ),
                  ),
                ],
              ),
              IconButton(
                tooltip: 'Sign out',
                color: Colors.white,
                icon: const Icon(Icons.logout),
                onPressed: () =>
                    ref.read(authControllerProvider.notifier).logout(),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // The working day's one button. Not permission-gated, for
                // the same reason the Attendance tile is not: recording your
                // own day is not a privilege, and the API enforces the same
                // rule.
                if (user?.employee != null)
                  AttendanceQuickCard(
                    onOpen: (path) => _open(context, ref, path),
                  ),
                if (scope.canViewDashboard)
                  MySummaryCard(onOpen: (path) => _open(context, ref, path)),
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
                  for (final section in _sectionOrder)
                    if (sections[section] case final list?) ...[
                      Padding(
                        padding: const EdgeInsets.fromLTRB(4, 8, 4, 10),
                        child: Text(
                          section,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: const Color(0xFF1E1B4B),
                          ),
                        ),
                      ),
                      LayoutBuilder(
                        builder: (context, constraints) => GridView.count(
                          crossAxisCount: constraints.maxWidth >= 600 ? 4 : 2,
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          mainAxisSpacing: 12,
                          crossAxisSpacing: 12,
                          childAspectRatio: 1.05,
                          padding: const EdgeInsets.only(bottom: 12),
                          children: [
                            for (final module in list)
                              _ModuleTile(
                                module: module,
                                onTap: () => _open(context, ref, module.path),
                              ),
                          ],
                        ),
                      ),
                    ],
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'HRMS app · build ${AppConfig.build}',
                    key: const ValueKey('app-build'),
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: const Color(0xFF94A3B8),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Every way off the dashboard comes back through here, so the bell's
  /// number and the summary are re-read on return — approving a leave request or opening a
  /// payslip can change it without the inbox ever being visited.
  Future<void> _open(BuildContext context, WidgetRef ref, String path) async {
    await context.push(path);
    if (!context.mounted) return;
    ref
      ..invalidate(unreadNotificationsProvider)
      ..invalidate(mySummaryProvider)
      ..invalidate(attendanceTodayProvider);
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

  Color get color => _colorOf(label);
}

const _sectionOrder = <String>[
  'My day',
  'People',
  'Projects & sites',
  'Pay & money',
  'Company',
];

String _sectionOf(String label) => switch (label) {
  'Attendance' ||
  'Attendance records' ||
  'Leave' ||
  'Timesheets' ||
  'Overtime' ||
  'Holidays' => 'My day',
  'Employees' ||
  'Departments' ||
  'Designations' ||
  'Onboarding' ||
  'Documents' ||
  'Training' => 'People',
  'Projects' ||
  'Sites' ||
  'Site assignments' ||
  'Quick site setup' ||
  'Site reports' ||
  'Daily site reports' => 'Projects & sites',
  'Payroll' ||
  'Salary slips' ||
  'Loans' ||
  'Salary certificates' ||
  'Expenses' => 'Pay & money',
  _ => 'Company',
};

Color _colorOf(String label) => switch (label) {
  'Attendance' || 'Attendance records' => ModuleColors.attendance,
  'Leave' => ModuleColors.leave,
  'Timesheets' || 'Overtime' => ModuleColors.time,
  'Holidays' => ModuleColors.calendar,
  'Employees' || 'Onboarding' => ModuleColors.people,
  'Departments' || 'Designations' => ModuleColors.org,
  'Documents' => ModuleColors.compliance,
  'Training' => ModuleColors.learning,
  'Projects' => ModuleColors.projects,
  'Sites' || 'Site assignments' || 'Quick site setup' => ModuleColors.sites,
  'Site reports' || 'Daily site reports' => ModuleColors.reports,
  'Payroll' || 'Salary slips' || 'Salary certificates' => ModuleColors.pay,
  'Loans' || 'Expenses' => ModuleColors.money,
  _ => ModuleColors.assets,
};

class _ModuleTile extends StatelessWidget {
  const _ModuleTile({required this.module, required this.onTap});

  final _Module module;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = module.color;
    final theme = Theme.of(context);

    return Material(
      key: ValueKey('module-${module.label}'),
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      elevation: 0,
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: onTap,
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: color.withValues(alpha: 0.25)),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [color.withValues(alpha: 0.14), Colors.white],
            ),
          ),
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: BorderRadius.circular(14),
                  boxShadow: [
                    BoxShadow(
                      color: color.withValues(alpha: 0.35),
                      blurRadius: 10,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Icon(module.icon, color: Colors.white, size: 24),
              ),
              const Spacer(),
              Text(
                module.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: const Color(0xFF1E1B4B),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                module.subtitle,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: const Color(0xFF64748B),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.name,
    required this.subtitle,
    required this.email,
    required this.roles,
    required this.actions,
  });

  final String name;
  final String? subtitle;
  final String email;
  final List<String> roles;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initials = name
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .take(2)
        .map((part) => part[0].toUpperCase())
        .join();

    return Container(
      decoration: const BoxDecoration(
        gradient: AppTheme.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 8, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(
                    'HRMS',
                    style: theme.textTheme.titleLarge?.copyWith(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 1.2,
                    ),
                  ),
                  const Spacer(),
                  ...actions,
                ],
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: Colors.white.withValues(alpha: 0.22),
                    child: Text(
                      initials.isEmpty ? '?' : initials,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Welcome back',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: Colors.white.withValues(alpha: 0.85),
                          ),
                        ),
                        Text(
                          name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.headlineSmall?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        if (subtitle != null)
                          Text(
                            subtitle!,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: Colors.white.withValues(alpha: 0.85),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  for (final role in roles)
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        role,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  Text(
                    'Signed in as $email',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.75),
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
