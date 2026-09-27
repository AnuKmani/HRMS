import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../data/api_departments_repository.dart';
import '../domain/department_repository.dart';

/// Create or edit a department — [departmentId] null means create.
///
/// Errors come back from the server in a `{field: message}` map and are drawn
/// under the field they belong to; nothing here re-implements a rule the API
/// already owns, it only repeats one. A 403 is shown in the banner rather
/// than under a field, because nothing the user typed caused it and pointing
/// at a field would suggest otherwise.
class DepartmentFormScreen extends ConsumerStatefulWidget {
  const DepartmentFormScreen({super.key, this.departmentId});

  final int? departmentId;

  @override
  ConsumerState<DepartmentFormScreen> createState() =>
      _DepartmentFormScreenState();
}

class _DepartmentFormScreenState extends ConsumerState<DepartmentFormScreen> {
  late final TextEditingController _name;
  late final TextEditingController _code;
  late final TextEditingController _description;

  String _status = 'active';

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.departmentId == null;

  DepartmentsRepository get _repository =>
      ref.read(departmentsRepositoryProvider);

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
      final department = await _repository.find(widget.departmentId!);

      if (!mounted) return;

      setState(() {
        _loading = false;
        _name.text = department.name;
        _code.text = department.code;
        _description.text = department.description ?? '';
        _status = department.status;
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
      'description': description.isEmpty ? null : description,
      'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.departmentId!, body);
      }

      if (!mounted) return;
      context.go('/departments');
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
        title: Text(_isCreate ? 'New department' : 'Edit department'),
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
                    LabeledTextField(
                      label: 'Name',
                      controller: _name,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['name'],
                      hint: 'Human Resources',
                    ),
                    LabeledTextField(
                      label: 'Code',
                      controller: _code,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['code'],
                      hint: 'HR',
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
                      onChanged: _saving ? (_) {} : (v) => setState(() => _status = v),
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
