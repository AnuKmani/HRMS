import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../departments/presentation/departments_controller.dart';
import '../../designations/presentation/designations_controller.dart';
import '../../projects/presentation/projects_controller.dart';
import '../../sites/presentation/sites_controller.dart';
import '../data/api_employees_repository.dart';
import '../domain/employees_repository.dart';
import 'employees_controller.dart';

const _employmentTypes = [
  StatusOption('permanent', 'Permanent'),
  StatusOption('contract', 'Contract'),
  StatusOption('probation', 'Probation'),
  StatusOption('internship', 'Internship'),
  StatusOption('part_time', 'Part time'),
];

const _employmentStatuses = [
  StatusOption('active', 'Active'),
  StatusOption('inactive', 'Inactive'),
  StatusOption('on_leave', 'On leave'),
  StatusOption('resigned', 'Resigned'),
  StatusOption('terminated', 'Terminated'),
];

/// Create or edit an employee — [employeeId] null means create.
///
/// Salary is rendered **only** when the session holds
/// `employees.salary.view`, and the key is then also the only time it is
/// put in the request body. Sending `salary: null` from a role without that
/// permission is a 422 by design (see `ValidatesEmployeeRelations`), so a
/// form that always included the field would make an unrelated edit
/// unsavable for everyone who may not see payroll in the first place.
///
/// Profile photo is absent on purpose: `photo_path` is not an accepted
/// request field on the server, and inventing a control for it here would
/// build an input the API would ignore.
class EmployeeFormScreen extends ConsumerStatefulWidget {
  const EmployeeFormScreen({super.key, this.employeeId});

  final int? employeeId;

  @override
  ConsumerState<EmployeeFormScreen> createState() => _EmployeeFormScreenState();
}

class _EmployeeFormScreenState extends ConsumerState<EmployeeFormScreen> {
  late final TextEditingController _code;
  late final TextEditingController _firstName;
  late final TextEditingController _middleName;
  late final TextEditingController _lastName;
  late final TextEditingController _email;
  late final TextEditingController _phone;
  late final TextEditingController _joiningDate;
  late final TextEditingController _dateOfBirth;
  late final TextEditingController _nationality;
  late final TextEditingController _address;
  late final TextEditingController _emergencyName;
  late final TextEditingController _emergencyPhone;
  late final TextEditingController _emergencyRelation;
  late final TextEditingController _salary;

  String _employmentType = 'permanent';
  String _employmentStatus = 'active';

  int? _departmentId;
  String? _departmentLabel;
  int? _designationId;
  String? _designationLabel;
  int? _reportingManagerId;
  String? _reportingManagerLabel;
  int? _projectId;
  String? _projectLabel;
  int? _siteId;
  String? _siteLabel;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.employeeId == null;

  EmployeesRepository get _repository => ref.read(employeesRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _code = TextEditingController();
    _firstName = TextEditingController();
    _middleName = TextEditingController();
    _lastName = TextEditingController();
    _email = TextEditingController();
    _phone = TextEditingController();
    _joiningDate = TextEditingController();
    _dateOfBirth = TextEditingController();
    _nationality = TextEditingController();
    _address = TextEditingController();
    _emergencyName = TextEditingController();
    _emergencyPhone = TextEditingController();
    _emergencyRelation = TextEditingController();
    _salary = TextEditingController();

    if (!_isCreate) {
      _loading = true;
      _load();
    }
  }

  @override
  void dispose() {
    _code.dispose();
    _firstName.dispose();
    _middleName.dispose();
    _lastName.dispose();
    _email.dispose();
    _phone.dispose();
    _joiningDate.dispose();
    _dateOfBirth.dispose();
    _nationality.dispose();
    _address.dispose();
    _emergencyName.dispose();
    _emergencyPhone.dispose();
    _emergencyRelation.dispose();
    _salary.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final employee = await _repository.find(widget.employeeId!);

      if (!mounted) return;

      setState(() {
        _loading = false;
        _code.text = employee.employeeCode;
        _firstName.text = employee.firstName;
        _middleName.text = employee.middleName ?? '';
        _lastName.text = employee.lastName;
        _email.text = employee.email ?? '';
        _phone.text = employee.phone ?? '';
        _joiningDate.text = employee.joiningDate ?? '';
        _dateOfBirth.text = employee.dateOfBirth ?? '';
        _nationality.text = employee.nationality ?? '';
        _address.text = employee.address ?? '';
        _emergencyName.text = employee.emergencyContactName ?? '';
        _emergencyPhone.text = employee.emergencyContactPhone ?? '';
        _emergencyRelation.text = employee.emergencyContactRelation ?? '';
        _salary.text = employee.salary == null
            ? ''
            : employee.salary!.toStringAsFixed(2);
        _employmentType = employee.employmentType;
        _employmentStatus = employee.employmentStatus;

        _departmentId = employee.departmentId;
        _departmentLabel = employee.departmentName;
        _designationId = employee.designationId;
        _designationLabel = employee.designationName;
        _reportingManagerId = employee.reportingManagerId;
        _reportingManagerLabel = employee.reportingManagerName;
        _projectId = employee.primaryProjectId;
        _projectLabel = employee.primaryProjectName;
        _siteId = employee.primarySiteId;
        _siteLabel = employee.primarySiteName;
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

  Future<void> _save(PermissionScope scope) async {
    FocusScope.of(context).unfocus();

    setState(() {
      _banner = null;
      _forbidden = false;
      _errors = const <String, String>{};
    });

    final local = <String, String>{};
    void require(String field, String value, String label) {
      if (value.trim().isEmpty) local[field] = '$label is required.';
    }

    require('employee_code', _code.text, 'Employee code');
    require('first_name', _firstName.text, 'First name');
    require('last_name', _lastName.text, 'Last name');
    require('email', _email.text, 'Email');
    require('joining_date', _joiningDate.text, 'Joining date');

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
      'employee_code': _code.text.trim(),
      'first_name': _firstName.text.trim(),
      'middle_name': blank(_middleName.text),
      'last_name': _lastName.text.trim(),
      'email': _email.text.trim(),
      'phone': blank(_phone.text),
      'date_of_birth': blank(_dateOfBirth.text),
      'nationality': blank(_nationality.text),
      'address': blank(_address.text),
      'emergency_contact_name': blank(_emergencyName.text),
      'emergency_contact_phone': blank(_emergencyPhone.text),
      'emergency_contact_relation': blank(_emergencyRelation.text),
      'joining_date': _joiningDate.text.trim(),
      'department_id': _departmentId,
      'designation_id': _designationId,
      'reporting_manager_id': _reportingManagerId,
      'primary_project_id': _projectId,
      'primary_site_id': _siteId,
      'employment_type': _employmentType,
      'employment_status': _employmentStatus,
    };

    if (scope.canViewSalary) {
      body['salary'] = double.tryParse(_salary.text.trim());
    }

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.employeeId!, body);
      }

      if (!mounted) return;

      if (_isCreate) {
        context.go('/employees');
      } else {
        context.go('/employees/${widget.employeeId}');
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
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: Text(_isCreate ? 'New employee' : 'Edit employee')),
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
                    _sectionTitle('Identity'),
                    LabeledTextField(
                      label: 'Employee code',
                      controller: _code,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['employee_code'],
                      hint: 'EMP-1001',
                      helper: 'Letters, numbers, dashes and underscores.',
                    ),
                    LabeledTextField(
                      label: 'First name',
                      controller: _firstName,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['first_name'],
                    ),
                    LabeledTextField(
                      label: 'Middle name',
                      controller: _middleName,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['middle_name'],
                    ),
                    LabeledTextField(
                      label: 'Last name',
                      controller: _lastName,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['last_name'],
                    ),
                    LabeledTextField(
                      label: 'Email',
                      controller: _email,
                      isRequired: true,
                      enabled: !_saving,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['email'],
                    ),
                    LabeledTextField(
                      label: 'Phone',
                      controller: _phone,
                      enabled: !_saving,
                      keyboardType: TextInputType.phone,
                      errorText: _errors['phone'],
                    ),
                    _sectionTitle('Employment'),
                    DateField(
                      label: 'Joining date',
                      controller: _joiningDate,
                      isRequired: true,
                      errorText: _errors['joining_date'],
                    ),
                    StatusField(
                      label: 'Employment type',
                      isRequired: true,
                      options: _employmentTypes,
                      value: _employmentType,
                      onChanged: _saving
                          ? (_) {}
                          : (v) => setState(() => _employmentType = v),
                      errorText: _errors['employment_type'],
                    ),
                    StatusField(
                      label: 'Employment status',
                      isRequired: true,
                      options: _employmentStatuses,
                      value: _employmentStatus,
                      onChanged: _saving
                          ? (_) {}
                          : (v) => setState(() => _employmentStatus = v),
                      errorText: _errors['employment_status'],
                    ),
                    _sectionTitle('Reports to'),
                    RemotePickerField(
                      label: 'Reporting manager',
                      provider: employeesPickerProvider,
                      idOf: (employee) => employee.id,
                      labelOf: (employee) => employee.fullName.isEmpty
                          ? employee.employeeCode
                          : employee.fullName,
                      value: _reportingManagerId,
                      selectedLabel: _reportingManagerLabel,
                      searchHint: 'Search employees',
                      sheetTitle: 'Reporting manager',
                      errorText: _errors['reporting_manager_id'],
                      onChanged: (id) => setState(() {
                        _reportingManagerId = id;
                        if (id == null) _reportingManagerLabel = null;
                      }),
                    ),
                    _sectionTitle('Organisation'),
                    RemotePickerField(
                      label: 'Department',
                      provider: departmentsPickerProvider,
                      idOf: (department) => department.id,
                      labelOf: (department) => department.name,
                      value: _departmentId,
                      selectedLabel: _departmentLabel,
                      searchHint: 'Search departments',
                      sheetTitle: 'Department',
                      errorText: _errors['department_id'],
                      onChanged: (id) => setState(() {
                        _departmentId = id;
                        if (id == null) _departmentLabel = null;
                      }),
                    ),
                    RemotePickerField(
                      label: 'Designation',
                      provider: designationsPickerProvider,
                      idOf: (designation) => designation.id,
                      labelOf: (designation) => designation.name,
                      value: _designationId,
                      selectedLabel: _designationLabel,
                      searchHint: 'Search designations',
                      sheetTitle: 'Designation',
                      errorText: _errors['designation_id'],
                      onChanged: (id) => setState(() {
                        _designationId = id;
                        if (id == null) _designationLabel = null;
                      }),
                    ),
                    RemotePickerField(
                      label: 'Primary project',
                      provider: projectsPickerProvider,
                      idOf: (project) => project.id,
                      labelOf: (project) => project.name,
                      value: _projectId,
                      selectedLabel: _projectLabel,
                      searchHint: 'Search projects',
                      sheetTitle: 'Primary project',
                      errorText: _errors['primary_project_id'],
                      onChanged: (id) => setState(() {
                        _projectId = id;
                        if (id == null) _projectLabel = null;
                      }),
                    ),
                    RemotePickerField(
                      label: 'Primary site',
                      provider: sitesPickerProvider,
                      idOf: (site) => site.id,
                      labelOf: (site) => site.name,
                      value: _siteId,
                      selectedLabel: _siteLabel,
                      searchHint: 'Search sites',
                      sheetTitle: 'Primary site',
                      errorText: _errors['primary_site_id'],
                      onChanged: (id) => setState(() {
                        _siteId = id;
                        if (id == null) _siteLabel = null;
                      }),
                    ),
                    _sectionTitle('Personal'),
                    DateField(
                      label: 'Date of birth',
                      controller: _dateOfBirth,
                      allowEmpty: true,
                      errorText: _errors['date_of_birth'],
                      lastDate: DateTime.now(),
                    ),
                    LabeledTextField(
                      label: 'Nationality',
                      controller: _nationality,
                      enabled: !_saving,
                      errorText: _errors['nationality'],
                    ),
                    LabeledTextField(
                      label: 'Address',
                      controller: _address,
                      enabled: !_saving,
                      maxLines: 3,
                      errorText: _errors['address'],
                    ),
                    _sectionTitle('Emergency contact'),
                    LabeledTextField(
                      label: 'Contact name',
                      controller: _emergencyName,
                      enabled: !_saving,
                      errorText: _errors['emergency_contact_name'],
                    ),
                    LabeledTextField(
                      label: 'Contact phone',
                      controller: _emergencyPhone,
                      enabled: !_saving,
                      keyboardType: TextInputType.phone,
                      errorText: _errors['emergency_contact_phone'],
                    ),
                    LabeledTextField(
                      label: 'Relationship',
                      controller: _emergencyRelation,
                      enabled: !_saving,
                      errorText: _errors['emergency_contact_relation'],
                    ),
                    if (scope.canViewSalary) ...[
                      _sectionTitle('Compensation'),
                      LabeledTextField(
                        label: 'Salary',
                        controller: _salary,
                        enabled: !_saving,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        errorText: _errors['salary'],
                        helper: 'Visible only to roles allowed to see payroll.',
                      ),
                    ],
                    const SizedBox(height: 8),
                    FilledButton(
                      key: const ValueKey('form-save'),
                      onPressed: _saving ? null : () => _save(scope),
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

  Widget _sectionTitle(String title) => Padding(
    padding: const EdgeInsets.only(top: 8, bottom: 12),
    child: Text(
      title,
      key: ValueKey('section-$title'),
      style: Theme.of(context).textTheme.titleSmall,
    ),
  );
}
