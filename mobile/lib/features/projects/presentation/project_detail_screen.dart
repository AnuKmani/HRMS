import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/router/return_navigation.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../data/api_projects_repository.dart';
import '../domain/project.dart';
import '../data/project_delivery_api.dart';
import '../domain/project_delivery.dart';
import 'project_delivery_sections.dart';

/// One project, in full.
class ProjectDetailScreen extends ConsumerStatefulWidget {
  const ProjectDetailScreen({super.key, required this.projectId});

  final int projectId;

  @override
  ConsumerState<ProjectDetailScreen> createState() =>
      _ProjectDetailScreenState();
}

class _ProjectDetailScreenState extends ConsumerState<ProjectDetailScreen> {
  Project? _project;
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
      final project = await ref
          .read(projectsRepositoryProvider)
          .find(widget.projectId);

      if (!mounted) return;

      setState(() {
        _project = project;
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
        _message = 'Something went wrong while loading this project. Please try again.';
      });
    }
  }

  Future<void> _delete(Project project) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove project?'),
        content: Text(
          '${project.name} will be removed from the project list. '
          'Its sites must be moved or removed first — the API refuses this '
          'while any remain.',
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
      await ref.read(projectsRepositoryProvider).remove(project.id);

      if (!mounted) return;
      context.returnTo('/projects');
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
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final project = _project;

    if (project == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Project')),
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
                  _message ?? 'This project could not be loaded.',
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

    // Every Project Manager sees every project; only the one who added it
    // (or Super Admin) changes it. The server says which.
    final canEdit = project.canEdit ?? scope.canManageProjects;

    return Scaffold(
      appBar: AppBar(
        title: Text(project.name),
        // At the top so they are always in reach, however long the page.
        actions: [
          if (canEdit) ...[
            IconButton(
              key: const ValueKey('detail-edit'),
              tooltip: 'Edit project',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () => context.push('/projects/${project.id}/edit'),
            ),
            IconButton(
              key: const ValueKey('detail-delete'),
              tooltip: 'Remove project',
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _delete(project),
            ),
          ],
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              project.name,
              key: const ValueKey('detail-name'),
              style: theme.textTheme.headlineSmall,
            ),
            const SizedBox(height: 4),
            Text(project.summary, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 24),
            _section('Details', [
              _row('Code', project.code),
              _row('Client', project.client),
              _row('Main contractor', project.mainContractor),
              _row('Location', project.location),
              _row(
                'Status',
                project.statusLabel,
                key: const ValueKey('detail-status'),
              ),
              _row('Start date', project.startDate),
              _row('End date', project.endDate),
              _row('Project manager', project.projectManagerName),
              _row('Added by', project.createdByName),
              _row(
                'Sites',
                project.sitesCount?.toString(),
                key: const ValueKey('detail-sites-count'),
              ),
              _row(
                'People assigned',
                project.employeesCount?.toString(),
                key: const ValueKey('detail-employees-count'),
              ),
            ]),
            if (project.canReassign)
              Padding(
                padding: const EdgeInsets.only(bottom: 16),
                child: OutlinedButton.icon(
                  key: const ValueKey('reassign-pm'),
                  icon: const Icon(Icons.swap_horiz),
                  label: const Text('Change Project Manager'),
                  onPressed: () => _reassign(project),
                ),
              ),
            ProjectDeliverySections(
              project: project,
              canEdit: canEdit,
              canCoordinate: canEdit || project.canCoordinate,
              canSeeMoney: project.canSeeMoney,
              canBill: canEdit || scope.canManageBilling,
              onChanged: _load,
            ),
            _eventsSection(
              project,
              canManage: canEdit || project.canCoordinate,
            ),
            if (project.description != null &&
                project.description!.trim().isNotEmpty)
              _section('Description', [
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 10,
                  ),
                  child: Text(project.description!),
                ),
              ]),
            const SizedBox(height: 8),
            if (scope.canManageProjects && !canEdit)
              Padding(
                key: const ValueKey('detail-read-only'),
                padding: const EdgeInsets.only(bottom: 16),
                child: Text(
                  'Only ${project.createdByName ?? 'the Project Manager who added this project'} can change it.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// Move the project to another Project Manager.
  Future<void> _reassign(Project project) async {
    final api = ref.read(projectDeliveryApiProvider);
    final candidates = await api.managerCandidates().catchError(
      (_) => <TeamCandidate>[],
    );
    if (!mounted) return;
    final chosen = await showDialog<TeamCandidate>(
      context: context,
      builder: (context) => SimpleDialog(
        title: const Text('New Project Manager'),
        children: [
          if (candidates.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text('No Project Managers found.'),
            ),
          for (final c in candidates)
            SimpleDialogOption(
              key: ValueKey('pm-${c.id}'),
              onPressed: () => Navigator.pop(context, c),
              child: Text(
                [c.name, ?c.role].join(' · '),
                style: TextStyle(
                  fontWeight: c.id == project.projectManagerId
                      ? FontWeight.bold
                      : null,
                ),
              ),
            ),
        ],
      ),
    );
    if (chosen == null || !mounted) return;
    if (await runAction(
      context,
      () => api.reassign(project.id, chosen.id),
      done: '${project.name} now runs under ${chosen.name}.',
    )) {
      await _load();
    }
  }

  /// DCD / Civil Defence / handover dates. Everyone who may see the
  /// project reads them; whoever runs it books and cancels them.
  Widget _eventsSection(Project project, {required bool canManage}) {
    final today = DateTime.now();
    final events = project.events.isNotEmpty
        ? project.events
        : project.upcomingEvents;

    return _section('Inspections & key dates', [
      if (events.isEmpty)
        const Padding(
          key: ValueKey('events-none'),
          padding: EdgeInsets.all(12),
          child: Text('No dates booked yet.'),
        ),
      for (final event in events)
        ListTile(
          key: ValueKey('event-${event.id}'),
          leading: Icon(
            event.isPast(today) ? Icons.event_available : Icons.event,
            color: event.isPast(today) ? Colors.grey : Colors.deepOrange,
          ),
          title: Text(
            event.label,
            style: TextStyle(
              color: event.isPast(today) ? Colors.grey : null,
              fontWeight: event.isPast(today) ? null : FontWeight.w600,
            ),
          ),
          subtitle: Text(
            [
              event.prettyDate,
              if (event.isPast(today)) 'done',
              if (event.notes != null && event.notes!.trim().isNotEmpty)
                event.notes!,
            ].join(' · '),
          ),
          trailing: canManage
              ? IconButton(
                  key: ValueKey('event-remove-${event.id}'),
                  tooltip: 'Remove date',
                  icon: const Icon(Icons.close),
                  onPressed: () => _removeEvent(project, event),
                )
              : null,
        ),
      if (canManage)
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
          child: SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              key: const ValueKey('event-add'),
              icon: const Icon(Icons.add),
              label: const Text('Add inspection / date'),
              onPressed: () => _addEvent(project),
            ),
          ),
        ),
    ]);
  }

  Future<void> _addEvent(Project project) async {
    final body = await showDialog<Map<String, Object?>>(
      context: context,
      builder: (context) => const _EventDialog(),
    );
    if (body == null || !mounted) return;

    try {
      await ref.read(projectsRepositoryProvider).addEvent(project.id, body);
      await _load();
    } on ApiException catch (failure) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            failure.errors.values.isNotEmpty
                ? failure.errors.values.first
                : failure.message,
          ),
        ),
      );
    }
  }

  Future<void> _removeEvent(Project project, ProjectEvent event) async {
    try {
      await ref
          .read(projectsRepositoryProvider)
          .removeEvent(project.id, event.id);
      await _load();
    } on ApiException catch (failure) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }
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

/// Which inspection, and when.
class _EventDialog extends StatefulWidget {
  const _EventDialog();

  @override
  State<_EventDialog> createState() => _EventDialogState();
}

class _EventDialogState extends State<_EventDialog> {
  String _type = ProjectEvent.types.first.$1;
  DateTime? _date;
  final TextEditingController _notes = TextEditingController();

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _date ?? now,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 5),
    );
    if (picked != null) setState(() => _date = picked);
  }

  @override
  Widget build(BuildContext context) {
    final date = _date;

    return AlertDialog(
      title: const Text('Add inspection / date'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            DropdownButtonFormField<String>(
              key: const ValueKey('event-type'),
              initialValue: _type,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'What'),
              items: [
                for (final (value, label) in ProjectEvent.types)
                  DropdownMenuItem(value: value, child: Text(label)),
              ],
              onChanged: (value) {
                if (value != null) setState(() => _type = value);
              },
            ),
            const SizedBox(height: 8),
            ListTile(
              key: const ValueKey('event-date'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.calendar_today),
              title: Text(date == null ? 'Choose the date' : _ymd(date)),
              onTap: _pickDate,
            ),
            TextField(
              key: const ValueKey('event-notes'),
              controller: _notes,
              maxLength: 500,
              decoration: const InputDecoration(labelText: 'Notes (optional)'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('event-save'),
          onPressed: date == null
              ? null
              : () => Navigator.of(context).pop(<String, Object?>{
                  'type': _type,
                  'event_date': _ymd(date),
                  if (_notes.text.trim().isNotEmpty)
                    'notes': _notes.text.trim(),
                }),
          child: const Text('Save'),
        ),
      ],
    );
  }
}
