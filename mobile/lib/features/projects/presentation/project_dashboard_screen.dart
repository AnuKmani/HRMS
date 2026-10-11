import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../data/project_delivery_api.dart';
import '../domain/project.dart';
import '../domain/project_delivery.dart';
import 'project_delivery_sections.dart';

double _d(Object? v) => v is num ? v.toDouble() : 0;
double? _dn(Object? v) => v is num ? v.toDouble() : null;

List<Map<String, dynamic>> _rows(Object? v) => [
  if (v is List)
    for (final r in v)
      if (r is Map<String, dynamic>) r,
];

/// Project Manager and above: for every project — progress, value and
/// own-company billing, system by system and in total — and what each
/// installation subcontractor is contracted for and has billed.
class ProjectDashboardScreen extends ConsumerStatefulWidget {
  const ProjectDashboardScreen({super.key});

  @override
  ConsumerState<ProjectDashboardScreen> createState() =>
      _ProjectDashboardScreenState();
}

class _ProjectDashboardScreenState
    extends ConsumerState<ProjectDashboardScreen> {
  String _search = '';
  late Future<Map<String, dynamic>> _future = _fetch();

  Future<Map<String, dynamic>> _fetch() => ref
      .read(projectDeliveryApiProvider)
      .dashboard(search: _search.isEmpty ? null : _search);

  Future<void> _reload() async {
    setState(() => _future = _fetch());
    await _future.catchError((_) => <String, dynamic>{});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Project dashboard')),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snap.hasError) {
              final e = snap.error;
              return ListView(
                children: [
                  Padding(
                    padding: const EdgeInsets.all(32),
                    child: Text(
                      e is ApiException
                          ? e.message
                          : 'Could not load the dashboard.',
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              );
            }
            final data = snap.data ?? const <String, dynamic>{};
            final totals = data['totals'] is Map<String, dynamic>
                ? data['totals'] as Map<String, dynamic>
                : const <String, dynamic>{};
            final items = _rows(data['items']);

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _TotalsCard(totals: totals),
                const SizedBox(height: 12),
                TextField(
                  key: const ValueKey('dashboard-search'),
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    hintText: 'Search project, code or client',
                  ),
                  onSubmitted: (v) {
                    _search = v.trim();
                    _reload();
                  },
                ),
                const SizedBox(height: 12),
                if (items.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('No projects.', textAlign: TextAlign.center),
                  ),
                for (final p in items) _ProjectCard(project: p),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure(this.label, this.value, {this.sub});

  final String label;
  final String value;
  final String? sub;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        Text(
          value,
          style: theme.textTheme.titleMedium?.copyWith(
            fontWeight: FontWeight.bold,
          ),
        ),
        if (sub != null) Text(sub!, style: theme.textTheme.bodySmall),
      ],
    );
  }
}

class _TotalsCard extends StatelessWidget {
  const _TotalsCard({required this.totals});

  final Map<String, dynamic> totals;

  @override
  Widget build(BuildContext context) {
    final behind = (totals['behind_schedule'] as num?)?.toInt() ?? 0;
    return Card(
      key: const ValueKey('dashboard-totals'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'All projects (${(totals['projects'] as num?)?.toInt() ?? 0})',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 24,
              runSpacing: 12,
              children: [
                _Figure('Progress', pct(_dn(totals['progress_percent']))),
                _Figure('Project value', money(_dn(totals['value_total']))),
                _Figure(
                  'Billed by us',
                  money(_dn(totals['billed_total'])),
                  sub: pct(_dn(totals['billed_percent'])),
                ),
                _Figure(
                  'Subcontracts',
                  money(_dn(totals['subcontract_value_total'])),
                  sub:
                      'billed ${money(_dn(totals['subcontract_billed_total']))} '
                      '(${pct(_dn(totals['subcontract_billed_percent']))})',
                ),
              ],
            ),
            if (behind > 0) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const BehindChip(),
                  const SizedBox(width: 8),
                  Text('$behind project(s)'),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ProjectCard extends StatelessWidget {
  const _ProjectCard({required this.project});

  final Map<String, dynamic> project;

  @override
  Widget build(BuildContext context) {
    final p = project;
    final theme = Theme.of(context);
    final progress = _d(p['progress_percent']);
    final systems = _rows(p['systems']);
    final subs = _rows(p['subcontractors']);
    final id = (p['id'] as num?)?.toInt() ?? 0;

    return Card(
      key: ValueKey('dashboard-project-$id'),
      margin: const EdgeInsets.only(bottom: 12),
      child: ExpansionTile(
        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        expandedCrossAxisAlignment: CrossAxisAlignment.stretch,
        title: Text(
          '${p['name']}',
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              [
                '${p['code']}',
                projectStatusLabel('${p['status']}'),
                if (p['project_manager'] != null) 'PM: ${p['project_manager']}',
              ].join(' · '),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: LinearProgressIndicator(
                    value: (progress / 100).clamp(0, 1),
                    minHeight: 6,
                  ),
                ),
                const SizedBox(width: 8),
                Text(pct(progress)),
                if (p['behind_schedule'] == true) ...[
                  const SizedBox(width: 6),
                  const BehindChip(),
                ],
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'Value ${money(_dn(p['value_total']))} · billed '
              '${money(_dn(p['billed_total']))} (${pct(_dn(p['billed_percent']))})',
            ),
          ],
        ),
        children: [
          Text('By system', style: theme.textTheme.titleSmall),
          const SizedBox(height: 4),
          if (systems.isEmpty) const Text('No systems set.'),
          for (final s in systems)
            _Line(
              title: '${s['name']}',
              progress: _d(s['progress_percent']),
              detail:
                  'Value ${money(_dn(s['value']))} · billed '
                  '${money(_dn(s['billed_amount']))} '
                  '(${pct(_dn(s['billed_percent']))})',
            ),
          _TotalLine(
            'Total',
            'Progress ${pct(progress)} · value ${money(_dn(p['value_total']))}'
                ' · billed ${money(_dn(p['billed_total']))} '
                '(${pct(_dn(p['billed_percent']))})',
          ),
          const SizedBox(height: 12),
          Text(
            'Installation subcontractors',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          if (subs.isEmpty) const Text('None.'),
          for (final c in subs)
            _Line(
              title: [
                '${c['name']}',
                if (c['system'] != null) '${c['system']}',
              ].join(' · '),
              progress: _dn(c['billed_percent']),
              detail:
                  'Contract ${money(_dn(c['contract_value']))} · billed '
                  '${money(_dn(c['billed_amount']))} '
                  '(${pct(_dn(c['billed_percent']))})',
            ),
          if (subs.isNotEmpty)
            _TotalLine(
              'Total',
              'Contract ${money(_dn(p['subcontract_value_total']))} · billed '
                  '${money(_dn(p['subcontract_billed_total']))} '
                  '(${pct(_dn(p['subcontract_billed_percent']))})',
            ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              icon: const Icon(Icons.open_in_new, size: 18),
              label: const Text('Open project'),
              onPressed: () => context.push('/projects/$id'),
            ),
          ),
        ],
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.title, required this.detail, this.progress});

  final String title;
  final String detail;

  /// Bar under the title: progress for a system, billed % for a
  /// subcontractor.
  final double? progress;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            if (progress != null) Text(pct(progress)),
          ],
        ),
        if (progress != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: LinearProgressIndicator(
              value: (progress! / 100).clamp(0, 1),
              minHeight: 5,
            ),
          ),
        Text(detail, style: Theme.of(context).textTheme.bodySmall),
      ],
    ),
  );
}

class _TotalLine extends StatelessWidget {
  const _TotalLine(this.label, this.text);

  final String label;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 4),
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      '$label: $text',
      style: const TextStyle(fontWeight: FontWeight.w600),
    ),
  );
}
