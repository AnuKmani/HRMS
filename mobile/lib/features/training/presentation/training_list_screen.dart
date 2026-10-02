import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/employee_training.dart';
import 'training_controller.dart';

/// Every enrolment this session may read.
///
/// Two things this list is careful about, and both are the questions the
/// documents list asks in the same shape — deliberately, because they *are*
/// the same questions:
///
///  - **the certificate state is printed next to the status, never a colour
///    alone.** "Expires in 5 days" and "Expired 12 days ago" carry their
///    meaning in the sentence, so the chip's tint is a second signal rather
///    than the only one.
///
///  - **the filter is a query parameter.** `status` and `certified` /
///    `expiring_soon` / `expired` travel to the API rather than being
///    applied over the loaded page, because the server owns both the row
///    scope and the totals: a Dart-side slice would still be showing the
///    unfiltered `total` underneath it.
class TrainingListScreen extends ConsumerWidget {
  const TrainingListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the enrol button, and before the list: `GET /employee-training`
    // is behind `permission:training.view`, so a session without it would
    // only ever draw a 403 or an empty list that looks like a decision.
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewTraining))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Training')),
        body: const NoPermission(module: 'training'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Training'),
        actions: [
          // The catalogue is readable by anyone who may read a course
          // history — `GET /training-programs` is behind
          // `permission:training.view` — so the door is drawn for exactly
          // the same session that is already reading the list behind it.
          if (scope.canViewTraining)
            IconButton(
              key: const ValueKey('training-programs-door'),
              tooltip: 'Courses',
              icon: const Icon(Icons.menu_book_outlined),
              onPressed: () => context.push('/training/programs'),
            ),
          if (scope.canViewTrainingExpiry)
            IconButton(
              key: const ValueKey('training-expiring-door'),
              tooltip: 'Expiring certificates',
              icon: const Icon(Icons.event_busy_outlined),
              onPressed: () => context.push('/training/expiring'),
            ),
          if (scope.canViewTraining)
            IconButton(
              key: const ValueKey('training-compliance-door'),
              tooltip: 'Compliance',
              icon: const Icon(Icons.pie_chart_outline),
              onPressed: () => context.push('/training/compliance'),
            ),
        ],
      ),
      floatingActionButton: scope.canAssignTraining
          ? FloatingActionButton(
              key: const ValueKey('enrol-training'),
              tooltip: 'Enrol somebody',
              onPressed: () => context.push('/training/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<EmployeeTraining>(
        provider: trainingListProvider,
        emptyMessage: 'No training records yet.',
        emptyHint:
            'Courses people have been put on — and the certificates that '
            'came out of them — appear here.',
        filter: _TrainingFilters(
          status: ref.watch(
            trainingListProvider.select((s) => s.query['status'] as String?),
          ),
          certificate: ref.watch(
            trainingListProvider.select(
              (s) => s.query['certificate'] as String?,
            ),
          ),
          onStatus: (value) => ref
              .read(trainingListProvider.notifier)
              .setFilter('status', value),
          // One key here, three on the wire — TrainingListController does
          // the translation, so the dropdown has a value to show when it
          // comes back and the request still carries the boolean the API
          // actually reads.
          onCertificate: (value) => ref
              .read(trainingListProvider.notifier)
              .setFilter('certificate', value),
        ),
        itemBuilder: (context, training, index) => ListTile(
          key: ValueKey('training-row-${training.id}'),
          leading: Icon(_iconFor(training)),
          title: Text(
            training.programName.isNotEmpty
                ? training.programName
                : 'Training ${training.id}',
          ),
          subtitle: Text(
            [
              training.employeeName ?? 'You',
              if (training.programCode.isNotEmpty) training.programCode,
              if ((training.enrollmentDate ?? '').isNotEmpty)
                'Enrolled ${training.enrollmentDate!}',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: training.statusText, tone: training.statusTone),
              const SizedBox(height: 6),
              // The certificate state only appears when there is a
              // certificate to have one: a course somebody has not sat yet
              // would otherwise draw "No expiry date", which reads as a
              // missing field rather than as "not yet".
              if (training.hasCertificate)
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(training.expiryIcon, size: 14),
                    const SizedBox(width: 4),
                    Text(
                      training.expiryText,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
            ],
          ),
          onTap: () => context.push('/training/${training.id}'),
        ),
      ),
    );
  }

  static IconData _iconFor(EmployeeTraining training) {
    if (training.isCompleted) return Icons.workspace_premium_outlined;

    return switch (training.status) {
      EmployeeTraining.statusCancelled => Icons.block_outlined,
      EmployeeTraining.statusFailed => Icons.warning_amber_outlined,
      EmployeeTraining.statusExpired => Icons.event_busy_outlined,
      _ => Icons.school_outlined,
    };
  }
}

class _TrainingFilters extends StatelessWidget {
  const _TrainingFilters({
    required this.status,
    required this.certificate,
    required this.onStatus,
    required this.onCertificate,
  });

  final String? status;
  final String? certificate;
  final ValueChanged<String?> onStatus;
  final ValueChanged<String?> onCertificate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Status', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        _dropdown(
          key: const ValueKey('training-status-filter'),
          value: status ?? '',
          items: const [
            ('', 'All statuses'),
            (EmployeeTraining.statusEnrolled, 'Enrolled'),
            (EmployeeTraining.statusScheduled, 'Scheduled'),
            (EmployeeTraining.statusInProgress, 'In progress'),
            (EmployeeTraining.statusCompleted, 'Completed'),
            (EmployeeTraining.statusFailed, 'Failed'),
            (EmployeeTraining.statusCancelled, 'Cancelled'),
            (EmployeeTraining.statusExpired, 'Certificate expired'),
          ],
          onChanged: onStatus,
        ),
        const SizedBox(height: 12),
        Text('Certificate', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        _dropdown(
          key: const ValueKey('training-certificate-filter'),
          value: certificate ?? '',
          items: const [
            ('', 'Any certificate'),
            ('certified', 'Has a certificate'),
            ('expiring_soon', 'Expiring soon'),
            ('expired', 'Already expired'),
          ],
          onChanged: onCertificate,
        ),
      ],
    );
  }

  Widget _dropdown({
    required Key key,
    required String value,
    required List<(String, String)> items,
    required ValueChanged<String?> onChanged,
  }) => InputDecorator(
    decoration: const InputDecoration(
      border: OutlineInputBorder(),
      isDense: true,
      contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
    ),
    child: DropdownButtonHideUnderline(
      child: DropdownButton<String>(
        key: key,
        isExpanded: true,
        value: value,
        items: [
          for (final (code, label) in items)
            DropdownMenuItem<String>(value: code, child: Text(label)),
        ],
        onChanged: onChanged,
      ),
    ),
  );
}
