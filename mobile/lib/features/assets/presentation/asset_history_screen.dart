import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/asset_assignment.dart';
import 'asset_controller.dart';

/// Every hand-over the company ever wrote — a different question from the
/// register, behind a different permission.
///
/// `assets.history.view` sits *on top of* `assets.view`: reading the register
/// asks "what do we own?", and this asks "who has held what?" — so the seeders
/// give it only to the roles that legitimately need the cross-employee log.
/// A screen that put both behind one tile would make the second act free to
/// anyone who could do the first.
///
/// Three things this list is careful about:
///
///  - **a closed hand-over is shown as one.** "15 Jan 2026 → 20 Jan 2026" is
///    a span, not a status: an open loan draws `→` with nothing after it,
///    because the count has no end yet and a fabricated one would be a
///    claim about a date nobody agreed to.
///
///  - **both conditions are printed.** What it went out in and what it came
///    back in are two columns of the same row, and the distance between them
///    is the whole argument for tracking condition at all.
///
///  - **the default filter is the open hand-overs.** "What is out right now"
///    is the question that gets asked first; the closed ones are one
///    dropdown away rather than something to scroll past.
class AssetHistoryScreen extends ConsumerWidget {
  const AssetHistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the list: `GET /asset-assignments` is behind
    // `permission:assets.history.view` *and* `assets.view`, so a session
    // with only the second would draw a 403 — and this screen says which
    // one it was rather than looking like an empty log.
    if (!ref.watch(
      permissionScopeProvider.select((s) => s.canViewAssetHistory),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Hand-over log')),
        body: const NoPermission(module: 'the hand-over log'),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Hand-over log')),
      body: PagedListView<AssetAssignment>(
        provider: assetHistoryProvider,
        emptyMessage: 'No hand-overs yet.',
        emptyHint:
            'Every time something left the shelf and every time it came '
            'back is listed here.',
        filter: _HistoryFilters(
          status: ref.watch(
            assetHistoryProvider.select((s) => s.query['status'] as String?),
          ),
          onStatus: (value) => ref
              .read(assetHistoryProvider.notifier)
              .setFilter('status', value),
        ),
        itemBuilder: (context, row, index) => ListTile(
          key: ValueKey('history-row-${row.id}'),
          leading: Icon(
            row.isOpen ? Icons.outbox_outlined : Icons.inbox_outlined,
          ),
          title: Text(row.employeeName ?? 'Unnamed holder'),
          subtitle: Text(
            [
              row.assetName,
              row.spanLabel,
              if (row.returnedConditionText != null)
                'came back ${row.returnedConditionText!.toLowerCase()}',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: row.statusText, tone: row.statusTone),
              const SizedBox(height: 6),
              // Words, not a colour: "Overdue" and "2 days out" carry their
              // meaning in the sentence, and a tint would be a second
              // signal rather than the only one.
              Text(
                [
                  if (row.isOverdue) 'Overdue',
                  if (row.daysOutLabel != null) row.daysOutLabel!,
                ].join(' · ').ifEmpty('—'),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          onTap: row.assetId == 0
              ? null
              : () => context.push('/assets/${row.assetId}'),
        ),
      ),
    );
  }
}

extension on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}

class _HistoryFilters extends StatelessWidget {
  const _HistoryFilters({required this.status, required this.onStatus});

  final String? status;
  final ValueChanged<String?> onStatus;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Hand-over', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              key: const ValueKey('history-status-filter'),
              isExpanded: true,
              value: status ?? '',
              items:
                  const [
                        ('', 'Everything'),
                        (AssetAssignment.statusActive, 'Still out'),
                        (AssetAssignment.statusReturned, 'Already returned'),
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
