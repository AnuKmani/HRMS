import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../departments/presentation/departments_controller.dart';
import '../data/api_designations_repository.dart';
import '../domain/designation_repository.dart';

/// Create or edit a designation — [designationId] null means create.
///
/// The department is chosen from an endpoint-backed picker because the list
/// is neither short nor fixed; the picker's own failure is reported under
/// that field rather than blocking the whole form, since a designation with
/// no department is a legal thing to save.
class DesignationFormScreen extends ConsumerStatefulWidget {
  const DesignationFormScreen({super.key, this.designationId});

  final int? designationId;

  @override
  ConsumerState<DesignationFormScreen> createState() =>
      _DesignationFormScreenState();
}

class _DesignationFormScreenState extends ConsumerState<DesignationFormScreen> {
  late final TextEditingController _name;
  late final TextEditingController _code;
  late final TextEditingController _description;

  int? _departmentId;
  String? _departmentLabel;
  String _status = 'active';

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.designationId == null;

  DesignationsRepository get _repository =>
      ref.read(designationsRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _code = TextEditingController();
    _description = TextEditingController();

    if (!_isCreate) {
      _loading = true;
      _load();
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final designation = await _repository.find(widget.designationId!);

      if (!mounted) return;

      setState(() {
        _loading = false;
        _name.text = designation.name;
        _code.text = designation.code;
        _description.text = designation.description ?? '';
        _status = designation.status;
        _departmentId = designation.departmentId;
        _departmentLabel = designation.department?.name;
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

    final description = _description.text.trim();
    final body = <String, Object?>{
      'name': _name.text.trim(),
      'code': _code.text.trim(),
      'department_id': _departmentId,
      'description': description.isEmpty ? null : description,
      'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.designationId!, body);
      }

      if (!mounted) return;
      context.go('/designations');
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
      appBar: AppBar(
        title: Text(_isCreate ? 'New designation' : 'Edit designation'),
      ),
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
                    RemotePickerField(
                      key: const ValueKey('field-department'),
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
                    LabeledTextField(
                      label: 'Name',
                      controller: _name,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['name'],
                      hint: 'Site Supervisor',
                    ),
                    LabeledTextField(
                      label: 'Code',
                      controller: _code,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['code'],
                      hint: 'SS',
                      helper: 'Letters, numbers, dashes and underscores.',
                    ),
                    LabeledTextField(
                      label: 'Description',
                      controller: _description,
                      enabled: !_saving,
                      maxLines: 3,
                      errorText: _errors['description'],
                    ),
                    StatusField(
                      label: 'Status',
                      isRequired: true,
                      options: const [
                        StatusOption('active', 'Active'),
                        StatusOption('inactive', 'Inactive'),
                      ],
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
