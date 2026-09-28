import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_site_activity_repository.dart';
import '../domain/site_activity_report.dart';
import '../domain/site_activity_repository.dart';
import '../domain/site_report_photo.dart';
import 'report_photos_section.dart';

/// One filed (or half-filed) activity report, read-only.
///
/// Read-only is the whole design. A submitted report is closed to edits by
/// *anybody* — including its author, and the API enforces that with a 409
/// rather than a 403, because "you may not change a filed report" is a
/// statement about the document's state and not about the person's
/// rights. So this screen never offers an edit button for a submitted row,
/// and for a draft it offers one only to a session holding
/// `site_activity_reports.update`.
class SiteActivityDetailScreen extends ConsumerStatefulWidget {
  const SiteActivityDetailScreen({super.key, required this.reportId});

  final int reportId;

  @override
  ConsumerState<SiteActivityDetailScreen> createState() =>
      _SiteActivityDetailScreenState();
}

class _SiteActivityDetailScreenState
    extends ConsumerState<SiteActivityDetailScreen> {
  SiteActivityReport? _report;
  bool _loading = true;
  bool _forbidden = false;
  String? _banner;

  SiteActivityRepository get _repository =>
      ref.read(siteActivityRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gate before the fetch, exactly as the form does. A screen that is
    // going to draw "no permission" has no business asking the server for
    // the row first: the response is the same information the gate exists
    // to withhold, only with a request attached to it.
    if (ref.read(permissionScopeProvider).canViewSiteActivityReports) {
      _load();
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final report = await _repository.find(widget.reportId);
      if (!mounted) return;

      setState(() {
        _report = report;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = failure is ApiException
            ? failure.message
            : 'Something went wrong while loading this report.';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final canView = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canViewSiteActivityReports,
      ),
    );

    if (!canView || _forbidden) {
      return Scaffold(
        appBar: AppBar(title: const Text('Site report')),
        body: const NoPermission(module: 'site activity reports'),
      );
    }

    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Site report')),
        body: const Center(
          key: ValueKey('site-report-detail-loading'),
          child: CircularProgressIndicator(),
        ),
      );
    }

    final report = _report;

    if (report == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Site report')),
        body: Center(
          key: const ValueKey('site-report-detail-error'),
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.cloud_off, size: 40),
                const SizedBox(height: 16),
                Text(_banner ?? 'This report could not be opened.'),
                const SizedBox(height: 20),
                FilledButton(onPressed: _load, child: const Text('Try again')),
              ],
            ),
          ),
        ),
      );
    }

    final canUpdate = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canUpdateSiteActivityReports,
      ),
    );
    final editable = canUpdate && report.isEditable;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Site report'),
        actions: [
          StatusChip(
            label: report.statusLabel,
            tone: report.isSubmitted ? StatusTone.positive : StatusTone.neutral,
          ),
          const SizedBox(width: 12),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          key: const ValueKey('site-report-detail'),
          padding: const EdgeInsets.all(16),
          children: [
            _section(
              title: 'Where and when',
              children: [
                _fact('Date', report.reportDate),
                _fact('Site', report.siteName ?? '—'),
                _fact('Project', report.projectName ?? '—'),
                _fact('Reported by', report.employeeName ?? 'You'),
                if (report.submittedAt != null)
                  _fact('Submitted', report.submittedAt!),
              ],
            ),
            _section(
              title: 'Work',
              children: [
                _fact('Category', report.workCategory),
                _fact('Work performed', report.workPerformed),
                _progress(report),
              ],
            ),
            _section(
              title: 'Resources',
              children: [
                _fact('Manpower', report.manpower),
                _fact('Materials', report.materialsUsed),
                _fact('Equipment', report.equipmentUsed),
              ],
            ),
            _section(
              title: 'Safety, issues and remarks',
              children: [
                _fact('Safety issues', report.safetyIssues),
                _fact('Issues / blockers', report.issues),
                _fact('Remarks', report.remarks),
              ],
            ),
            _section(
              title: 'Evidence',
              children: [
                _fact(
                  'Location',
                  report.hasUsableGps
                      ? '${report.latitude!.toStringAsFixed(5)}, '
                            '${report.longitude!.toStringAsFixed(5)} '
                            '(±${report.gpsAccuracy!.round()} m)'
                      : 'Not recorded',
                ),
                ReportPhotosSection(
                  attached: List<SiteReportPhoto>.unmodifiable(report.photos),
                  pending: const [],
                  pathOf: (photo) => report.photoPath(photo.id),
                  // Photographs are managed from the form: the one place
                  // where adding and removing sit together, and where a
                  // removal can be undone by simply not saving.
                  onAdd: () {},
                  onRemoveAttached: (_) {},
                  onRemovePending: (_) {},
                  canEdit: false,
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (editable)
              FilledButton(
                key: const ValueKey('edit-site-report'),
                onPressed: () =>
                    context.push('/site-reports/${report.id}/edit'),
                child: const Text('Edit report'),
              ),
          ],
        ),
      ),
    );
  }

  Widget _section({required String title, required List<Widget> children}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 6),
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleSmall
                    ?.copyWith(color: Theme.of(context).colorScheme.primary),
              ),
            ),
            ...children,
          ],
        ),
      );

  Widget _fact(String label, String? value) {
    final text = (value == null || value.trim().isEmpty)
        ? 'Not recorded'
        : value;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: Theme.of(context).textTheme.labelSmall
                ?.copyWith(color: Theme.of(context).colorScheme.outline),
          ),
          const SizedBox(height: 2),
          Text(text, style: Theme.of(context).textTheme.bodyMedium),
        ],
      ),
    );
  }

  Widget _progress(SiteActivityReport report) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Progress',
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.outline,
            ),
          ),
          const SizedBox(height: 4),
          LinearProgressIndicator(
            key: const ValueKey('site-report-detail-progress'),
            value: report.progressPercentage / 100,
            minHeight: 8,
          ),
          const SizedBox(height: 4),
          Text(
            '${report.progressPercentage}% complete',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
