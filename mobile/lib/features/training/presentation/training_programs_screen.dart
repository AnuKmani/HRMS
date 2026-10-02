import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/training_program.dart';
import 'training_controller.dart';

/// The catalogue of courses this company runs.
///
/// **Nothing in here is a hard-coded training type.** SAFETY_INDUCTION, HSE,
/// WORKING_AT_HEIGHTS and the rest are rows in a table an operator may
/// rename, retire or add to, and this screen draws whatever the API returns —
/// which is the whole reason scope item A asks for configurable types. A
/// `switch` on `code` anywhere below would put the vocabulary back into the
/// client, where adding a kind of course would need an app release.
///
/// The list is the whole catalogue rather than only the active half,
/// because a retired course still sits behind every cohort that was filed
/// under it: an operator has to be able to tell "we stopped running these"
/// from "this never existed".
class TrainingProgramsScreen extends ConsumerWidget {
  const TrainingProgramsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewTraining))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Courses')),
        body: const NoPermission(module: 'training catalogue'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Courses')),
      floatingActionButton: scope.canCreatePrograms
          ? FloatingActionButton(
              key: const ValueKey('new-program'),
              tooltip: 'Add a course',
              onPressed: () => context.push('/training/programs/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<TrainingProgram>(
        provider: trainingProgramsProvider,
        emptyMessage: 'No courses yet.',
        emptyHint:
            'Add the courses this company runs and how long each card '
            'lasts.',
        filter: _ProgramFilters(
          status: ref.watch(
            trainingProgramsProvider.select(
              (s) => s.query['status'] as String?,
            ),
          ),
          onStatus: (value) => ref
              .read(trainingProgramsProvider.notifier)
              .setFilter('status', value),
        ),
        itemBuilder: (context, program, index) => ListTile(
          key: ValueKey('program-row-${program.id}'),
          leading: Icon(
            program.certificateRequired
                ? Icons.workspace_premium_outlined
                : Icons.school_outlined,
          ),
          title: Text(program.name),
          subtitle: Text(
            [
              program.code,
              if (program.typeName.isNotEmpty) program.typeName,
              if ((program.provider ?? '').isNotEmpty) program.provider!,
              program.durationLabel,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: program.statusText, tone: StatusTone.neutral),
              const SizedBox(height: 6),
              // The certificate promise, in words. `null` validity means
              // "never lapses" and a blank here would read as "nobody filled
              // it in", which is a different claim.
              Text(
                program.certificateLabel,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          onTap: () => context.push('/training/programs/${program.id}'),
        ),
      ),
    );
  }
}

class _ProgramFilters extends StatelessWidget {
  const _ProgramFilters({required this.status, required this.onStatus});

  final String? status;
  final ValueChanged<String?> onStatus;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Status', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              key: const ValueKey('program-status-filter'),
              isExpanded: true,
              value: status ?? '',
              items:
                  const [
                        ('', 'All courses'),
                        (TrainingProgram.statusActive, 'Active'),
                        (TrainingProgram.statusRetired, 'Retired'),
                      ]
                      .map(
                        (option) => DropdownMenuItem<String>(
                          value: option.$1,
                          child: Text(option.$2),
                        ),
                      )
                      .toList(),
              onChanged: onStatus,
            ),
          ),
        ),
      ],
    );
  }
}
