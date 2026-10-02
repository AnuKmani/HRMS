import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/remote_picker.dart';
import '../data/api_training_repository.dart';
import '../domain/training_program.dart';
import '../domain/training_repository.dart';
import '../domain/training_type.dart';
import 'training_controller.dart';

/// Add a course to the catalogue, or correct one.
///
/// [programId] null means create.
///
/// Three things this form is careful about:
///
///  - **the type comes from the server.** The picker reads
///    `GET /training-types`, and the type a program is filed under is a row
///    rather than a name matched here — so an operator who renames a kind of
///    training changes what this form offers without a code change. Nothing
///    anywhere in this app knows that `SAFETY_INDUCTION` exists.
///
///  - **"never expires" is an empty field, not a zero.** A certificate with
///    `certificate_validity_days: 0` would lapse the instant it was issued,
///    which is a different claim from one that does not lapse at all — and
///    the two are one character apart in a payload a person reads. The
///    server's rule is `min:1`, so this form says "leave it empty" rather
///    than offering a zero.
///
///  - **the client's checks are a courtesy, not the rule.** The server
///    re-runs every one of them — the type must be active, the code must be
///    unique, the validity must be at least a day — and its 422 is what
///    actually decides.
class TrainingProgramFormScreen extends ConsumerStatefulWidget {
  const TrainingProgramFormScreen({super.key, this.programId});

  final int? programId;

  @override
  ConsumerState<TrainingProgramFormScreen> createState() =>
      _TrainingProgramFormScreenState();
}

class _TrainingProgramFormScreenState
    extends ConsumerState<TrainingProgramFormScreen> {
  late final TextEditingController _code;
  late final TextEditingController _name;
  late final TextEditingController _description;
  late final TextEditingController _provider;
  late final TextEditingController _duration;
  late final TextEditingController _validity;

  int? _typeId;
  String? _typeName;
  bool _certificateRequired = true;
  String _status = TrainingProgram.statusActive;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.programId == null;

  TrainingRepository get _repository => ref.read(trainingRepositoryProvider);

  @override
  void initState() {
    super.initState();

    _code = TextEditingController();
    _name = TextEditingController();
    _description = TextEditingController();
    _provider = TextEditingController();
    _duration = TextEditingController();
    _validity = TextEditingController();

    // Gated before the fetch for the same reason the detail screen is: a URL
    // typed by hand should be refused at the door rather than answered by a
    // request the API will only turn into a 403.
    final allowed = _isCreate
        ? ref.read(permissionScopeProvider).canCreatePrograms
        : ref.read(permissionScopeProvider).canUpdatePrograms;

    if (!allowed) return;

    if (!_isCreate) _load();
  }

  @override
  void dispose() {
    _code.dispose();
    _name.dispose();
    _description.dispose();
    _provider.dispose();
    _duration.dispose();
    _validity.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      // No `findProgram` call here, and not for brevity: the id arrived from
      // the catalogue screen, which holds that page already, and asking the
      // server for a row we were just handed would be a second request that
      // could answer *differently* — a course retired in the meantime would
      // come back as its own 404 instead of as the record on screen. So the
      // page the list already owns is read, and a miss there is the honest
      // "you are editing something that has moved".
      final page = ref.read(trainingProgramsProvider).items;
      final cached = page
          .where((row) => row.id == widget.programId)
          .firstOrNull;
      final program =
          cached ?? await _repository.findProgram(widget.programId!);

      if (!mounted) return;

      setState(() {
        _typeId = program.trainingTypeId;
        _typeName = program.typeName;
        _code.text = program.code;
        _name.text = program.name;
        _description.text = program.description ?? '';
        _provider.text = program.provider ?? '';
        _duration.text = program.durationDays?.toString() ?? '';
        _validity.text = program.certificateValidityDays?.toString() ?? '';
        _certificateRequired = program.certificateRequired;
        _status = program.status;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = _messageFor(failure);
      });
    }
  }

  TrainingType? _typeFromPicker(int? id) {
    if (id == null) return null;

    for (final type in ref.read(trainingTypesPickerProvider).items) {
      if (type.id == id) return type;
    }

    return null;
  }

  /// Drop one field's error the moment its value is touched.
  ///
  /// A *replacement* of the map, not a mutation of it, and it always calls
  /// `setState`: mutating `_errors` in place would not draw (the frame was
  /// already painted with the same instance), and a helper that only
  /// sometimes rebuilt would leave the error on screen for one field and
  /// clear it for the next. One method, one behaviour, seven call sites that
  /// cannot disagree.
  void _touch(String field) {
    setState(() => _errors = Map<String, String>.of(_errors)..remove(field));
  }

  Map<String, String> _validate() {
    final errors = <String, String>{};

    if (_typeId == null) errors['training_type_id'] = 'Choose a training type.';
    if (_code.text.trim().isEmpty) errors['code'] = 'Enter a course code.';
    if (_name.text.trim().isEmpty) errors['name'] = 'Enter a course name.';

    final duration = int.tryParse(_duration.text.trim());
    if (_duration.text.trim().isNotEmpty &&
        (duration == null || duration < 1)) {
      errors['duration_days'] = 'Enter how many days the course runs for.';
    }

    final validity = int.tryParse(_validity.text.trim());
    if (_certificateRequired &&
        _validity.text.trim().isNotEmpty &&
        (validity == null || validity < 1)) {
      errors['certificate_validity_days'] =
          'Enter how many days the certificate is valid for, or leave it '
          'empty if it never expires.';
    }

    return errors;
  }

  Future<void> _save() async {
    if (_saving) return;

    final errors = _validate();

    if (errors.isNotEmpty) {
      setState(() {
        _errors = errors;
        _banner = null;
      });
      return;
    }

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const <String, String>{};
    });

    final duration = _duration.text.trim();
    final validity = _validity.text.trim();

    final body = <String, Object?>{
      'training_type_id': _typeId,
      'code': _code.text.trim(),
      'name': _name.text.trim(),
      'description': _description.text.trim(),
      'provider': _provider.text.trim(),
      'duration_days': duration.isEmpty ? null : duration,
      'certificate_required': _certificateRequired,
      'certificate_validity_days': !_certificateRequired || validity.isEmpty
          ? null
          : validity,
      // Only on an edit: a new course is `active` because the API says so,
      // and offering a "status" on something that does not exist yet would
      // be inviting a person to choose the wrong default.
      if (!_isCreate) 'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.createProgram(body);
      } else {
        await _repository.updateProgram(widget.programId!, body);
      }

      ref.read(trainingProgramsProvider.notifier).reload();
      ref.read(trainingProgramsPickerProvider.notifier).reload();

      if (!mounted) return;
      context.go('/training/programs');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _banner = 'Something went wrong while saving. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);
    final theme = Theme.of(context);

    if (_isCreate && !scope.canCreatePrograms) {
      return Scaffold(
        appBar: AppBar(title: const Text('Add course')),
        body: const NoPermission(module: 'course creation'),
      );
    }

    if (!_isCreate && !scope.canUpdatePrograms) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit course')),
        body: const NoPermission(module: 'course editing'),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(_isCreate ? 'Add course' : 'Edit course')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('program-form-banner'),
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Material(
                        color: theme.colorScheme.errorContainer,
                        borderRadius: BorderRadius.circular(8),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Text(
                            _banner!,
                            style: TextStyle(
                              color: theme.colorScheme.onErrorContainer,
                            ),
                          ),
                        ),
                      ),
                    ),
                  RemotePickerField<TrainingType>(
                    key: const ValueKey('program-type'),
                    label: 'Training type',
                    provider: trainingTypesPickerProvider,
                    idOf: (type) => type.id,
                    labelOf: (type) => type.name,
                    value: _typeId,
                    selectedLabel: _typeName,
                    isRequired: true,
                    searchHint: 'Search training types',
                    helper:
                        'The kind of course this is — data, not a fixed list.',
                    errorText: _errors['training_type_id'],
                    onChanged: (id) {
                      _typeId = id;
                      _typeName = _typeFromPicker(id)?.name;
                      _touch('training_type_id');
                    },
                  ),
                  LabeledTextField(
                    key: const ValueKey('program-code'),
                    label: 'Code',
                    controller: _code,
                    isRequired: true,
                    hint: 'WAH-101',
                    helper: 'Printed on the certificate; one code, one course.',
                    errorText: _errors['code'],
                    onChanged: (_) => _touch('code'),
                  ),
                  LabeledTextField(
                    key: const ValueKey('program-name'),
                    label: 'Name',
                    controller: _name,
                    isRequired: true,
                    errorText: _errors['name'],
                    onChanged: (_) => _touch('name'),
                  ),
                  LabeledTextField(
                    key: const ValueKey('program-description'),
                    label: 'Description',
                    controller: _description,
                    maxLines: 3,
                  ),
                  LabeledTextField(
                    key: const ValueKey('program-provider'),
                    label: 'Provider',
                    controller: _provider,
                    hint: 'External training provider',
                    helper: 'Used when a cohort does not name its own trainer.',
                  ),
                  LabeledTextField(
                    key: const ValueKey('program-duration'),
                    label: 'Duration (days)',
                    controller: _duration,
                    keyboardType: TextInputType.number,
                    helper: 'Leave empty if duration is not tracked.',
                    errorText: _errors['duration_days'],
                  ),
                  SwitchListTile(
                    key: const ValueKey('program-certificate-required'),
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Issues a certificate'),
                    subtitle: const Text(
                      'People who pass come out of this with a card that has '
                      'its own expiry.',
                    ),
                    value: _certificateRequired,
                    onChanged: (value) {
                      _certificateRequired = value;
                      _touch('certificate_validity_days');
                    },
                  ),
                  if (_certificateRequired) ...[
                    const SizedBox(height: 8),
                    LabeledTextField(
                      key: const ValueKey('program-validity'),
                      label: 'Certificate valid for (days)',
                      controller: _validity,
                      keyboardType: TextInputType.number,
                      // The whole point of the field: `null` is a real answer
                      // and `0` is not an option this form will accept.
                      helper:
                          'Leave empty if the certificate never expires. '
                          'Zero is not a valid answer — that is a card that '
                          'lapses as it is issued.',
                      errorText: _errors['certificate_validity_days'],
                    ),
                  ],
                  if (!_isCreate) ...[
                    const SizedBox(height: 8),
                    Text('Status', style: theme.textTheme.labelLarge),
                    const SizedBox(height: 6),
                    InputDecorator(
                      decoration: const InputDecoration(
                        border: OutlineInputBorder(),
                        isDense: true,
                        contentPadding: EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 12,
                        ),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          key: const ValueKey('program-status'),
                          isExpanded: true,
                          value: _status,
                          items:
                              const [
                                    (
                                      TrainingProgram.statusActive,
                                      'Active — offered on forms',
                                    ),
                                    (
                                      TrainingProgram.statusRetired,
                                      'Retired — history stays readable',
                                    ),
                                  ]
                                  .map(
                                    (option) => DropdownMenuItem<String>(
                                      value: option.$1,
                                      child: Text(option.$2),
                                    ),
                                  )
                                  .toList(),
                          onChanged: (value) => setState(
                            () =>
                                _status = value ?? TrainingProgram.statusActive,
                          ),
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    key: const ValueKey('save-program'),
                    onPressed: _saving ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(_isCreate ? 'Add course' : 'Save changes'),
                  ),
                ],
              ),
            ),
    );
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}
