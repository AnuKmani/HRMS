import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/employee_training.dart';
import 'training_controller.dart';

/// Every certificate that is about to lapse, or already has.
///
/// A separate screen rather than a filter on the directory, because it is a
/// separate question with a separate permission: `training.expiry.view` asks
/// about *everybody*, and the seeders withhold it from roles that should only
/// ever see their own course history. A filter chip on the main list would
/// put the same act behind `training.view`.
///
/// The window is the report's own `within` (90 days, overridable from the
/// chips below) rather than a page-side comparison — the server answers it
/// with a single date arithmetic, and a client that re-derived the same
/// answer would drift from the one the scheduler lapses on. That matters
/// more here than it does for a document: a card that lapsed an hour ago is
/// expired whether or not the nightly scan has run.
class TrainingExpiryScreen extends ConsumerWidget {
  const TrainingExpiryScreen({super.key});

  static const _windows = <(String, String)>[
    ('0', 'Already expired'),
    ('30', 'Next 30 days'),
    ('90', 'Next 90 days'),
    ('180', 'Next 180 days'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(
      permissionScopeProvider.select((s) => s.canViewTrainingExpiry),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Expiring certificates')),
        body: const NoPermission(module: 'training expiry'),
      );
    }

    final within = ref.watch(
      trainingExpiryProvider.select((s) => '${s.query['within'] ?? 90}'),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Expiring certificates')),
      body: PagedListView<EmployeeTraining>(
        provider: trainingExpiryProvider,
        emptyMessage: 'Nothing is due to expire.',
        emptyHint:
            'Certificates inside the selected window appear here, earliest '
            'first.',
        filter: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Window', style: Theme.of(context).textTheme.labelLarge),
            const SizedBox(height: 6),
            InputDecorator(
              decoration: const InputDecoration(
                border: OutlineInputBorder(),
                isDense: true,
                contentPadding: EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 12,
                ),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  key: const ValueKey('expiry-window-filter'),
                  isExpanded: true,
                  value: within,
                  items: [
                    for (final (code, label) in _windows)
                      DropdownMenuItem<String>(value: code, child: Text(label)),
                  ],
                  onChanged: (value) => ref
                      .read(trainingExpiryProvider.notifier)
                      .setFilter('within', value),
                ),
              ),
            ),
          ],
        ),
        itemBuilder: (context, training, index) => ListTile(
          key: ValueKey('expiring-row-${training.id}'),
          leading: Icon(training.expiryIcon),
          title: Text(
            training.programName.isNotEmpty
                ? training.programName
                : 'Training ${training.id}',
          ),
          subtitle: Text(
            [
              training.employeeName ?? 'You',
              if ((training.certificateExpiryDate ?? '').isNotEmpty)
                'Expires ${training.certificateExpiryDate!}',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: training.statusText, tone: training.statusTone),
              const SizedBox(height: 6),
              Text(
                training.expiryText,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          onTap: () => context.push('/training/${training.id}'),
        ),
      ),
    );
  }
}
