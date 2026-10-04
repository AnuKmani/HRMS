import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/permissions/permission_scope.dart';
import '../../core/presentation/fields.dart';
import '../../core/presentation/form_controls.dart';
import '../../core/presentation/remote_picker.dart';
import '../../core/router/return_navigation.dart';
import '../attendance/data/device_location.dart';
import '../employees/domain/employee.dart';
import '../employees/presentation/employees_controller.dart';
import '../projects/data/api_projects_repository.dart';
import '../projects/domain/project.dart';
import '../projects/presentation/projects_controller.dart';
import '../sites/data/api_sites_repository.dart';
import 'site_assignments.dart';

/// One screen that makes an employee able to check in: stand at the site,
/// pick the person, tap once.
///
/// It is not a shortcut around any rule — it calls the same three endpoints
/// HR would otherwise use one at a time (`POST /projects` when no project is
/// chosen, `POST /sites`, `POST /employee-site-assignments`), each behind
/// its own permission on the server. The site's position is this phone's
/// GPS reading; the radius is whatever HR types.
class QuickSiteSetupScreen extends ConsumerStatefulWidget {
  const QuickSiteSetupScreen({super.key});

  @override
  ConsumerState<QuickSiteSetupScreen> createState() =>
      _QuickSiteSetupScreenState();
}

class _QuickSiteSetupScreenState extends ConsumerState<QuickSiteSetupScreen> {
  final _siteName = TextEditingController(text: 'Main site');
  final _projectName = TextEditingController(text: 'General');
  final _radius = TextEditingController(text: '200');

  /// Everyone being posted here — one person for a construction site, the
  /// whole office team for a head office. Names are kept for the chips.
  final Map<int, String> _employees = {};
  int? _projectId;

  bool _busy = false;
  String _step = '';
  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const {};

  @override
  void dispose() {
    _siteName.dispose();
    _projectName.dispose();
    _radius.dispose();
    super.dispose();
  }

  String _code(String prefix) =>
      '$prefix-${DateTime.now().millisecondsSinceEpoch.toRadixString(36).toUpperCase()}';

  Future<void> _run() async {
    final radius = double.tryParse(_radius.text.trim());
    final errors = <String, String>{
      if (_employees.isEmpty) 'employee_id': 'Add at least one employee.',
      if (_siteName.text.trim().isEmpty) 'name': 'Give the site a name.',
      if (_projectId == null && _projectName.text.trim().isEmpty)
        'project': 'Choose a project or name a new one.',
      if (radius == null || radius <= 0)
        'geofence_radius': 'Enter the allowed distance in metres.',
    };
    if (errors.isNotEmpty) {
      setState(() => _errors = errors);
      return;
    }

    setState(() {
      _busy = true;
      _banner = null;
      _errors = const {};
      _step = 'Getting your location…';
    });

    try {
      final gateway = ref.read(locationGatewayProvider);
      if (!await gateway.isServiceEnabled()) {
        throw const LocationAcquisitionFailed(
          'Turn on location (GPS) on this phone, then try again.',
        );
      }
      var status = await gateway.checkPermission();
      if (status != LocationStatus.granted) {
        status = await gateway.requestPermission();
      }
      if (status != LocationStatus.granted) {
        throw const LocationAcquisitionFailed(
          'Allow location access for HRMS, then try again.',
        );
      }
      final fix = await gateway.currentFix();

      var projectId = _projectId;
      if (projectId == null) {
        setState(() => _step = 'Creating the project…');
        final Project project = await ref
            .read(projectsRepositoryProvider)
            .create({
              'name': _projectName.text.trim(),
              'code': _code('PRJ'),
              'status': 'active',
            });
        projectId = project.id;
      }

      setState(() => _step = 'Creating the site at your location…');
      final site = await ref.read(sitesRepositoryProvider).create({
        'project_id': projectId,
        'name': _siteName.text.trim(),
        'code': _code('SITE'),
        'latitude': double.parse(fix.latitude.toStringAsFixed(7)),
        'longitude': double.parse(fix.longitude.toStringAsFixed(7)),
        'geofence_radius': radius,
        'status': 'active',
      });

      final now = DateTime.now();
      final today =
          '${now.year}-${now.month.toString().padLeft(2, '0')}-'
          '${now.day.toString().padLeft(2, '0')}';
      var done = 0;
      final failed = <String>[];
      for (final entry in _employees.entries) {
        setState(
          () => _step = 'Assigning ${done + 1} of ${_employees.length}…',
        );
        try {
          await ref.read(siteAssignmentsRepositoryProvider).create({
            'employee_id': entry.key,
            'project_id': projectId,
            'site_id': site.id,
            'assignment_type': SiteAssignment.typePrimary,
            'start_date': today,
          });
          done++;
        } on ApiException catch (failure) {
          failed.add('${entry.value}: ${failure.message}');
        }
      }

      ref.read(siteAssignmentsListProvider.notifier).reload();
      if (!mounted) return;
      setState(() {
        _busy = false;
        _step = '';
      });

      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.check_circle, color: Colors.green, size: 48),
          title: const Text('All set'),
          content: Text(
            '"${site.name}" was created at your location '
            '(±${fix.accuracy.round()} m) with a ${radius!.round()} m '
            'check-in area.\n\n'
            '$done ${done == 1 ? 'person is' : 'people are'} assigned and '
            'can now sign in and tap CHECK IN.'
            '${failed.isEmpty ? '' : '\n\nNot assigned:\n${failed.join('\n')}'}',
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Done'),
            ),
          ],
        ),
      );
      if (!mounted) return;
      context.returnTo('/site-assignments');
    } on LocationAcquisitionFailed catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _banner = failure.message;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty
            ? failure.message
            : 'Please check the highlighted fields.';
      });
    }
  }

  void _addEmployee(int? id) {
    if (id == null) return;
    final match = ref
        .read(employeesPickerProvider)
        .items
        .where((employee) => employee.id == id);
    setState(() {
      _employees[id] = match.isEmpty ? 'Employee #$id' : match.first.fullName;
      _errors = const {};
    });
  }

  @override
  Widget build(BuildContext context) {
    final canCreateProjects = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageProjects),
    );
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Quick site setup')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            margin: const EdgeInsets.only(bottom: 16),
            decoration: BoxDecoration(
              color: theme.colorScheme.primaryContainer,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Row(
              children: [
                Icon(Icons.my_location),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Stand at the site or office. Its location is taken '
                    'from this phone\'s GPS when you tap Set up. For office '
                    'staff, name it Head Office and add everyone in the '
                    'office.',
                  ),
                ),
              ],
            ),
          ),
          if (_banner != null)
            FormBanner(message: _banner!, forbidden: _forbidden),
          RemotePickerField<Employee>(
            key: const ValueKey('quick-employee'),
            label: _employees.isEmpty ? 'Employees' : 'Add another employee',
            provider: employeesPickerProvider,
            idOf: (employee) => employee.id,
            labelOf: (employee) => employee.fullName,
            value: null,
            isRequired: _employees.isEmpty,
            hint: 'Tap to add someone who works here',
            searchHint: 'Search employees',
            errorText: _errors['employee_id'],
            onChanged: _addEmployee,
          ),
          if (_employees.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  for (final entry in _employees.entries)
                    InputChip(
                      key: ValueKey('quick-chosen-${entry.key}'),
                      avatar: const Icon(Icons.person, size: 18),
                      label: Text(entry.value),
                      onDeleted: _busy
                          ? null
                          : () => setState(() => _employees.remove(entry.key)),
                    ),
                ],
              ),
            ),
          LabeledTextField(
            key: const ValueKey('quick-site-name'),
            label: 'Site or office name',
            controller: _siteName,
            isRequired: true,
            enabled: !_busy,
            errorText: _errors['name'],
          ),
          RemotePickerField<Project>(
            key: const ValueKey('quick-project'),
            label: 'Project',
            provider: projectsPickerProvider,
            idOf: (project) => project.id,
            labelOf: (project) => project.name,
            value: _projectId,
            hint: canCreateProjects
                ? 'None — create a new one below'
                : 'Choose a project',
            searchHint: 'Search projects',
            errorText: _errors['project_id'],
            onChanged: (id) => setState(() => _projectId = id),
          ),
          if (_projectId == null && canCreateProjects)
            LabeledTextField(
              key: const ValueKey('quick-project-name'),
              label: 'New project name',
              controller: _projectName,
              enabled: !_busy,
              errorText: _errors['project'],
            ),
          LabeledTextField(
            key: const ValueKey('quick-radius'),
            label: 'Check-in area (metres)',
            controller: _radius,
            isRequired: true,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            helper: 'How far from this spot people may check in.',
            errorText: _errors['geofence_radius'],
          ),
          const SizedBox(height: 8),
          FilledButton.icon(
            key: const ValueKey('quick-setup-run'),
            style: FilledButton.styleFrom(
              minimumSize: const Size.fromHeight(56),
            ),
            onPressed: _busy ? null : _run,
            icon: _busy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.bolt),
            label: Text(_busy ? _step : 'Set up'),
          ),
        ],
      ),
    );
  }
}
