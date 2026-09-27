import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/presentation/employees_controller.dart';
import '../data/api_projects_repository.dart';
import '../domain/projects_repository.dart';

const _projectStatuses = [
  StatusOption('planned', 'Planned'),
  StatusOption('active', 'Active'),
  StatusOption('on_hold', 'On hold'),
  StatusOption('completed', 'Completed'),
  StatusOption('cancelled', 'Cancelled'),
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

  ProjectsRepository get _repository =>
      ref.read(projectsRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _code = TextEditingController();
    _client = TextEditingController();
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
    if (_code.text.trim().isEmpty) local['code'] = 'This field is required.';

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
      'code': _code.text.trim(),
      'client': blank(_client.text),
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
        context.go('/projects');
      } else {
        context.go('/projects/${widget.projectId}');
      }
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _saving = false;
        _banner = failure.message;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() => _saving = false);
    }
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
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['code'],
                      hint: 'RT-01',
                      helper: 'Letters, numbers, dashes and underscores.',
                    ),
                    LabeledTextField(
                      label: 'Client',
                      controller: _client,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['client'],
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
                      onChanged:
                          _saving ? (_) {} : (v) => setState(() => _status = v),
                      errorText: _errors['status'],
                    ),
                    const SizedBox(height: 8),
                    FilledButton(
                      key: const ValueKey('form-save'),
                      onPressed: _saving ? null : _save,
                      child: _saving
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child:
                                  CircularProgressIndicator(strokeWidth: 2),
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
