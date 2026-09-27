import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../data/api_projects_repository.dart';
import '../domain/project.dart';

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
      context.go('/projects');
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

    return Scaffold(
      appBar: AppBar(title: Text(project.name)),
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
              _row('Location', project.location),
              _row(
                'Status',
                project.status.replaceAll('_', ' '),
                key: const ValueKey('detail-status'),
              ),
              _row('Start date', project.startDate),
              _row('End date', project.endDate),
              _row('Project manager', project.projectManagerName),
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
            if (scope.canManageProjects)
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const ValueKey('detail-edit'),
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit'),
                      onPressed: () =>
                          context.push('/projects/${project.id}/edit'),
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
                      onPressed: () => _delete(project),
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
