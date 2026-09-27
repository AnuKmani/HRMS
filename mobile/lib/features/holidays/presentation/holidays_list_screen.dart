import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/holiday.dart';
import 'holidays_controller.dart';

/// The holiday calendar, for everyone.
///
/// Every signed-in account can read it — `GET /holidays` carries no
/// `permission:` middleware and `HolidayPolicy::viewAny` answers yes
/// unconditionally, because a day the company declared off is not a
/// privilege within it. Only the *write* affordances are gated, and only on
/// the buttons: forcing `/holidays/new` by hand gets the API's 403.
///
/// There is no delete anywhere in this screen. A holiday is retired with
/// `status = inactive`, which keeps the fact that it once counted — the
/// alternative is a calendar with holes where decisions used to be.
class HolidaysListScreen extends ConsumerWidget {
  const HolidaysListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageHolidays),
    );
    final state = ref.watch(holidaysListProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Holidays')),
      floatingActionButton: canManage
          ? FloatingActionButton(
              key: const ValueKey('add-holiday'),
              tooltip: 'Add holiday',
              onPressed: () => context.push('/holidays/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Holiday>(
        provider: holidaysListProvider,
        searchHint: 'Search holidays',
        emptyMessage: 'No holidays on the calendar yet.',
        emptyHint: 'Days the organisation declares off will appear here.',
        filter: Row(
          children: [
            Expanded(
              child: _Filter(
                key: const ValueKey('holiday-type-filter'),
                label: 'Type',
                value: state.query['type'] as String? ?? '',
                options: const [
                  ('', 'All types'),
                  (Holiday.typePublic, 'Public'),
                  (Holiday.typeCompany, 'Company'),
                  (Holiday.typeSite, 'Site'),
                ],
                onSelected: (value) => ref
                    .read(holidaysListProvider.notifier)
                    .setFilter('type', value),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _Filter(
                key: const ValueKey('holiday-status-filter'),
                label: 'Status',
                value: state.query['status'] as String? ?? '',
                options: const [
                  ('', 'All'),
                  (Holiday.statusActive, 'Active'),
                  (Holiday.statusInactive, 'Retired'),
                ],
                onSelected: (value) => ref
                    .read(holidaysListProvider.notifier)
                    .setFilter('status', value),
              ),
            ),
          ],
        ),
        itemBuilder: (context, holiday, index) => ListTile(
          key: ValueKey('holiday-row-${holiday.id}'),
          title: Text(holiday.name),
          subtitle: Text('${holiday.date} · ${holiday.scopeLabel}'),
          trailing: StatusChip(
            label: holiday.isActive ? 'Active' : 'Retired',
            tone: holiday.isActive ? StatusTone.positive : StatusTone.neutral,
          ),
          // A reader with no write permission gets no arrow either: there is
          // nothing on the other side of the tap, and a chevron that leads
          // nowhere is worse than no affordance at all.
          onTap: canManage
              ? () => context.push('/holidays/${holiday.id}/edit')
              : null,
        ),
      ),
    );
  }
}

/// One of the two calendar filters.
///
/// `''` means "no filter" and the controller drops the key rather than
/// sending an empty value, because `type=` and no `type` at all are
/// different questions to the API.
class _Filter extends StatelessWidget {
  const _Filter({
    super.key,
    required this.label,
    required this.value,
    required this.options,
    required this.onSelected,
  });

  final String label;

  final String value;

  final List<(String, String)> options;

  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              isExpanded: true,
              value: value,
              items: [
                for (final (option, caption) in options)
                  DropdownMenuItem<String>(value: option, child: Text(caption)),
              ],
              onChanged: onSelected,
            ),
          ),
        ),
      ],
    );
  }
}
