import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/site_activity_report.dart';
import 'site_reports_controller.dart';

/// Every site activity report this session may read.
///
/// The row is built from the two facts a reviewer needs before opening
/// anything: *which day, at which site*, and *who says so*. The employee's
/// name is on the list rather than on the detail because it is the one
/// thing a manager scanning forty reports is actually comparing — and
/// because the server decides whose reports appear here, showing the name
/// it sent can never overstate it.
class SiteActivityListScreen extends ConsumerWidget {
  const SiteActivityListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // `GET /site-activity-reports` is behind `site_activity_reports.view`.
    // Gating before the list exists is what keeps "you may not read this"
    // from drawing as an empty list that looks like a decision.
    final canView = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canViewSiteActivityReports,
      ),
    );

    if (!canView) {
      return Scaffold(
        appBar: AppBar(title: const Text('Site reports')),
        body: const NoPermission(module: 'site activity reports'),
      );
    }

    final canCreate = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canCreateSiteActivityReports,
      ),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Site reports')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('new-site-report'),
              tooltip: 'File a site report',
              onPressed: () => context.push('/site-reports/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<SiteActivityReport>(
        provider: siteActivityReportsListProvider,
        emptyMessage: 'No site reports yet.',
        emptyHint:
            'A note about the work you did today appears here as soon as '
            'you file one.',
        filter: _ReportStatusFilter(
          value: ref.watch(
            siteActivityReportsListProvider.select(
              (state) => state.query['status'] as String?,
            ),
          ),
          onChanged: (status) => ref
              .read(siteActivityReportsListProvider.notifier)
              .setFilter('status', status),
        ),
        itemBuilder: (context, report, index) => ListTile(
          key: ValueKey('site-report-row-${report.id}'),
          title: Text('${report.reportDate} · ${report.siteName ?? 'Site'}'),
          subtitle: Text(
            [
              report.employeeName ?? 'You',
              report.workCategory,
              if (report.photos.isNotEmpty) '${report.photoCount} photo(s)',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: StatusChip(
            label: report.statusLabel,
            tone: report.isSubmitted ? StatusTone.positive : StatusTone.neutral,
          ),
          onTap: () => context.push('/site-reports/${report.id}'),
        ),
      ),
    );
  }
}

class _ReportStatusFilter extends StatelessWidget {
  const _ReportStatusFilter({required this.value, required this.onChanged});

  final String? value;

  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    return InputDecorator(
      decoration: const InputDecoration(
        border: OutlineInputBorder(),
        isDense: true,
        contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          key: const ValueKey('site-report-status-filter'),
          isExpanded: true,
          value: value ?? '',
          items: const [
            DropdownMenuItem<String>(value: '', child: Text('All statuses')),
            DropdownMenuItem<String>(
              value: SiteActivityReport.statusDraft,
              child: Text('Drafts only'),
            ),
            DropdownMenuItem<String>(
              value: SiteActivityReport.statusSubmitted,
              child: Text('Submitted only'),
            ),
          ],
          onChanged: onChanged,
        ),
      ),
    );
  }
}
