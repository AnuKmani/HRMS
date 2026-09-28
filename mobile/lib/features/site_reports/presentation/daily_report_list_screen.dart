import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/daily_site_report.dart';
import 'daily_reports_controller.dart';

/// Every official site-day document this session may read.
///
/// Deliberately *not* an Employee's screen: `daily_site_reports.view` is
/// granted to the six roles that prepare or review the document and is
/// withheld from the role that supplies most of the workforce, because the
/// document exists for a review the person filling it is not part of. The
/// gate below is the same permission the server's `permission:` middleware
/// will check, and it is here so that the honest refusal draws as a refusal
/// rather than as an empty list.
class DailySiteReportListScreen extends ConsumerWidget {
  const DailySiteReportListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewDailySiteReports),
    );

    if (!canView) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily site reports')),
        body: const NoPermission(module: 'daily site reports'),
      );
    }

    final canCreate = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canCreateDailySiteReports,
      ),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Daily site reports')),
      floatingActionButton: canCreate
          ? FloatingActionButton(
              key: const ValueKey('new-daily-report'),
              tooltip: 'Prepare the daily report',
              onPressed: () => context.push('/daily-reports/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<DailySiteReport>(
        provider: dailySiteReportsListProvider,
        emptyMessage: 'No daily reports yet.',
        emptyHint:
            'One document per site per day. It appears here as soon as '
            'somebody starts it.',
        filter: _DailyReportStatusFilter(
          value: ref.watch(
            dailySiteReportsListProvider.select(
              (state) => state.query['status'] as String?,
            ),
          ),
          onChanged: (status) => ref
              .read(dailySiteReportsListProvider.notifier)
              .setFilter('status', status),
        ),
        itemBuilder: (context, report, index) => ListTile(
          key: ValueKey('daily-report-row-${report.id}'),
          title: Text('${report.reportDate} · ${report.siteName ?? 'Site'}'),
          subtitle: Text(
            [
              'By ${report.creatorName ?? 'someone'}',
              '${report.displayTotalManpower} on site',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: StatusChip(
            label: report.statusLabel,
            tone: report.isSubmitted ? StatusTone.positive : StatusTone.neutral,
          ),
          onTap: () => context.push('/daily-reports/${report.id}'),
        ),
      ),
    );
  }
}

class _DailyReportStatusFilter extends StatelessWidget {
  const _DailyReportStatusFilter({
    required this.value,
    required this.onChanged,
  });

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
          key: const ValueKey('daily-report-status-filter'),
          isExpanded: true,
          value: value ?? '',
          items: const [
            DropdownMenuItem<String>(value: '', child: Text('All statuses')),
            DropdownMenuItem<String>(
              value: DailySiteReport.statusDraft,
              child: Text('Drafts only'),
            ),
            DropdownMenuItem<String>(
              value: DailySiteReport.statusSubmitted,
              child: Text('Submitted only'),
            ),
          ],
          onChanged: onChanged,
        ),
      ),
    );
  }
}
