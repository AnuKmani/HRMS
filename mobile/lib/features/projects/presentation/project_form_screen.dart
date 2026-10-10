import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/router/return_navigation.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/presentation/employees_controller.dart';
import '../data/api_projects_repository.dart';
import '../data/project_delivery_api.dart';
import '../domain/project.dart';
import '../domain/project_delivery.dart';
import 'project_delivery_sections.dart';
import '../domain/projects_repository.dart';

final _projectStatuses = [
  for (final (value, label) in projectStatuses) StatusOption(value, label),
];

/// Create or edit a project — [projectId] null means create.
class ProjectFormScreen extends ConsumerStatefulWidget {
  const ProjectFormScreen({super.key, this.projectId});

  final int? projectId;

  @override
  ConsumerState<ProjectFormScreen> createState() => _ProjectFormScreenState();
}

class _ProjectFormScreenState extends ConsumerState<ProjectFormScreen> {
  late final TextEditingController _name;
  late final TextEditingController _code;
  late final TextEditingController _client;
  late final TextEditingController _mainContractor;

  /// Only on create — afterwards they are managed on the project page.
  final List<Map<String, Object?>> _subcontractors = [];
  List<TeamMember> _team = const [];
  late final TextEditingController _location;
  late final TextEditingController _description;
  late final TextEditingController _startDate;
  late final TextEditingController _endDate;

  String _status = 'planned';
  int? _projectManagerId;
  String? _projectManagerLabel;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.projectId == null;

  ProjectsRepository get _repository => ref.read(projectsRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _code = TextEditingController();
    _client = TextEditingController();
    _mainContractor = TextEditingController();
    _location = TextEditingController();
    _description = TextEditingController();
    _startDate = TextEditingController();
    _endDate = TextEditingController();

    if (!_isCreate) {
      _loading = true;
      _load();
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _client.dispose();
    _mainContractor.dispose();
    _location.dispose();
    _description.dispose();
    _startDate.dispose();
    _endDate.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final project = await _repository.find(widget.projectId!);

      if (!mounted) return;

      setState(() {
        _loading = false;
        _name.text = project.name;
        _code.text = project.code;
        _client.text = project.client ?? '';
        _mainContractor.text = project.mainContractor ?? '';
        _location.text = project.location ?? '';
        _description.text = project.description ?? '';
        _startDate.text = project.startDate ?? '';
        _endDate.text = project.endDate ?? '';
        _status = project.status;
        _projectManagerId = project.projectManagerId;
        _projectManagerLabel = project.projectManagerName;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = failure.message;
        _forbidden = failure.statusCode == 403;
      });
    }
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();

    setState(() {
      _banner = null;
      _forbidden = false;
      _errors = const <String, String>{};
    });

    final local = <String, String>{};
    if (_name.text.trim().isEmpty) local['name'] = 'This field is required.';
    if (!_isCreate && _code.text.trim().isEmpty) {
      local['code'] = 'This field is required.';
    }

    if (local.isNotEmpty) {
      setState(() => _errors = local);
      return;
    }

    setState(() => _saving = true);

    String? blank(String value) {
      final trimmed = value.trim();
      return trimmed.isEmpty ? null : trimmed;
    }

    final body = <String, Object?>{
      'name': _name.text.trim(),
      'code': blank(_code.text),
      'client': blank(_client.text),
      'main_contractor': blank(_mainContractor.text),
      if (_isCreate) ...{
        'subcontractors': _subcontractors,
        'site_engineer_ids': [
          for (final m in _team)
            if (m.role == 'site_engineer') m.employeeId,
        ],
        'site_supervisor_ids': [
          for (final m in _team)
            if (m.role == 'site_supervisor') m.employeeId,
        ],
      },
      'location': blank(_location.text),
      'description': blank(_description.text),
      'project_manager_id': _projectManagerId,
      'start_date': blank(_startDate.text),
      'end_date': blank(_endDate.text),
      'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.projectId!, body);
      }

      if (!mounted) return;

      if (_isCreate) {
        context.returnTo('/projects');
      } else {
        context.returnTo('/projects/${widget.projectId}');
      }
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _saving = false;
        _banner = failure.message;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
      });

      // Someone already added this project: say so plainly.
      final duplicate = failure.errors['name'];
      if (duplicate != null && duplicate.contains('already added')) {
        await showDialog<void>(
          context: context,
          builder: (context) => AlertDialog(
            key: const ValueKey('duplicate-project'),
            icon: const Icon(Icons.info_outline),
            title: const Text('Project already added'),
            content: Text(duplicate),
            actions: [
              FilledButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('OK'),
              ),
            ],
          ),
        );
      }
    } catch (_) {
      if (!mounted) return;

      setState(() => _saving = false);
    }
  }

  /// Subcontractors and the site team, chosen while adding the project.
  List<Widget> _createExtras(BuildContext context) {
    final theme = Theme.of(context);
    return [
      const SizedBox(height: 8),
      Text('Subcontractors', style: theme.textTheme.titleSmall),
      for (var i = 0; i < _subcontractors.length; i++)
        ListTile(
          key: ValueKey('new-sub-$i'),
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.handyman_outlined),
          title: Text('${_subcontractors[i]['name']}'),
          subtitle: Text(
            [
              _subcontractors[i]['trade'],
              _subcontractors[i]['contact_name'],
              _subcontractors[i]['contact_phone'],
            ].whereType<String>().join(' · '),
          ),
          trailing: IconButton(
            icon: const Icon(Icons.close),
            onPressed: () => setState(() => _subcontractors.removeAt(i)),
          ),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const ValueKey('form-add-sub'),
          icon: const Icon(Icons.add),
          label: const Text('Add subcontractor'),
          onPressed: _saving
              ? null
              : () async {
                  final body = await showSubcontractorDialog(context);
                  if (body != null) setState(() => _subcontractors.add(body));
                },
        ),
      ),
      const SizedBox(height: 8),
      Text('Site team', style: theme.textTheme.titleSmall),
      if (_team.isEmpty)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 4),
          child: Text('No site engineers or supervisors chosen yet.'),
        ),
      for (final m in _team)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.engineering_outlined),
          title: Text(m.name),
          subtitle: Text(m.roleLabel),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const ValueKey('form-team'),
          icon: const Icon(Icons.group_add_outlined),
          label: const Text('Choose site engineers & supervisors'),
          onPressed: _saving ? null : _chooseTeam,
        ),
      ),
      const Padding(
        padding: EdgeInsets.only(bottom: 8),
        child: Text(
          'Progress stages start from the standard template '
          '(Mobilisation, Foundation, Structure, MEP, Finishing, Handover). '
          'You can change them on the project page.',
          style: TextStyle(fontSize: 12, color: Colors.grey),
        ),
      ),
    ];
  }

  Future<void> _chooseTeam() async {
    final api = ref.read(projectDeliveryApiProvider);
    final candidates = await api.teamCandidates().catchError(
      (_) => <TeamCandidate>[],
    );
    if (!mounted) return;
    final result = await showTeamPicker(context, api, _team);
    if (result == null) return;
    final names = {for (final c in candidates) c.id: c.name};
    setState(() {
      _team = [
        for (final id in result.$1)
          TeamMember(
            employeeId: id,
            name: names[id] ?? '#$id',
            role: 'site_engineer',
          ),
        for (final id in result.$2)
          TeamMember(
            employeeId: id,
            name: names[id] ?? '#$id',
            role: 'site_supervisor',
          ),
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_isCreate ? 'New project' : 'Edit project')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SafeArea(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_banner != null)
                      FormBanner(message: _banner!, forbidden: _forbidden),
                    LabeledTextField(
                      label: 'Name',
                      controller: _name,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['name'],
                      hint: 'Riverside Tower',
                    ),
                    LabeledTextField(
                      label: 'Code',
                      controller: _code,
                      isRequired: !_isCreate,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['code'],
                      hint: _isCreate ? 'Automatic (PRJ-0001…)' : 'RT-01',
                      helper: _isCreate
                          ? 'Leave blank and a code is given automatically.'
                          : 'Letters, numbers, dashes and underscores.',
                    ),
                    LabeledTextField(
                      label: 'Client',
                      controller: _client,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['client'],
                    ),
                    LabeledTextField(
                      label: 'Main contractor',
                      controller: _mainContractor,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['main_contractor'],
                    ),
                    LabeledTextField(
                      label: 'Location',
                      controller: _location,
                      enabled: !_saving,
                      errorText: _errors['location'],
                    ),
                    LabeledTextField(
                      label: 'Description',
                      controller: _description,
                      enabled: !_saving,
                      maxLines: 4,
                      errorText: _errors['description'],
                    ),
                    RemotePickerField(
                      label: 'Project manager',
                      provider: employeesPickerProvider,
                      idOf: (employee) => employee.id,
                      labelOf: (employee) => employee.fullName.isEmpty
                          ? employee.employeeCode
                          : employee.fullName,
                      value: _projectManagerId,
                      selectedLabel: _projectManagerLabel,
                      searchHint: 'Search employees',
                      sheetTitle: 'Project manager',
                      errorText: _errors['project_manager_id'],
                      onChanged: (id) => setState(() {
                        _projectManagerId = id;
                        if (id == null) _projectManagerLabel = null;
                      }),
                    ),
                    DateField(
                      label: 'Start date',
                      controller: _startDate,
                      allowEmpty: true,
                      errorText: _errors['start_date'],
                    ),
                    DateField(
                      label: 'End date',
                      controller: _endDate,
                      allowEmpty: true,
                      errorText: _errors['end_date'],
                    ),
                    StatusField(
                      label: 'Status',
                      isRequired: true,
                      options: _projectStatuses,
                      value: _status,
                      onChanged: _saving
                          ? (_) {}
                          : (v) => setState(() => _status = v),
                      errorText: _errors['status'],
                    ),
                    if (_isCreate) ..._createExtras(context),
                    const SizedBox(height: 8),
                    FilledButton(
                      key: const ValueKey('form-save'),
                      onPressed: _saving ? null : _save,
                      child: _saving
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(_isCreate ? 'Create' : 'Save changes'),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}
