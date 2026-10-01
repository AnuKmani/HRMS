import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/onboarding.dart';
import 'onboarding_controller.dart';

/// Every person's standing in joining the company.
///
/// The list is a **directory**, not a table of onboarding records: a starter
/// nobody has opened yet still appears, as `Not started`. That is the whole
/// reason this screen reads `/onboarding` rather than a table — an empty
/// result here would mean "not begun", and drawing it as "all done" would be
/// the single most dangerous mistake this module could make.
///
/// Colour is never the only signal: every row pairs its chip with the
/// employee's name and, where the checklist was computed, a sentence naming
/// how much is outstanding.
class OnboardingListScreen extends ConsumerWidget {
  const OnboardingListScreen({super.key});

  static const _statusFilters = <(String, String)>[
    ('', 'Everyone'),
    (Onboarding.statusDraft, 'Not started'),
    (Onboarding.statusPendingDocuments, 'Waiting on documents'),
    (Onboarding.statusHrReview, 'HR review'),
    (Onboarding.statusCompleted, 'Completed'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(
      permissionScopeProvider.select((s) => s.canViewOnboarding),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Onboarding')),
        body: const NoPermission(module: 'onboarding'),
      );
    }

    final status = ref.watch(
      onboardingListProvider.select((s) => s.query['status'] as String?),
    );
    final incompleteOnly = ref.watch(
      onboardingListProvider.select((s) => s.query['incomplete'] != null),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Onboarding')),
      body: PagedListView<Onboarding>(
        provider: onboardingListProvider,
        emptyMessage: 'Nobody is onboarding.',
        emptyHint:
            'Every new joiner appears here from the moment they are added to '
            'the directory, including those nothing has been started for.',
        searchHint: 'Search name or code',
        filter: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Status', style: Theme.of(context).textTheme.labelLarge),
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
                  key: const ValueKey('onboarding-status-filter'),
                  isExpanded: true,
                  value: status ?? '',
                  items: [
                    for (final (code, label) in _statusFilters)
                      DropdownMenuItem<String>(value: code, child: Text(label)),
                  ],
                  onChanged: (value) => ref
                      .read(onboardingListProvider.notifier)
                      .setFilter('status', value),
                ),
              ),
            ),
            const SizedBox(height: 8),
            FilterChip(
              key: const ValueKey('onboarding-incomplete-filter'),
              label: const Text('Incomplete only'),
              selected: incompleteOnly,
              onSelected: (selected) => ref
                  .read(onboardingListProvider.notifier)
                  .setFilter('incomplete', selected ? '1' : null),
            ),
          ],
        ),
        itemBuilder: (context, record, index) => ListTile(
          key: ValueKey('onboarding-row-${record.employeeId}'),
          leading: const Icon(Icons.person_add_alt_outlined),
          title: Text(record.employeeName ?? 'Unnamed'),
          subtitle: Text(
            [
              if ((record.employeeCode ?? '').isNotEmpty) record.employeeCode!,
              record.summaryLabel,
              // Only when the payload actually carried a checklist — a blank
              // here is "not asked", never "zero of zero".
              if (record.progressLabel.isNotEmpty) record.progressLabel,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: StatusChip(
            label: record.statusLabel,
            tone: _toneFor(record.status),
          ),
          onTap: () => context.push('/onboarding/${record.employeeId}'),
        ),
      ),
    );
  }

  static StatusTone _toneFor(String status) => switch (status) {
    Onboarding.statusCompleted => StatusTone.positive,
    Onboarding.statusHrReview => StatusTone.info,
    Onboarding.statusPendingDocuments => StatusTone.warning,
    Onboarding.statusDraft => StatusTone.neutral,
    _ => StatusTone.neutral,
  };
}
