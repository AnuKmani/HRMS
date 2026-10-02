import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_training_repository.dart';
import '../domain/training_compliance.dart';
import '../domain/training_repository.dart';

/// The whole workforce's training position, on one screen.
///
/// Behind `training.view` — the same door as the list — because the report
/// is about the same people, only asked as a total instead of as rows. What
/// changes between two readers is *whose* total: the counts are scoped on
/// the server before they are summed, so a project manager gets their own
/// four numbers and a blank catalogue rather than the company's.
///
/// Four things this screen is careful about:
///
///  - **every number is printed with words, never a bare colour.** "3
///    expiring soon" is a sentence; a red tile is a colour, and a colour
///    alone tells a colour-blind reader nothing about which way it points.
///
///  - **the catalogue is drawn even where the count is zero.** A course
///    nobody has sat is still a course this company runs, and a table that
///    dropped it would look like the operator had mislaid a programme.
///
///  - **nothing here is recomputed client-side.** The four certificate
///    buckets arrive from the server's own date arithmetic, so the report
///    cannot disagree with the nightly scan that lapses the same rows.
///
///  - **`generated_at` is shown, not hidden.** A total without a time is a
///    total that could be from yesterday.
class TrainingComplianceScreen extends ConsumerStatefulWidget {
  const TrainingComplianceScreen({super.key});

  @override
  ConsumerState<TrainingComplianceScreen> createState() =>
      _TrainingComplianceScreenState();
}

class _TrainingComplianceScreenState
    extends ConsumerState<TrainingComplianceScreen> {
  TrainingCompliance? _report;
  bool _loading = false;
  String? _banner;

  TrainingRepository get _repository => ref.read(trainingRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gated before the fetch, not just before the build: a URL typed by
    // hand would otherwise ask the API for totals the session was never
    // going to be shown, and the answer to that question is a 403 with
    // nothing to do about it.
    if (ref.read(permissionScopeProvider).canViewTraining) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final report = await _repository.compliance();
      if (!mounted) return;

      setState(() {
        _report = report;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = _messageFor(failure);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewTraining))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Training compliance')),
        body: const NoPermission(module: 'training compliance'),
      );
    }

    final report = _report;

    return Scaffold(
      appBar: AppBar(title: const Text('Training compliance')),
      body: _loading && report == null
          ? const Center(
              child: CircularProgressIndicator(
                key: ValueKey('compliance-loading'),
              ),
            )
          : report == null
          ? _failed()
          : RefreshIndicator(onRefresh: _load, child: _body(report)),
    );
  }

  Widget _failed() => Center(
    child: Padding(
      padding: const EdgeInsets.all(32),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.error_outline,
            size: 48,
            color: Theme.of(context).hintColor,
          ),
          const SizedBox(height: 12),
          Text(
            _banner ?? 'The report could not be loaded.',
            key: const ValueKey('compliance-error'),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 12),
          FilledButton(
            key: const ValueKey('compliance-retry'),
            onPressed: _load,
            child: const Text('Try again'),
          ),
        ],
      ),
    ),
  );

  Widget _body(TrainingCompliance report) {
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('compliance-body'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: [
        if (_banner != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _Banner(message: _banner!, onRetry: _load),
          ),
        _Section(
          title: 'Certificates',
          subtitle: 'What is valid today, and what needs a fresh card',
          rows: [
            _row(
              label: 'Valid',
              value: report.validCertificates,
              tone: StatusTone.positive,
              icon: Icons.event_available_outlined,
            ),
            _row(
              label: 'Expiring within ${report.warningDays} days',
              value: report.expiringCertificates,
              tone: StatusTone.warning,
              icon: Icons.schedule_outlined,
            ),
            _row(
              label: 'Expired',
              value: report.expiredCertificates,
              tone: StatusTone.negative,
              icon: Icons.event_busy_outlined,
            ),
            _row(
              label: 'No expiry date',
              value: report.certificatesWithoutExpiry,
              tone: StatusTone.neutral,
              icon: Icons.event_outlined,
            ),
          ],
        ),
        const SizedBox(height: 16),
        _Section(
          title: 'Enrolments',
          subtitle: 'Where the ${report.totalEnrollments} rows currently stand',
          rows: [
            for (final status in _statuses)
              _row(
                label: status.$2,
                value: report.countFor(status.$1),
                tone: status.$3,
                icon: status.$4,
              ),
          ],
        ),
        const SizedBox(height: 16),
        _Section(
          title: 'Courses',
          subtitle:
              '${report.programs.length} in the catalogue · '
              'readable by everyone with training view · '
              'counts limited to what you may see',
          rows: [for (final row in report.programs) _ProgramRow(row: row)],
        ),
        const SizedBox(height: 20),
        Text(
          'As at ${report.generatedAt}',
          key: const ValueKey('compliance-generated-at'),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }

  Widget _row({
    required String label,
    required int value,
    required StatusTone tone,
    required IconData icon,
  }) => Row(
    children: [
      Icon(icon, size: 18),
      const SizedBox(width: 10),
      Expanded(child: Text(label)),
      // Words as well as the tint: the chip below carries the meaning even
      // if the colour is not read.
      StatusChip(label: '$value', tone: tone),
    ],
  );

  static const _statuses = <(String, String, StatusTone, IconData)>[
    (
      EmployeeTrainingStatus.enrolled,
      'Enrolled',
      StatusTone.info,
      Icons.person_add_alt_outlined,
    ),
    (
      EmployeeTrainingStatus.scheduled,
      'Scheduled',
      StatusTone.info,
      Icons.event_outlined,
    ),
    (
      EmployeeTrainingStatus.inProgress,
      'In progress',
      StatusTone.info,
      Icons.play_circle_outline,
    ),
    (
      EmployeeTrainingStatus.completed,
      'Completed',
      StatusTone.positive,
      Icons.check_circle_outline,
    ),
    (
      EmployeeTrainingStatus.failed,
      'Failed',
      StatusTone.negative,
      Icons.cancel_outlined,
    ),
    (
      EmployeeTrainingStatus.cancelled,
      'Cancelled',
      StatusTone.neutral,
      Icons.block_outlined,
    ),
    (
      EmployeeTrainingStatus.expired,
      'Certificate expired',
      StatusTone.warning,
      Icons.event_busy_outlined,
    ),
  ];

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}

/// The seven states the vocabulary defines, named once.
///
/// A tiny holder rather than importing the whole `EmployeeTraining` model
/// just for its constants: this screen tallies rows it never draws, and
/// pulling the model in for four strings would put a constructor with
/// thirty fields behind every reference to the word `scheduled`.
abstract final class EmployeeTrainingStatus {
  static const enrolled = 'enrolled';
  static const scheduled = 'scheduled';
  static const inProgress = 'in_progress';
  static const completed = 'completed';
  static const failed = 'failed';
  static const cancelled = 'cancelled';
  static const expired = 'expired';
}

class _Section extends StatelessWidget {
  const _Section({
    required this.title,
    required this.subtitle,
    required this.rows,
  });

  final String title;
  final String subtitle;
  final List<Widget> rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(subtitle, style: theme.textTheme.bodySmall),
            const SizedBox(height: 14),
            for (final row in rows) ...[row, const SizedBox(height: 10)],
          ],
        ),
      ),
    );
  }
}

class _ProgramRow extends StatelessWidget {
  const _ProgramRow({required this.row});

  final ComplianceProgram row;

  @override
  Widget build(BuildContext context) {
    final program = row.program;
    final active = row.activeEnrollmentsCount;
    final total = row.enrollmentsCount;

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(program.name),
              Text(
                '${program.code} · $active active of $total',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
        StatusChip(label: '$active', tone: StatusTone.info),
      ],
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Material(
      color: scheme.errorContainer,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                message,
                style: TextStyle(color: scheme.onErrorContainer),
              ),
            ),
            TextButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}
