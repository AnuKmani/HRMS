import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/permissions/permission_scope.dart';
import '../projects/data/api_projects_repository.dart';
import '../projects/domain/project.dart';
import '../sites/data/api_sites_repository.dart';
import '../sites/domain/site.dart';
import 'site_visit_plans.dart';

/// Planned site visits. A Project Manager sees the visits they planned
/// (with each one's status) and plans new ones; an engineer sees the
/// visits planned for them and marks one completed.
class SiteVisitPlansScreen extends ConsumerStatefulWidget {
  const SiteVisitPlansScreen({super.key});

  @override
  ConsumerState<SiteVisitPlansScreen> createState() =>
      _SiteVisitPlansScreenState();
}

class _SiteVisitPlansScreenState extends ConsumerState<SiteVisitPlansScreen> {
  List<SiteVisitPlan>? _plans;
  String? _error;
  String _filter = 'all';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final plans = await ref
          .read(siteVisitPlansRepositoryProvider)
          .list(status: _filter == 'all' ? null : _filter);
      if (!mounted) return;
      setState(() => _plans = plans);
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() => _error = failure.message);
    }
  }

  void _snack(String text) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(text)));

  Future<void> _complete(SiteVisitPlan plan) async {
    final note = await showDialog<String>(
      context: context,
      builder: (context) => const _CompleteDialog(),
    );
    if (note == null || !mounted) return;
    try {
      await ref
          .read(siteVisitPlansRepositoryProvider)
          .complete(plan.id, note: note.isEmpty ? null : note);
      _snack('Marked as completed. Your project manager has been told.');
      ref.invalidate(myUpcomingPlansProvider);
      await _load();
    } on ApiException catch (failure) {
      _snack(failure.message);
    }
  }

  Future<void> _cancel(SiteVisitPlan plan) async {
    try {
      await ref.read(siteVisitPlansRepositoryProvider).cancel(plan.id);
      _snack('Visit cancelled.');
      await _load();
    } on ApiException catch (failure) {
      _snack(failure.message);
    }
  }

  Future<void> _plan() async {
    final created = await Navigator.of(context).push<int>(
      MaterialPageRoute(builder: (_) => const PlanSiteVisitsScreen()),
    );
    if (created != null && mounted) {
      _snack(
        created == 1
            ? 'Visit planned. The engineer has been told.'
            : '$created visits planned. The engineers have been told.',
      );
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final canPlan = ref.watch(
      permissionScopeProvider.select((s) => s.canPlanSiteVisits),
    );
    final plans = _plans;

    return Scaffold(
      appBar: AppBar(title: const Text('Planned site visits')),
      floatingActionButton: canPlan
          ? FloatingActionButton.extended(
              key: const ValueKey('plan-visits'),
              onPressed: _plan,
              icon: const Icon(Icons.add_task),
              label: const Text('Plan visits'),
            )
          : null,
      body: Column(
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: Row(
              children: [
                for (final (value, label) in const [
                  ('all', 'All'),
                  ('upcoming', 'Upcoming'),
                  ('completed', 'Completed'),
                  ('missed', 'Missed'),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      key: ValueKey('plans-filter-$value'),
                      label: Text(label),
                      selected: _filter == value,
                      onSelected: (_) {
                        setState(() => _filter = value);
                        _load();
                      },
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: _error != null
                ? Center(child: Text(_error!))
                : plans == null
                ? const Center(child: CircularProgressIndicator())
                : plans.isEmpty
                ? Center(
                    key: const ValueKey('plans-empty'),
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text(
                        canPlan
                            ? 'No visits here. Tap Plan visits to send '
                                  'engineers to a project.'
                            : 'No site visits planned for you here.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView.builder(
                      padding: const EdgeInsets.fromLTRB(12, 12, 12, 96),
                      itemCount: plans.length,
                      itemBuilder: (context, i) => PlanCard(
                        plan: plans[i],
                        onComplete: plans[i].isMine && plans[i].isOpen
                            ? () => _complete(plans[i])
                            : null,
                        onCancel: plans[i].assignedByMe && plans[i].isOpen
                            ? () => _cancel(plans[i])
                            : null,
                      ),
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

class PlanStatusChip extends StatelessWidget {
  const PlanStatusChip(this.plan, {super.key});

  final SiteVisitPlan plan;

  @override
  Widget build(BuildContext context) {
    final color = switch (plan.status) {
      'completed' => const Color(0xFF16A34A),
      'missed' => const Color(0xFFDC2626),
      'cancelled' => const Color(0xFF64748B),
      _ => const Color(0xFF2563EB),
    };
    return Chip(
      key: ValueKey('plan-status-${plan.id}'),
      label: Text(
        plan.statusLabel,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w600,
        ),
      ),
      backgroundColor: color,
      side: BorderSide.none,
      visualDensity: VisualDensity.compact,
    );
  }
}

class PlanCard extends StatelessWidget {
  const PlanCard({
    super.key,
    required this.plan,
    this.onComplete,
    this.onCancel,
  });

  final SiteVisitPlan plan;
  final VoidCallback? onComplete;
  final VoidCallback? onCancel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      key: ValueKey('plan-${plan.id}'),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.event, size: 18),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    plan.prettyDate,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                PlanStatusChip(plan),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              plan.place,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            Text(plan.purpose),
            if (plan.notes != null && plan.notes!.isNotEmpty)
              Text(plan.notes!, style: theme.textTheme.bodySmall),
            const SizedBox(height: 4),
            Text(
              [
                if (!plan.isMine && plan.employeeName != null)
                  'Engineer: ${plan.employeeName}',
                if (!plan.assignedByMe && plan.assignedByName != null)
                  'Planned by: ${plan.assignedByName}',
                if (plan.completionNote != null &&
                    plan.completionNote!.isNotEmpty)
                  'Note: ${plan.completionNote}',
              ].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
            if (onComplete != null || onCancel != null) ...[
              const SizedBox(height: 8),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  if (onCancel != null)
                    TextButton(
                      key: ValueKey('plan-cancel-${plan.id}'),
                      onPressed: onCancel,
                      child: const Text('Cancel visit'),
                    ),
                  if (onComplete != null)
                    FilledButton.icon(
                      key: ValueKey('plan-complete-${plan.id}'),
                      onPressed: onComplete,
                      icon: const Icon(Icons.check),
                      label: const Text('Mark completed'),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _CompleteDialog extends StatefulWidget {
  const _CompleteDialog();

  @override
  State<_CompleteDialog> createState() => _CompleteDialogState();
}

class _CompleteDialogState extends State<_CompleteDialog> {
  final TextEditingController _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Mark visit completed'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Text(
          'Tip: doing START and END SITE VISIT at this site completes it '
          'automatically.',
        ),
        TextField(
          key: const ValueKey('plan-complete-note'),
          controller: _note,
          maxLength: 500,
          decoration: const InputDecoration(labelText: 'Note (optional)'),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const ValueKey('plan-complete-confirm'),
        onPressed: () => Navigator.of(context).pop(_note.text.trim()),
        child: const Text('Completed'),
      ),
    ],
  );
}

/// The Project Manager's form: engineers, project, site, day, purpose.
class PlanSiteVisitsScreen extends ConsumerStatefulWidget {
  const PlanSiteVisitsScreen({super.key});

  @override
  ConsumerState<PlanSiteVisitsScreen> createState() =>
      _PlanSiteVisitsScreenState();
}

class _PlanSiteVisitsScreenState extends ConsumerState<PlanSiteVisitsScreen> {
  List<PlanAssignee>? _people;
  List<Project>? _projects;
  List<Site> _sites = const [];
  final Set<int> _chosen = {};
  int? _projectId;
  int? _siteId;
  DateTime? _date;
  final TextEditingController _purpose = TextEditingController();
  final TextEditingController _notes = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadChoices();
  }

  @override
  void dispose() {
    _purpose.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _loadChoices() async {
    try {
      final people = await ref
          .read(siteVisitPlansRepositoryProvider)
          .assignees();
      final projects = await ref
          .read(projectsRepositoryProvider)
          .list(query: const {'per_page': 100});
      if (!mounted) return;
      setState(() {
        _people = people;
        _projects = projects.items;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() => _error = failure.message);
    }
  }

  Future<void> _pickProject(int? id) async {
    setState(() {
      _projectId = id;
      _siteId = null;
      _sites = const [];
    });
    if (id == null) return;
    try {
      final sites = await ref
          .read(sitesRepositoryProvider)
          .list(query: {'project_id': id, 'per_page': 100});
      if (!mounted || _projectId != id) return;
      setState(() => _sites = sites.items);
    } on ApiException {
      // The site is optional; the project alone is a valid plan.
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final picked = await showDatePicker(
      context: context,
      initialDate: _date ?? today,
      firstDate: today,
      lastDate: today.add(const Duration(days: 31)),
      helpText: 'Visit date (up to a month ahead)',
    );
    if (picked != null) setState(() => _date = picked);
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  bool get _ready =>
      _chosen.isNotEmpty &&
      _projectId != null &&
      _date != null &&
      _purpose.text.trim().isNotEmpty;

  Future<void> _save() async {
    if (!_ready) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final created = await ref.read(siteVisitPlansRepositoryProvider).create({
        'employee_ids': _chosen.toList(),
        'project_id': _projectId,
        'site_id': ?_siteId,
        'visit_date': _ymd(_date!),
        'purpose': _purpose.text.trim(),
        if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
      });
      if (!mounted) return;
      Navigator.of(context).pop(created.length);
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = failure.errors.values.isNotEmpty
            ? failure.errors.values.first
            : failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final people = _people;
    final projects = _projects;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Plan site visits')),
      body: people == null || projects == null
          ? Center(
              child: _error != null
                  ? Text(_error!)
                  : const CircularProgressIndicator(),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(
                      _error!,
                      key: const ValueKey('plan-error'),
                      style: TextStyle(color: theme.colorScheme.error),
                    ),
                  ),
                Text('Engineers', style: theme.textTheme.titleSmall),
                const SizedBox(height: 4),
                if (people.isEmpty)
                  const Text('Nobody has the Site visit option yet.'),
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    for (final person in people)
                      FilterChip(
                        key: ValueKey('plan-person-${person.id}'),
                        label: Text(
                          person.role == null
                              ? person.name
                              : '${person.name} · ${person.role}',
                        ),
                        selected: _chosen.contains(person.id),
                        onSelected: (on) => setState(
                          () => on
                              ? _chosen.add(person.id)
                              : _chosen.remove(person.id),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<int>(
                  key: const ValueKey('plan-project'),
                  initialValue: _projectId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Project'),
                  items: [
                    for (final project in projects)
                      DropdownMenuItem(
                        value: project.id,
                        child: Text(
                          project.name,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: _pickProject,
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int?>(
                  key: ValueKey('plan-site-$_projectId'),
                  initialValue: _siteId,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Site (optional)',
                  ),
                  items: [
                    const DropdownMenuItem<int?>(
                      value: null,
                      child: Text('Any site of the project'),
                    ),
                    for (final site in _sites)
                      DropdownMenuItem<int?>(
                        value: site.id,
                        child: Text(site.name, overflow: TextOverflow.ellipsis),
                      ),
                  ],
                  onChanged: (id) => setState(() => _siteId = id),
                ),
                const SizedBox(height: 12),
                ListTile(
                  key: const ValueKey('plan-date'),
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.calendar_month),
                  title: Text(
                    _date == null ? 'Choose the visit date' : _ymd(_date!),
                  ),
                  subtitle: const Text('Today up to one month ahead'),
                  onTap: _pickDate,
                ),
                TextField(
                  key: const ValueKey('plan-purpose'),
                  controller: _purpose,
                  maxLength: 150,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    labelText: 'Purpose',
                    hintText: 'Inspection, snag list, handover…',
                  ),
                ),
                TextField(
                  controller: _notes,
                  maxLength: 500,
                  decoration: const InputDecoration(
                    labelText: 'Notes (optional)',
                  ),
                ),
                const SizedBox(height: 8),
                FilledButton.icon(
                  key: const ValueKey('plan-save'),
                  onPressed: _ready && !_saving ? _save : null,
                  icon: const Icon(Icons.send),
                  label: Text(
                    _chosen.length > 1
                        ? 'Plan for ${_chosen.length} engineers'
                        : 'Plan visit',
                  ),
                ),
              ],
            ),
    );
  }
}

/// The engineer's dashboard card: what is planned next.
class MyPlannedVisitsCard extends ConsumerWidget {
  const MyPlannedVisitsCard({super.key, required this.onOpenAll});

  final VoidCallback onOpenAll;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plans = ref.watch(myUpcomingPlansProvider);
    final theme = Theme.of(context);

    return Card(
      key: const ValueKey('my-planned-visits'),
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.assignment_outlined),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'My planned site visits',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                TextButton(onPressed: onOpenAll, child: const Text('View all')),
              ],
            ),
            switch (plans) {
              AsyncData(:final value) when value.isEmpty => const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text('Nothing planned for you right now.'),
              ),
              AsyncData(:final value) => Column(
                children: [
                  for (final plan in value.take(3))
                    ListTile(
                      key: ValueKey('my-plan-${plan.id}'),
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(
                        Icons.event,
                        color: Colors.deepOrange,
                      ),
                      title: Text(
                        '${plan.prettyDate} · ${plan.place}',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      subtitle: Text(plan.purpose),
                      trailing: PlanStatusChip(plan),
                      onTap: onOpenAll,
                    ),
                  if (value.length > 3)
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text('+${value.length - 3} more'),
                    ),
                ],
              ),
              AsyncError() => const Text('Could not load your planned visits.'),
              _ => const LinearProgressIndicator(),
            },
          ],
        ),
      ),
    );
  }
}
