import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/asset.dart';
import '../domain/asset_type.dart';
import 'asset_controller.dart';

/// Every asset this session may read.
///
/// Two things this list is careful about:
///
///  - **status and condition are two chips, never one.** "Assigned" says
///    where the asset sits in its lifecycle; "Fair" says what condition it
///    is physically in. An asset is very often both, and a register that
///    folded them would be unable to say "it is out *and* it came back
///    broken".
///
///  - **the filters are query parameters.** Type, status and condition
///    travel to the API rather than being applied over the loaded page,
///    because the server owns both the row scope and the totals: a register
///    narrowed in the browser would still be reporting the unfiltered
///    `total` underneath it.
///
/// And one thing it deliberately does *not* do: show what an asset cost.
/// `purchase_cost` is withheld from this payload for a reader without
/// `assets.manage`, and the detail screen records that it was withheld
/// rather than reading the absence as "this one was free".
class AssetListScreen extends ConsumerWidget {
  const AssetListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the register button, and before the list: `GET /assets` is
    // behind `permission:assets.view`, so a session without it would only
    // ever draw a 403 or an empty list that looks like a decision.
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewAssets))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Assets')),
        body: const NoPermission(module: 'assets'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Assets'),
        actions: [
          if (scope.canViewAssetHistory)
            IconButton(
              key: const ValueKey('asset-history-door'),
              tooltip: 'Hand-over log',
              icon: const Icon(Icons.history),
              onPressed: () => context.push('/assets/history'),
            ),
        ],
      ),
      floatingActionButton: scope.canCreateAssets
          ? FloatingActionButton(
              key: const ValueKey('new-asset'),
              tooltip: 'Register an asset',
              onPressed: () => context.push('/assets/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<Asset>(
        provider: assetListProvider,
        emptyMessage: 'No assets yet.',
        emptyHint:
            'Laptops, phones, tools and safety kit appear here once '
            'they are registered.',
        filter: _AssetFilters(
          typeId: ref.watch(
            assetListProvider.select(
              (s) => s.query['asset_type_id'] as String?,
            ),
          ),
          status: ref.watch(
            assetListProvider.select((s) => s.query['status'] as String?),
          ),
          condition: ref.watch(
            assetListProvider.select((s) => s.query['condition'] as String?),
          ),
          types: ref.watch(assetTypesPickerProvider).items,
          onType: (value) => ref
              .read(assetListProvider.notifier)
              .setFilter('asset_type_id', value),
          onStatus: (value) =>
              ref.read(assetListProvider.notifier).setFilter('status', value),
          onCondition: (value) => ref
              .read(assetListProvider.notifier)
              .setFilter('condition', value),
        ),
        itemBuilder: (context, asset, index) => ListTile(
          key: ValueKey('asset-row-${asset.id}'),
          leading: Icon(_iconFor(asset)),
          title: Text(asset.name.isNotEmpty ? asset.name : asset.assetCode),
          subtitle: Text(
            [
              asset.assetCode,
              if (asset.typeName.isNotEmpty) asset.typeName,
              if (asset.hasHolder) asset.holderName,
              asset.conditionText,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: asset.statusText, tone: asset.statusTone),
              const SizedBox(height: 6),
              // The condition, spelled out. Two chips rather than one
              // because these are two facts from two different columns and
              // neither may stand in for the other.
              Text(
                asset.conditionText,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          onTap: () => context.push('/assets/${asset.id}'),
        ),
      ),
    );
  }

  static IconData _iconFor(Asset asset) => switch (asset.status) {
    Asset.statusRetired => Icons.archive_outlined,
    Asset.statusLost => Icons.help_outline,
    Asset.statusMaintenance => Icons.build_outlined,
    Asset.statusDamaged => Icons.warning_amber_outlined,
    _ => _typeIcon(asset.typeName),
  };

  /// A switch on the *type's name*, which is data — not on its code, which
  /// would be a hard-coded vocabulary waiting to be wrong the moment an
  /// operator renames a row. Anything unknown gets the same generic icon,
  /// because "we do not recognise this kind" is not worth a wrong answer.
  static IconData _typeIcon(String typeName) {
    final lower = typeName.toLowerCase();

    if (lower.contains('laptop') || lower.contains('tablet')) {
      return Icons.laptop_mac;
    }
    if (lower.contains('phone')) return Icons.phone_iphone;
    if (lower.contains('safety') || lower.contains('equipment')) {
      return Icons.health_and_safety_outlined;
    }
    if (lower.contains('measur') || lower.contains('tool')) {
      return Icons.handyman_outlined;
    }

    return Icons.inventory_2_outlined;
  }
}

class _AssetFilters extends StatelessWidget {
  const _AssetFilters({
    required this.typeId,
    required this.status,
    required this.condition,
    required this.types,
    required this.onType,
    required this.onStatus,
    required this.onCondition,
  });

  final String? typeId;
  final String? status;
  final String? condition;
  final List<AssetType> types;
  final ValueChanged<String?> onType;
  final ValueChanged<String?> onStatus;
  final ValueChanged<String?> onCondition;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: _field(
                key: const ValueKey('asset-type-filter'),
                theme: theme,
                label: 'Type',
                value: typeId ?? '',
                items: [
                  ('', 'All types'),
                  for (final type in types) ('${type.id}', type.name),
                ],
                onChanged: onType,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _field(
                key: const ValueKey('asset-status-filter'),
                theme: theme,
                label: 'Status',
                value: status ?? '',
                items: const [
                  ('', 'Any status'),
                  (Asset.statusAvailable, 'Available'),
                  (Asset.statusAssigned, 'Assigned'),
                  (Asset.statusMaintenance, 'In maintenance'),
                  (Asset.statusDamaged, 'Damaged'),
                  (Asset.statusLost, 'Lost'),
                  (Asset.statusRetired, 'Retired'),
                ],
                onChanged: onStatus,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        _field(
          key: const ValueKey('asset-condition-filter'),
          theme: theme,
          label: 'Condition',
          value: condition ?? '',
          items: const [
            ('', 'Any condition'),
            (Asset.conditionNew, 'New'),
            (Asset.conditionGood, 'Good'),
            (Asset.conditionFair, 'Fair'),
            (Asset.conditionPoor, 'Poor'),
          ],
          onChanged: onCondition,
        ),
        if (types.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text('Loading types…', style: theme.textTheme.bodySmall),
          ),
      ],
    );
  }

  Widget _field({
    required Key key,
    required ThemeData theme,
    required String label,
    required String value,
    required List<(String, String)> items,
    required ValueChanged<String?> onChanged,
  }) {
    // A `DropdownButton` whose `value` is not among its items asserts rather
    // than drawing, and the type list arrives a moment after the filter can
    // be set. Falling back to "All types" for that one frame is the
    // difference between a screen that redraws and one that throws.
    final known = items.any((option) => option.$1 == value);

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
              key: key,
              isExpanded: true,
              value: known ? value : '',
              items: [
                for (final (code, text) in items)
                  DropdownMenuItem<String>(value: code, child: Text(text)),
              ],
              onChanged: onChanged,
            ),
          ),
        ),
      ],
    );
  }
}
