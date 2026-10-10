import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../data/project_delivery_api.dart';
import '../domain/project.dart';
import '../domain/project_delivery.dart';
import 'project_delivery_sections.dart';

/// Every project's progress — what Sales sees. Nothing else of the project.
class ProjectProgressListScreen extends ConsumerStatefulWidget {
  const ProjectProgressListScreen({super.key});

  @override
  ConsumerState<ProjectProgressListScreen> createState() =>
      _ProjectProgressListScreenState();
}

class _ProjectProgressListScreenState
    extends ConsumerState<ProjectProgressListScreen> {
  late Future<List<ProjectProgress>> _future = _fetch();
  String _search = '';

  Future<List<ProjectProgress>> _fetch() => ref
      .read(projectDeliveryApiProvider)
      .progressList(search: _search.isEmpty ? null : _search);

  Future<void> _reload() async {
    setState(() => _future = _fetch());
    await _future.catchError((_) => <ProjectProgress>[]);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Project progress')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: TextField(
              key: const ValueKey('progress-search'),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'Search projects or codes',
              ),
              onSubmitted: (value) {
                _search = value.trim();
                _reload();
              },
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<List<ProjectProgress>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snap.hasError) {
                    final error = snap.error;
                    return ListView(
                      children: [
                        Padding(
                          padding: const EdgeInsets.all(32),
                          child: Text(
                            error is ApiException
                                ? error.message
                                : 'Could not load project progress.',
                            textAlign: TextAlign.center,
                          ),
                        ),
                      ],
                    );
                  }
                  final rows = snap.data ?? const [];
                  if (rows.isEmpty) {
                    return ListView(
                      children: const [
                        Padding(
                          padding: EdgeInsets.all(32),
                          child: Text(
                            'No projects yet.',
                            textAlign: TextAlign.center,
                          ),
                        ),
                      ],
                    );
                  }
                  return ListView.separated(
                    itemCount: rows.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (context, i) {
                      final p = rows[i];
                      return ListTile(
                        key: ValueKey('progress-row-${p.id}'),
                        isThreeLine: true,
                        title: Text(p.name),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${p.code} · ${projectStatusLabel(p.status)}'
                              '${p.milestonesDue > 0 ? ' · ${p.milestonesDue} payment due' : ''}',
                              style: p.milestonesDue > 0
                                  ? TextStyle(
                                      color: Colors.orange.shade800,
                                      fontWeight: FontWeight.w600,
                                    )
                                  : null,
                            ),
                            const SizedBox(height: 4),
                            Row(
                              children: [
                                Expanded(
                                  child: LinearProgressIndicator(
                                    value: (p.progressPercent / 100).clamp(
                                      0,
                                      1,
                                    ),
                                    minHeight: 6,
                                  ),
                                ),
                                const SizedBox(width: 8),
                                Text(pct(p.progressPercent)),
                                if (p.behindSchedule) ...[
                                  const SizedBox(width: 6),
                                  const BehindChip(),
                                ],
                              ],
                            ),
                          ],
                        ),
                        onTap: () async {
                          await context.push('/project-progress/${p.id}');
                          if (mounted) _reload();
                        },
                      );
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One project's progress: stages, history, payment milestones, PDF.
class ProjectProgressDetailScreen extends ConsumerStatefulWidget {
  const ProjectProgressDetailScreen({super.key, required this.projectId});

  final int projectId;

  @override
  ConsumerState<ProjectProgressDetailScreen> createState() =>
      _ProjectProgressDetailScreenState();
}

class _ProjectProgressDetailScreenState
    extends ConsumerState<ProjectProgressDetailScreen> {
  ProjectProgress? _project;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final p = await ref
          .read(projectDeliveryApiProvider)
          .progress(widget.projectId);
      if (mounted) setState(() => _project = p);
    } on ApiException catch (failure) {
      if (mounted) setState(() => _error = failure.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not load this project.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _project;
    if (p == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Project progress')),
        body: Center(
          child: _error == null
              ? const CircularProgressIndicator()
              : Padding(
                  padding: const EdgeInsets.all(32),
                  child: Text(_error!, textAlign: TextAlign.center),
                ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(p.name),
        actions: [
          IconButton(
            key: const ValueKey('progress-pdf'),
            tooltip: 'Progress report PDF',
            icon: const Icon(Icons.picture_as_pdf_outlined),
            onPressed: () => runAction(
              context,
              () => ref
                  .read(projectDeliveryApiProvider)
                  .openProgressPdf(p.id, p.code),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              '${p.code} · ${projectStatusLabel(p.status)}'
              '${p.startDate != null || p.endDate != null ? ' · ${p.startDate ?? '?'} → ${p.endDate ?? '?'}' : ''}',
            ),
            const SizedBox(height: 16),
            SectionCard(
              title: 'Progress',
              action: TextButton.icon(
                key: const ValueKey('progress-history'),
                icon: const Icon(Icons.history, size: 18),
                label: const Text('History'),
                onPressed: () => showProgressHistory(context, ref, p.id),
              ),
              children: [
                ProgressHeadline(
                  percent: p.progressPercent,
                  expected: p.expectedPercent,
                  behind: p.behindSchedule,
                ),
                const Divider(height: 1),
                StageList(stages: p.stages),
              ],
            ),
            SectionCard(
              title: 'Payment milestones',
              children: [
                if (p.milestones.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(12),
                    child: Text(
                      'The Project Manager has not set any milestones yet.',
                    ),
                  ),
                for (final m in p.milestones)
                  MilestoneTile(
                    projectId: p.id,
                    milestone: m,
                    onChanged: _load,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
