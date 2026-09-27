import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../data/api_sites_repository.dart';
import '../domain/site.dart';

/// One site, in full — coordinates and geofence included.
class SiteDetailScreen extends ConsumerStatefulWidget {
  const SiteDetailScreen({super.key, required this.siteId});

  final int siteId;

  @override
  ConsumerState<SiteDetailScreen> createState() => _SiteDetailScreenState();
}

class _SiteDetailScreenState extends ConsumerState<SiteDetailScreen> {
  Site? _site;
  String? _message;
  bool _forbidden = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _message = null;
      _forbidden = false;
    });

    try {
      final site = await ref.read(sitesRepositoryProvider).find(widget.siteId);

      if (!mounted) return;

      setState(() {
        _site = site;
        _loading = false;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _message = failure.message;
        _forbidden = failure.statusCode == 403;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _message =
            'Something went wrong while loading this site. Please try again.';
      });
    }
  }

  Future<void> _delete(Site site) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove site?'),
        content: Text(
          '${site.name} will be removed from the site list. The API refuses '
          'this while any employee still has assignment history here — those '
          'rows are the record of who worked where, and there is no way to '
          'delete them.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('confirm-delete'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Remove'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    try {
      await ref.read(sitesRepositoryProvider).remove(site.id);

      if (!mounted) return;
      context.go('/sites');
    } on ApiException catch (failure) {
      if (!mounted) return;

      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    if (_loading) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      );
    }

    final site = _site;

    if (site == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Site')),
        body: Center(
          key: const ValueKey('detail-error'),
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  _forbidden ? Icons.lock_outline : Icons.error_outline,
                  size: 48,
                  color: Theme.of(context).colorScheme.error,
                ),
                const SizedBox(height: 12),
                Text(
                  _message ?? 'This site could not be loaded.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  key: const ValueKey('detail-retry'),
                  onPressed: _load,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Try again'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(site.name)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              site.name,
              key: const ValueKey('detail-name'),
              style: theme.textTheme.headlineSmall,
            ),
            const SizedBox(height: 4),
            Text(site.summary, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 24),
            _section('Location', [
              _row('Code', site.code),
              _row('Project', site.projectName),
              _row('Address', site.address),
              _row(
                'Status',
                (site.status ?? '').replaceAll('_', ' '),
                key: const ValueKey('detail-status'),
              ),
            ]),
            _section('Geofence', [
              _row(
                'Latitude',
                site.latitude?.toStringAsFixed(7),
                key: const ValueKey('detail-latitude'),
              ),
              _row(
                'Longitude',
                site.longitude?.toStringAsFixed(7),
                key: const ValueKey('detail-longitude'),
              ),
              _row(
                'Radius (m)',
                site.geofenceRadius?.toStringAsFixed(2),
                key: const ValueKey('detail-radius'),
              ),
            ]),
            if (!site.hasGeofence)
              Padding(
                padding: const EdgeInsets.only(bottom: 16),
                child: Text(
                  'No geofence is configured for this site, so check-ins from '
                  'a phone are not bounded to a location.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            _section('People', [
              _row('Site manager', site.siteManagerName),
              _row('Site supervisor', site.siteSupervisorName),
              _row('Shift', site.shiftName),
            ]),
            const SizedBox(height: 8),
            if (scope.canManageSites)
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const ValueKey('detail-edit'),
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit'),
                      onPressed: () => context.push('/sites/${site.id}/edit'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const ValueKey('detail-delete'),
                      icon: const Icon(Icons.delete_outline),
                      label: const Text('Remove'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: theme.colorScheme.error,
                      ),
                      onPressed: () => _delete(site),
                    ),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _section(String title, List<Widget> rows) => Padding(
        padding: const EdgeInsets.only(bottom: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            DecoratedBox(
              decoration: BoxDecoration(
                border: Border.all(color: Theme.of(context).dividerColor),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(children: rows),
            ),
          ],
        ),
      );

  Widget _row(String label, String? value, {Key? key}) {
    final resolved = (value == null || value.trim().isEmpty) ? '—' : value;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 140,
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
          Expanded(child: Text(resolved, key: key)),
        ],
      ),
    );
  }
}
