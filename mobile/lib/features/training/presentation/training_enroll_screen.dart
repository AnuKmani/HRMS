import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/domain/employee.dart';
import '../../employees/presentation/employees_controller.dart';
import '../data/api_training_repository.dart';
import '../domain/training_program.dart';
import '../domain/training_repository.dart';
import 'training_controller.dart';

/// Put somebody on a course.
///
/// Behind `training.assign` and nothing else — there is no self-service half.
/// The API refuses a person enrolling themselves for the reason
/// `StoreEmployeeTrainingRequest` spells out: putting yourself on the course
/// that certifies you is exactly the act the brief rules out, and the form
/// does not offer a "me" option in the first place rather than relying on
/// the server to catch it.
///
/// Three things this form is careful about:
///
///  - **both the person and the course come from the server.** Neither list
///    is hard-coded: the course picker reads the catalogue an operator
///    maintains, and only the *active* half — a retired course is refused by
///    the API too, so this is the picker declining to offer a door that
///    would only come back as a 422.
///
///  - **the date is required, and it is the date the seat belongs to.** Two
///    people may sit the same course on different days, but the same person
///    may not hold a seat on the same course for the same day twice — the
///    API answers 409 naming that sentence, and this form shows it rather
///    than inventing its own duplicate check that would drift from the one
///    under the row lock.
///
///  - **the client's checks are a courtesy, not the rule.** The server
///    re-runs every one of them and its 422 is what actually decides.
class TrainingEnrollScreen extends ConsumerStatefulWidget {
  const TrainingEnrollScreen({super.key});

  @override
  ConsumerState<TrainingEnrollScreen> createState() =>
      _TrainingEnrollScreenState();
}

class _TrainingEnrollScreenState extends ConsumerState<TrainingEnrollScreen> {
  late final TextEditingController _enrolledOn;
  late final TextEditingController _trainingDate;
  late final TextEditingController _trainer;
  late final TextEditingController _remarks;

  int? _employeeId;
  String? _employeeName;
  int? _programId;
  String? _programName;

  bool _saving = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  TrainingRepository get _repository => ref.read(trainingRepositoryProvider);

  @override
  void initState() {
    super.initState();

    _enrolledOn = TextEditingController(text: _today());
    _trainingDate = TextEditingController();
    _trainer = TextEditingController();
    _remarks = TextEditingController();

    // Gated before the form is drawn rather than only before the request: a
    // URL typed by hand should be refused at the door rather than answered
    // by a screen whose button would only come back as a 403.
    if (!ref.read(permissionScopeProvider).canAssignTraining) return;
  }

  static String _today() {
    final now = DateTime.now();

    return '${now.year.toString().padLeft(4, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.day.toString().padLeft(2, '0')}';
  }

  @override
  void dispose() {
    _enrolledOn.dispose();
    _trainingDate.dispose();
    _trainer.dispose();
    _remarks.dispose();
    super.dispose();
  }

  /// Drop one field's error the moment its value is touched — a
  /// *replacement* of the map that also rebuilds, so the red line under the
  /// box goes away on the same frame the value does. See the identically
  /// named method on the program form for why it is never a bare mutation.
  void _touch(String field) {
    setState(() => _errors = Map<String, String>.of(_errors)..remove(field));
  }

  Map<String, String> _validate() {
    final errors = <String, String>{};

    if (_employeeId == null) errors['employee_id'] = 'Choose who this is for.';
    if (_programId == null) {
      errors['training_program_id'] = 'Choose a course.';
    }
    if (_enrolledOn.text.trim().isEmpty) {
      errors['enrollment_date'] = 'Choose the date of the seat.';
    }

    final enrolled = DateTime.tryParse(_enrolledOn.text.trim());
    final onCourse = DateTime.tryParse(_trainingDate.text.trim());

    // Date order only — the server owns the rest. Catching this one here is
    // so a person is told before a request that was always going to fail,
    // not instead of being refused.
    if (enrolled != null && onCourse != null && onCourse.isBefore(enrolled)) {
      errors['training_date'] =
          'The course cannot run before the seat was booked.';
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

    final trainingDate = _trainingDate.text.trim();
    final trainer = _trainer.text.trim();
    final remarks = _remarks.text.trim();

    try {
      await _repository.enroll(<String, Object?>{
        'employee_id': _employeeId,
        'training_program_id': _programId,
        'enrollment_date': _enrolledOn.text.trim(),
        if (trainingDate.isNotEmpty) 'training_date': trainingDate,
        if (trainer.isNotEmpty) 'trainer': trainer,
        if (remarks.isNotEmpty) 'remarks': remarks,
      });

      ref.read(trainingListProvider.notifier).reload();

      if (!mounted) return;
      context.go('/training');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _errors = failure.errors;
        // A 409 carries no field errors — it is one sentence about a state
        // the row is already in — so it belongs on the banner rather than
        // under a field it does not describe.
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
    if (!ref.watch(
      permissionScopeProvider.select((s) => s.canAssignTraining),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Enrol on a course')),
        body: const NoPermission(module: 'training enrolment'),
      );
    }

    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Enrol on a course')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_banner != null)
              Padding(
                key: const ValueKey('enrol-form-banner'),
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
            RemotePickerField<Employee>(
              key: const ValueKey('enrol-employee'),
              label: 'Who',
              provider: employeesPickerProvider,
              idOf: (employee) => employee.id,
              labelOf: (employee) => employee.fullName,
              value: _employeeId,
              selectedLabel: _employeeName,
              isRequired: true,
              searchHint: 'Search people',
              errorText: _errors['employee_id'],
              onChanged: (id) {
                _employeeId = id;
                _employeeName = null;
                _touch('employee_id');
              },
            ),
            RemotePickerField<TrainingProgram>(
              key: const ValueKey('enrol-program'),
              label: 'Course',
              provider: trainingProgramsPickerProvider,
              idOf: (program) => program.id,
              labelOf: (program) => '${program.name} (${program.code})',
              value: _programId,
              selectedLabel: _programName,
              isRequired: true,
              searchHint: 'Search courses',
              errorText: _errors['training_program_id'],
              onChanged: (id) {
                _programId = id;
                _programName = null;
                _touch('training_program_id');
              },
            ),
            DateField(
              key: const ValueKey('enrol-date'),
              label: 'Date of seat',
              controller: _enrolledOn,
              isRequired: true,
              errorText: _errors['enrollment_date'],
            ),
            DateField(
              key: const ValueKey('enrol-training-date'),
              label: 'Course runs on',
              controller: _trainingDate,
              allowEmpty: true,
              errorText: _errors['training_date'],
              helper: 'When the cohort actually sits it, if that is known.',
            ),
            LabeledTextField(
              key: const ValueKey('enrol-trainer'),
              label: 'Trainer',
              controller: _trainer,
              hint: 'Defaults to the course provider',
            ),
            LabeledTextField(
              key: const ValueKey('enrol-remarks'),
              label: 'Notes',
              controller: _remarks,
              maxLines: 3,
            ),
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('save-enrolment'),
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Enrol'),
            ),
          ],
        ),
      ),
    );
  }
}
