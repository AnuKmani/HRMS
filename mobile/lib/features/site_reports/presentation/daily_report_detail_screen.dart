import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_daily_site_report_repository.dart';
import '../domain/daily_site_report.dart';
import '../domain/daily_site_report_repository.dart';
import '../domain/site_report_photo.dart';
import 'report_pdf_opener.dart';
import 'report_photos_section.dart';

/// One official site-day document, and the only place it can be exported.
///
/// The PDF is **rendered when it is asked for** — there is no stored file,
/// no download URL and no "previously generated" timestamp, because a
/// document copied out of a row that has since been corrected is worse
/// than no document at all. That decision shows up here as an honest
/// loading state: this button is a network round-trip dressed as a file
/// open, and pretending otherwise would leave a person staring at a frozen
/// viewer wondering whether the app had hung.
class DailySiteReportDetailScreen extends ConsumerStatefulWidget {
  const DailySiteReportDetailScreen({super.key, required this.reportId});

  final int reportId;

  @override
  ConsumerState<DailySiteReportDetailScreen> createState() =>
      _DailySiteReportDetailScreenState();
}

class _DailySiteReportDetailScreenState
    extends ConsumerState<DailySiteReportDetailScreen> {
  DailySiteReport? _report;
  bool _loading = true;
  bool _forbidden = false;
  bool _exporting = false;
  String? _banner;
  String? _pdfError;

  DailySiteReportRepository get _repository =>
      ref.read(dailySiteReportRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gate before the fetch; see the same note on the activity detail.
    if (ref.read(permissionScopeProvider).canViewDailySiteReports) {
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

  /// The whole download UX, in one method and one error surface.
  ///
  /// The status codes are not distinguished by rewriting the message —
  /// the server's own sentence is already the right one for a 403 and a
  /// 500 alike — but they *are* distinguished by what happens next: a 401
  /// has already been handled by the client's session hook (the app signs
  /// itself out), and a 403 is left on screen rather than retried, because
  /// asking again would get the same refusal.
  Future<void> _downloadPdf() async {
    if (_exporting || _report == null) return;

    setState(() {
      _exporting = true;
      _pdfError = null;
    });

    try {
      await ref
          .read(reportPdfOpenerProvider)
          .open(
            _report!.id,
            filename: reportPdfFilename(_report!.id, _report!.reportDate),
          );
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _pdfError = failure.isUnauthenticated
            ? 'Your session has ended. Sign in again to download this '
                  'report.'
            : failure.isValidation
            ? 'The server could not build this report. Check it is '
                  'complete, then try again.'
            : failure.statusCode == 403
            ? 'You are not allowed to export this report.'
            : failure.message;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _pdfError =
            'The report could not be opened. Check your connection and '
            'try again.';
      });
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewDailySiteReports),
    );

    if (!canView || _forbidden) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily site report')),
        body: const NoPermission(module: 'daily site reports'),
      );
    }

    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily site report')),
        body: const Center(
          key: ValueKey('daily-report-detail-loading'),
          child: CircularProgressIndicator(),
        ),
      );
    }

    final report = _report;

    if (report == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily site report')),
        body: Center(
          key: const ValueKey('daily-report-detail-error'),
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
        (scope) => scope.canUpdateDailySiteReports,
      ),
    );
    // Exporting is its own permission, held by six roles and withheld from
    // the same six plus Management's `view`. Gating here means the button
    // is absent rather than present-and-refused.
    final canExport = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canExportDailySiteReports,
      ),
    );

    final editable = canUpdate && report.isEditable;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Daily site report'),
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
          key: const ValueKey('daily-report-detail'),
          padding: const EdgeInsets.all(16),
          children: [
            _section(
              title: 'Where and when',
              children: [
                _fact('Date', report.reportDate),
                _fact('Site', report.siteName ?? '—'),
                _fact('Project', report.projectName ?? '—'),
                _fact('Prepared by', report.creatorName ?? '—'),
                if (report.submittedAt != null)
                  _fact('Submitted', report.submittedAt!),
                if (report.approvedAt != null)
                  _fact('Approved', report.approvedAt!),
              ],
            ),
            _section(
              title: 'Workforce',
              children: [
                _fact('Total on site', '${report.displayTotalManpower} people'),
                if (report.manpower.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Card(
                    key: const ValueKey('daily-report-manpower'),
                    margin: EdgeInsets.zero,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        children: [
                          for (final row in report.manpower)
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              child: Row(
                                children: [
                                  Expanded(child: Text(row.category)),
                                  Text(
                                    '${row.count}',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
            _section(
              title: 'Planned and completed',
              children: [
                _fact('Work planned', report.workPlanned),
                _fact('Work completed', report.workCompleted),
              ],
            ),
            _section(
              title: 'Materials',
              children: report.materials.isEmpty
                  ? [const _EmptyLine()]
                  : [
                      Card(
                        key: const ValueKey('daily-report-materials'),
                        margin: EdgeInsets.zero,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            children: [
                              for (final row in report.materials)
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 4,
                                  ),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          [
                                            row.name,
                                            if (row.quantity != null)
                                              '${row.quantityLabel} ${row.unit ?? ''}',
                                          ].join(' — '),
                                        ),
                                      ),
                                      if (row.remarks != null &&
                                          row.remarks!.isNotEmpty)
                                        Text(
                                          row.remarks!,
                                          style: Theme.of(context)
                                              .textTheme
                                              .bodySmall,
                                        ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    ],
            ),
            _section(
              title: 'Equipment',
              children: report.equipment.isEmpty
                  ? [const _EmptyLine()]
                  : [
                      Card(
                        key: const ValueKey('daily-report-equipment'),
                        margin: EdgeInsets.zero,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            children: [
                              for (final row in report.equipment)
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 4,
                                  ),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          [
                                            row.name,
                                            '×${row.quantityLabel}',
                                            if (row.operatingHours != null)
                                              '${row.operatingHours!.toStringAsFixed(1)} h',
                                          ].join(' '),
                                        ),
                                      ),
                                      if (row.condition != null &&
                                          row.condition!.isNotEmpty)
                                        Text(
                                          row.condition!,
                                          style: Theme.of(context)
                                              .textTheme
                                              .bodySmall,
                                        ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    ],
            ),
            _section(
              title: 'Safety, delays, issues and remarks',
              children: [
                _fact('Safety observations', report.safetyObservations),
                _fact('Delays', report.delays),
                _fact('Issues', report.issues),
                _fact('Remarks', report.remarks),
              ],
            ),
            _section(
              title: 'Photographs',
              children: [
                ReportPhotosSection(
                  label: 'Attached',
                  attached: List<SiteReportPhoto>.unmodifiable(report.photos),
                  pending: const [],
                  pathOf: (photo) => report.photoPath(photo.id),
                  onAdd: () {},
                  onRemoveAttached: (_) {},
                  onRemovePending: (_) {},
                  canEdit: false,
                  emptyHint: 'No photographs were attached to this report.',
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (canExport) ...[
              FilledButton.icon(
                key: const ValueKey('download-daily-report-pdf'),
                onPressed: _exporting ? null : _downloadPdf,
                icon: _exporting
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.picture_as_pdf_outlined),
                label: Text(_exporting ? 'Preparing…' : 'Download PDF'),
              ),
              if (_pdfError != null) ...[
                const SizedBox(height: 8),
                Text(
                  _pdfError!,
                  key: const ValueKey('daily-report-pdf-error'),
                  style: Theme.of(context).textTheme.bodySmall
                      ?.copyWith(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: 8),
              Text(
                'Rendered from the report as it stands right now, then '
                'opened from this device. Nothing is stored on the server.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 16),
            ],
            if (editable)
              FilledButton(
                key: const ValueKey('edit-daily-report'),
                onPressed: () =>
                    context.push('/daily-reports/${report.id}/edit'),
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
}

/// "Nothing was recorded for this section" — said out loud rather than
/// left as a heading with nothing under it, which reads as an omission
/// instead of an answer.
class _EmptyLine extends StatelessWidget {
  const _EmptyLine();

  @override
  Widget build(BuildContext context) => Text(
    'Not recorded',
    style: Theme.of(context).textTheme.bodySmall
        ?.copyWith(color: Theme.of(context).colorScheme.outline),
  );
}
