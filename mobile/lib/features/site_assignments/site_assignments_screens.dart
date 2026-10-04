import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_exception.dart';
import '../../core/permissions/permission_scope.dart';
import '../../core/presentation/fields.dart';
import '../../core/presentation/form_controls.dart';
import '../../core/presentation/paged_list_view.dart';
import '../../core/presentation/remote_picker.dart';
import '../../core/router/return_navigation.dart';
import '../employees/domain/employee.dart';
import '../employees/presentation/employees_controller.dart';
import '../sites/data/api_sites_repository.dart';
import '../sites/domain/site.dart';
import '../sites/presentation/sites_controller.dart';
import 'site_assignments.dart';

/// Who is posted to which site. An employee can only check in at a site
/// they hold an active assignment to, so this is the screen HR uses to
/// make attendance possible at all.
class SiteAssignmentsScreen extends ConsumerWidget {
  const SiteAssignmentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageAssignments),
    );
    final state = ref.watch(siteAssignmentsListProvider);
    final controller = ref.read(siteAssignmentsListProvider.notifier);
    final status = state.query['status'] as String? ?? '';

    return Scaffold(
      appBar: AppBar(
        title: const Text('Site assignments'),
        actions: [
          if (canManage)
            TextButton.icon(
              key: const ValueKey('open-quick-setup'),
              onPressed: () => context.push('/site-assignments/quick'),
              icon: const Icon(Icons.bolt),
              label: const Text('Quick setup'),
            ),
        ],
      ),
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              key: const ValueKey('add-assignment'),
              icon: const Icon(Icons.person_add_alt_1),
              label: const Text('Assign'),
              onPressed: () => context.push('/site-assignments/new'),
            )
          : null,
      body: PagedListView<SiteAssignment>(
        provider: siteAssignmentsListProvider,
        emptyMessage: status == SiteAssignment.statusActive
            ? 'Nobody is assigned to a site yet.'
            : 'No assignments match.',
        emptyHint: canManage
            ? 'Tap Assign to post an employee to a site.'
            : 'HR posts employees to sites here.',
        filter: SegmentedButton<String>(
          key: const ValueKey('assignment-status-filter'),
          segments: const [
            ButtonSegment(
              value: SiteAssignment.statusActive,
              label: Text('Active'),
            ),
            ButtonSegment(
              value: SiteAssignment.statusEnded,
              label: Text('Ended'),
            ),
            ButtonSegment(value: '', label: Text('All')),
          ],
          selected: {status},
          onSelectionChanged: (selection) =>
              controller.setFilter('status', selection.first),
        ),
        itemBuilder: (context, assignment, index) => ListTile(
          key: ValueKey('assignment-row-${assignment.id}'),
          leading: CircleAvatar(
            backgroundColor: assignment.isActive
                ? Theme.of(context).colorScheme.primaryContainer
                : Theme.of(context).colorScheme.surfaceContainerHighest,
            child: const Icon(Icons.location_on_outlined),
          ),
          title: Text(
            assignment.employeeName == null
                ? 'Employee #${assignment.employeeId}'
                : '${assignment.employeeName}'
                      '${assignment.employeeCode == null ? '' : ' (${assignment.employeeCode})'}',
          ),
          subtitle: Text(
            '${assignment.siteName ?? 'Site ${assignment.siteId}'}'
            '${assignment.projectName == null ? '' : ' · ${assignment.projectName}'}\n'
            '${assignment.typeLabel} · from ${assignment.startDate}'
            '${assignment.endDate == null ? '' : ' to ${assignment.endDate}'}',
          ),
          isThreeLine: true,
          trailing: Chip(
            label: Text(assignment.statusLabel),
            visualDensity: VisualDensity.compact,
          ),
          onTap: canManage && assignment.isActive
              ? () => _confirmEnd(context, ref, assignment)
              : null,
        ),
      ),
    );
  }

  Future<void> _confirmEnd(
    BuildContext context,
    WidgetRef ref,
    SiteAssignment assignment,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('End this assignment?'),
        content: Text(
          '${assignment.employeeName ?? 'This employee'} will no longer be '
          'able to check in at ${assignment.siteName ?? 'this site'} from '
          'tomorrow. The history is kept.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('confirm-end-assignment'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('End assignment'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) return;

    try {
      await ref
          .read(siteAssignmentsRepositoryProvider)
          .end(assignment.id, endDate: _today());
      ref.read(siteAssignmentsListProvider.notifier).reload();
    } on ApiException catch (failure) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }
  }
}

/// Posting an employee to a site. The project comes from the site — a site
/// belongs to exactly one — so HR picks two things, not three.
class SiteAssignmentFormScreen extends ConsumerStatefulWidget {
  const SiteAssignmentFormScreen({super.key});

  @override
  ConsumerState<SiteAssignmentFormScreen> createState() =>
      _SiteAssignmentFormScreenState();
}

class _SiteAssignmentFormScreenState
    extends ConsumerState<SiteAssignmentFormScreen> {
  final _start = TextEditingController(text: _today());
  final _end = TextEditingController();

  int? _employeeId;
  int? _siteId;
  int? _projectId;
  String? _projectName;
  String _type = SiteAssignment.typePrimary;

  bool _saving = false;
  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const {};

  @override
  void dispose() {
    _start.dispose();
    _end.dispose();
    super.dispose();
  }

  Future<void> _pickSite(int? id) async {
    setState(() {
      _siteId = id;
      _projectId = null;
      _projectName = null;
    });
    if (id == null) return;

    try {
      final Site site = await ref.read(sitesRepositoryProvider).find(id);
      if (!mounted || _siteId != id) return;
      setState(() {
        _projectId = site.projectId;
        _projectName = site.projectName;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() => _banner = failure.message);
    }
  }

  Future<void> _submit() async {
    final errors = <String, String>{
      if (_employeeId == null) 'employee_id': 'Choose an employee.',
      if (_siteId == null) 'site_id': 'Choose a site.',
      if (_siteId != null && _projectId == null)
        'site_id': 'This site has no project. Set one on the site first.',
      if (_start.text.trim().isEmpty) 'start_date': 'Choose a start date.',
    };
    if (errors.isNotEmpty) {
      setState(() => _errors = errors);
      return;
    }

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const {};
    });

    try {
      await ref.read(siteAssignmentsRepositoryProvider).create({
        'employee_id': _employeeId,
        'site_id': _siteId,
        'project_id': _projectId,
        'assignment_type': _type,
        'start_date': _start.text.trim(),
        if (_end.text.trim().isNotEmpty) 'end_date': _end.text.trim(),
      });

      ref.read(siteAssignmentsListProvider.notifier).reload();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Employee assigned to the site.')),
      );
      context.returnTo('/site-assignments');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Assign to a site')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            FormBanner(message: _banner!, forbidden: _forbidden),
          RemotePickerField<Employee>(
            key: const ValueKey('assignment-employee'),
            label: 'Employee',
            provider: employeesPickerProvider,
            idOf: (employee) => employee.id,
            labelOf: (employee) => employee.fullName,
            value: _employeeId,
            isRequired: true,
            hint: 'Choose an employee',
            searchHint: 'Search employees',
            errorText: _errors['employee_id'],
            onChanged: (id) => setState(() => _employeeId = id),
          ),
          RemotePickerField<Site>(
            key: const ValueKey('assignment-site'),
            label: 'Site',
            provider: sitesPickerProvider,
            idOf: (site) => site.id,
            labelOf: (site) => site.name,
            value: _siteId,
            isRequired: true,
            hint: 'Choose a site',
            searchHint: 'Search sites',
            errorText: _errors['site_id'] ?? _errors['project_id'],
            helper: _projectName == null ? null : 'Project: $_projectName',
            onChanged: _pickSite,
          ),
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: DropdownButtonFormField<String>(
              key: const ValueKey('assignment-type'),
              initialValue: _type,
              decoration: InputDecoration(
                labelText: 'Assignment type',
                errorText: _errors['assignment_type'],
              ),
              items: const [
                DropdownMenuItem(
                  value: SiteAssignment.typePrimary,
                  child: Text('Primary — their usual site'),
                ),
                DropdownMenuItem(
                  value: SiteAssignment.typeTemporary,
                  child: Text('Temporary — for a period'),
                ),
                DropdownMenuItem(
                  value: SiteAssignment.typeAdditional,
                  child: Text('Additional — a second site'),
                ),
              ],
              onChanged: _saving
                  ? null
                  : (value) => setState(
                      () => _type = value ?? SiteAssignment.typePrimary,
                    ),
            ),
          ),
          DateField(
            key: const ValueKey('assignment-start'),
            label: 'Start date',
            controller: _start,
            isRequired: true,
            errorText: _errors['start_date'],
          ),
          DateField(
            key: const ValueKey('assignment-end'),
            label: 'End date (optional)',
            controller: _end,
            allowEmpty: true,
            errorText: _errors['end_date'],
          ),
          const SizedBox(height: 8),
          FilledButton.icon(
            key: const ValueKey('save-assignment'),
            style: FilledButton.styleFrom(
              minimumSize: const Size.fromHeight(52),
            ),
            onPressed: _saving ? null : _submit,
            icon: _saving
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.check),
            label: const Text('Assign'),
          ),
        ],
      ),
    );
  }
}

String _today() {
  final now = DateTime.now();
  return '${now.year}-${now.month.toString().padLeft(2, '0')}-'
      '${now.day.toString().padLeft(2, '0')}';
}
