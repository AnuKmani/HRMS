import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/client_settings.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/money.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_asset_repository.dart';
import '../domain/asset.dart';
import '../domain/asset_repository.dart';
import 'asset_controller.dart';
import 'asset_sheets.dart';

/// One asset, its master record, who holds it, and the actions this session
/// may attempt.
///
/// Five things this screen is careful about:
///
///  - **status and condition are two chips, in words, never one colour.**
///    "Assigned" and "Fair" come from different columns and answer different
///    questions; an asset is very often both.
///
///  - **"we did not ask" is never rendered as "it is free".** When the
///    payload carries no history, the holder line says so rather than
///    drawing an empty slot — a register that guessed would be handing out
///    an answer to a question it never asked.
///
///  - **cost is shown only when it was sent.** `purchase_cost` is omitted
///    from the payload for a reader without `assets.manage`, and an absent
///    key is recorded as absent rather than as zero. The currency comes from
///    the server's own `client-settings` so the figure is printed in the
///    company's money rather than in whatever the source said when it was
///    written.
///
///  - **three acts, three permissions, three refusals.** Handing out is
///    `assets.assign`, taking back is `assets.return`, and moving the status
///    is `assets.manage` — and the server refuses each with a sentence about
///    the state the row is actually in, which stays on screen here.
///
///  - **`assigned` is not offered as a status.** The register's status
///    control routes a person to the assign action instead, because a status
///    that could say "somebody holds this" with no hand-over behind it is
///    exactly the write this module exists to prevent.
class AssetDetailScreen extends ConsumerStatefulWidget {
  const AssetDetailScreen({super.key, required this.assetId});

  final int assetId;

  @override
  ConsumerState<AssetDetailScreen> createState() => _AssetDetailScreenState();
}

class _AssetDetailScreenState extends ConsumerState<AssetDetailScreen> {
  Asset? _asset;
  bool _loading = false;
  bool _busy = false;
  bool _forbidden = false;

  String? _banner;
  String? _currency;

  AssetRepository get _repository => ref.read(assetRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gated before the fetch, not just before the build: a URL typed by
    // hand would otherwise ask the API for a record the session was never
    // going to be shown, and the answer to that question is a 403 with
    // nothing to do about it.
    if (ref.read(permissionScopeProvider).canViewAssets) _load();
  }

  @override
  void didUpdateWidget(covariant AssetDetailScreen oldWidget) {
    super.didUpdateWidget(oldWidget);

    // The State survives a route that lands back on this screen with a
    // different id — an edit pushed and popped, or a list that navigated
    // sideways — and without this the screen would keep describing the row
    // it is no longer showing.
    if (oldWidget.assetId != widget.assetId) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final asset = await _repository.find(widget.assetId);
      if (!mounted) return;

      setState(() {
        _asset = asset;
        _loading = false;
        _forbidden = false;
      });

      if (asset.costVisible && _currency == null) _loadCurrency();
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = _messageFor(failure);
      });
    }
  }

  /// The company's own currency, fetched once and only when there is a
  /// figure to print in it.
  ///
  /// Not a constant, and not `AED` written in this file: `system.currency`
  /// is a settings row, and a deployment to a second entity would otherwise
  /// be labelling somebody else's money with this one.
  Future<void> _loadCurrency() async {
    try {
      final settings = await ref.read(clientSettingsProvider).load();
      if (mounted) setState(() => _currency = settings.defaultCurrency);
    } catch (_) {
      // The cost simply goes unformatted rather than wrong: `Money.format`
      // with a null currency is the honest rendering of "we do not know what
      // unit this is in", and the figure is worth showing either way.
      if (mounted) setState(() => _currency = null);
    }
  }

  /// One sheet, one send, one reload — the same shape for all three acts.
  ///
  /// Named rather than inlined three times because the shape *is* the
  /// invariant: a sheet returns a payload or `null` (\"do not send\"), the
  /// request owns the busy flag, a refusal lands on the banner, and the row
  /// is re-read afterwards so the screen describes what the server decided
  /// rather than what the form hoped for.
  Future<void> _run(
    Future<Map<String, Object?>?> Function() sheet, {
    required Future<void> Function(Map<String, Object?> body) send,
  }) async {
    if (_busy) return;

    final body = await sheet();
    if (body == null || !mounted) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      await send(body);

      ref.read(assetListProvider.notifier).reload();

      await _load();
    } on ApiException catch (failure) {
      // Field errors included, deliberately: by the time this runs the
      // sheet has already popped, so a `422 {errors}` has no field to land
      // under — and a banner left blank by `errors.isNotEmpty` would mean
      // the server refused the write and this screen said nothing about it.
      if (mounted) {
        setState(() {
          _banner = failure.errors.isEmpty
              ? failure.message
              : failure.errors.values.join(' ');
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() => _banner = 'Something went wrong. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _assign() => _run(
    () => showAssetAssignSheet(context: context),
    send: (body) => _repository.assign(widget.assetId, body),
  );

  Future<void> _returnAsset() => _run(
    () => showAssetReturnSheet(context: context),
    send: (body) => _repository.returnAsset(widget.assetId, body),
  );

  Future<void> _changeStatus(List<String> targets, String current) => _run(
    () => showAssetStatusSheet(
      context: context,
      targets: targets,
      current: current,
    ),
    send: (body) => _repository.changeStatus(
      widget.assetId,
      status: body['status']! as String,
      notes: body['notes'] as String?,
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewAssets))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Asset')),
        body: const NoPermission(module: 'assets'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);
    final asset = _asset;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          asset?.assetCode.isNotEmpty == true ? asset!.assetCode : 'Asset',
        ),
        actions: [
          if (asset != null && scope.canUpdateAssets && !asset.isRetired)
            IconButton(
              key: const ValueKey('edit-asset'),
              tooltip: 'Edit',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () => context.push('/assets/${asset.id}/edit'),
            ),
        ],
      ),
      body: _loading && asset == null
          ? const Center(
              child: CircularProgressIndicator(
                key: ValueKey('asset-detail-loading'),
              ),
            )
          : asset == null
          ? _failed()
          : RefreshIndicator(onRefresh: _load, child: _body(asset, scope)),
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
            _forbidden
                ? 'You may not open this asset.'
                : (_banner ?? 'That asset could not be loaded.'),
            key: const ValueKey('asset-detail-error'),
            textAlign: TextAlign.center,
          ),
          if (!_forbidden) ...[
            const SizedBox(height: 12),
            FilledButton(
              key: const ValueKey('asset-detail-retry'),
              onPressed: _load,
              child: const Text('Try again'),
            ),
          ],
        ],
      ),
    ),
  );

  Widget _body(Asset asset, PermissionScope scope) {
    final theme = Theme.of(context);
    final targets = asset.statusTargets;

    return ListView(
      key: const ValueKey('asset-detail-body'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: [
        if (_banner != null)
          Padding(
            key: const ValueKey('asset-detail-banner'),
            padding: const EdgeInsets.only(bottom: 12),
            child: Material(
              color: theme.colorScheme.errorContainer,
              borderRadius: BorderRadius.circular(8),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(
                  _banner!,
                  style: TextStyle(color: theme.colorScheme.onErrorContainer),
                ),
              ),
            ),
          ),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  asset.name.isNotEmpty ? asset.name : asset.assetCode,
                  key: const ValueKey('asset-detail-title'),
                  style: theme.textTheme.titleLarge,
                ),
                const SizedBox(height: 6),
                Text(
                  [
                    asset.assetCode,
                    if (asset.typeName.isNotEmpty) asset.typeName,
                  ].join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 6,
                  children: [
                    StatusChip(label: asset.statusText, tone: asset.statusTone),
                    StatusChip(
                      label: asset.conditionText,
                      tone: asset.conditionTone,
                    ),
                    if (asset.isAssignable)
                      const StatusChip(
                        label: 'Ready to hand out',
                        tone: StatusTone.positive,
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        _section(
          theme,
          title: 'The asset',
          rows: [
            (
              'Type',
              asset.typeName.isNotEmpty ? asset.typeName : 'Not recorded',
            ),
            ('Serial number', asset.serialLabel),
            if (asset.modelLabel.isNotEmpty) ('Model', asset.modelLabel),
            if ((asset.purchaseDate ?? '').isNotEmpty)
              ('Purchased on', asset.purchaseDate!),
            // Two different absences, two different sentences: the server
            // *omits* the key for a reader without `assets.manage`, and that
            // is not the same as an asset whose cost was never written down.
            if (!asset.costVisible)
              ('Purchase cost', 'Not shown to your role')
            else
              ('Purchase cost', _costLabel(asset)),
            ('Notes', (asset.notes ?? '').isNotEmpty ? asset.notes! : '—'),
          ],
        ),
        const SizedBox(height: 16),
        _holderSection(asset),
        const SizedBox(height: 16),
        if (asset.assignments != null && asset.assignments!.isNotEmpty)
          _section(
            theme,
            title: 'Hand-over history',
            rows: [
              for (final row in asset.assignments!.take(6))
                (
                  row.assignedDate ?? '',
                  [
                    row.employeeName ?? 'Unnamed holder',
                    row.statusText,
                    if (row.returnedDate != null) 'back ${row.returnedDate}',
                  ].join(' · '),
                ),
            ],
            trailing: scope.canViewAssetHistory
                ? TextButton(
                    key: const ValueKey('asset-full-history'),
                    onPressed: () => context.push('/assets/history'),
                    child: const Text('See all'),
                  )
                : null,
          ),
        if (scope.canManageAssets ||
            scope.canAssignAssets ||
            scope.canReturnAssets) ...[
          const SizedBox(height: 20),
          if (asset.isAssignable && scope.canAssignAssets)
            FilledButton.icon(
              key: const ValueKey('assign-asset'),
              icon: const Icon(Icons.handshake_outlined),
              label: const Text('Hand out'),
              onPressed: _busy ? null : _assign,
            ),
          if (asset.isAssigned && scope.canReturnAssets) ...[
            if (asset.isAssignable && scope.canAssignAssets)
              const SizedBox(height: 10),
            FilledButton.icon(
              key: const ValueKey('return-asset'),
              icon: const Icon(Icons.assignment_return_outlined),
              label: const Text('Take back'),
              onPressed: _busy ? null : _returnAsset,
            ),
          ],
          if (scope.canManageAssets && targets.isNotEmpty) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              key: const ValueKey('change-status'),
              icon: const Icon(Icons.swap_horiz_outlined),
              label: const Text('Change status'),
              onPressed: _busy
                  ? null
                  : () => _changeStatus(targets, asset.status),
            ),
          ],
        ],
      ],
    );
  }

  /// What it costs, in the company's money — or the honest absence.
  String _costLabel(Asset asset) {
    final cost = asset.purchaseCost;

    if (cost == null || cost.isEmpty) return 'Not recorded';

    final currency = _currency;
    // `currency: ''` rather than the formatter's default: if the settings
    // call failed we do not know the unit, and printing an `INR` this
    // company never used would be worse than printing the digits with no
    // unit at all. Money.format's default is deliberately unreachable here.
    if (currency == null) return Money.format(cost, currency: '');

    return Money.format(cost, currency: currency);
  }

  Widget _holderSection(Asset asset) {
    final theme = Theme.of(context);
    final assignment = asset.currentAssignment;

    // A payload without the history says nothing, and "we did not ask" is
    // not "it is free" — so the two are drawn as two different sentences.
    final String holder;
    final String detail;

    if (asset.assignments == null) {
      holder = 'Holders not loaded';
      detail = 'This view did not fetch the hand-over history.';
    } else if (!asset.hasHolder) {
      holder = 'Nobody holds it';
      detail = asset.isAvailable ? 'It is on the shelf.' : asset.statusText;
    } else {
      holder = asset.holderName.isNotEmpty ? asset.holderName : 'A holder';
      detail = [
        if (assignment != null)
          'Since ${assignment.assignedDate ?? 'an unstated date'}'
        else
          'Handed over',
        if (assignment != null && assignment.daysOutLabel != null)
          assignment.daysOutLabel!,
        if (assignment != null && assignment.isOverdue) 'Overdue',
      ].join(' · ');
    }

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Currently with', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(holder, key: const ValueKey('asset-holder')),
            const SizedBox(height: 4),
            Text(detail, style: theme.textTheme.bodySmall),
            if (assignment != null && assignment.remarks != null) ...[
              const SizedBox(height: 8),
              Text(
                'Remarks: ${assignment.remarks}',
                style: theme.textTheme.bodySmall?.copyWith(
                  fontStyle: FontStyle.italic,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _section(
    ThemeData theme, {
    required String title,
    required List<(String, String)> rows,
    Widget? trailing,
  }) => Card(
    margin: EdgeInsets.zero,
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(title, style: theme.textTheme.titleMedium)),
              ?trailing,
            ],
          ),
          const SizedBox(height: 10),
          for (final (label, value) in rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 132,
                    child: Text(
                      label,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.hintColor,
                      ),
                    ),
                  ),
                  Expanded(child: Text(value)),
                ],
              ),
            ),
        ],
      ),
    ),
  );

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}
