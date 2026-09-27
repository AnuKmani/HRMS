import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../projects/domain/project.dart';
import '../../projects/presentation/projects_controller.dart';
import '../../sites/domain/site.dart';
import '../../sites/presentation/sites_controller.dart';
import '../data/api_overtime_repository.dart';
import '../domain/overtime_repository.dart';
import 'overtime_controller.dart';

/// Claim overtime, or edit a draft — [overtimeId] null means create.
///
/// What is *not* on this form is the point of it: no status, no approved
/// minutes, no payroll flag. A claim is a draft until it is submitted, the
/// approved figure belongs to the approver and nothing else, and whether it
/// reaches payroll is the server's decision after that — a form that
/// offered any of the three would be asking the person claiming the hours
/// to decide how they are paid.
class OvertimeFormScreen extends ConsumerStatefulWidget {
  const OvertimeFormScreen({super.key, this.overtimeId});

  final int? overtimeId;

  @override
  ConsumerState<OvertimeFormScreen> createState() => _OvertimeFormScreenState();
}

class _OvertimeFormScreenState extends ConsumerState<OvertimeFormScreen> {
  late final TextEditingController _date;
  late final TextEditingController _minutes;
  late final TextEditingController _reason;

  int? _siteId;
  int? _projectId;
  String? _siteName;
  String? _projectName;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.overtimeId == null;

  OvertimeRepository get _repository => ref.read(overtimeRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _date = TextEditingController();
    _minutes = TextEditingController();
    _reason = TextEditingController();

    if (!_isCreate) _load();
  }

  @override
  void dispose() {
    _date.dispose();
    _minutes.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final claim = await _repository.find(widget.overtimeId!);
      if (!mounted) return;

      setState(() {
        _date.text = claim.overtimeDate;
        _minutes.text = claim.requestedMinutes.toString();
        _reason.text = claim.reason;
        _siteId = claim.siteId;
        _siteName = claim.siteName;
        _projectId = claim.projectId;
        _projectName = claim.projectName;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _banner = failure is ApiException
            ? failure.message
            : 'Something went wrong. Please try again.';
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    if (_saving) return;

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const <String, String>{};
    });

    final body = <String, Object?>{
      'overtime_date': _date.text.trim(),
      'requested_minutes': int.tryParse(_minutes.text.trim()),
      'reason': _reason.text.trim(),
      'site_id': _siteId,
      'project_id': _projectId,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.overtimeId!, body);
      }

      ref.read(overtimeListProvider.notifier).reload();
      if (!mounted) return;

      if (_isCreate) {
        context.go('/overtime');
      } else {
        context.go('/overtime/${widget.overtimeId}');
      }
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _forbidden = failure.statusCode == 403;
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
    return Scaffold(
      appBar: AppBar(
        title: Text(_isCreate ? 'Claim overtime' : 'Edit overtime draft'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('overtime-form-banner'),
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Material(
                        color: Theme.of(context).colorScheme.errorContainer,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Text(
                            _banner!,
                            style: TextStyle(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onErrorContainer,
                            ),
                          ),
                        ),
                      ),
                    ),
                  DateField(
                    key: const ValueKey('overtime-date'),
                    label: 'Date worked',
                    controller: _date,
                    isRequired: true,
                    // Overtime is claimed for a day that already happened;
                    // the server enforces the same bound, and offering a
                    // future date here would only set up a refusal later.
                    lastDate: DateTime.now(),
                    errorText: _errors['overtime_date'],
                  ),
                  LabeledTextField(
                    key: const ValueKey('overtime-minutes'),
                    label: 'Minutes worked',
                    controller: _minutes,
                    isRequired: true,
                    keyboardType: TextInputType.number,
                    hint: 'e.g. 90',
                    errorText: _errors['requested_minutes'],
                  ),
                  LabeledTextField(
                    key: const ValueKey('overtime-reason'),
                    label: 'Reason',
                    controller: _reason,
                    isRequired: true,
                    maxLines: 3,
                    hint: 'Why the extra time was needed',
                    errorText: _errors['reason'],
                  ),
                  RemotePickerField<Site>(
                    key: const ValueKey('overtime-site'),
                    label: 'Site (optional)',
                    provider: sitesPickerProvider,
                    idOf: (site) => site.id,
                    labelOf: (site) => site.name,
                    value: _siteId,
                    selectedLabel: _siteName,
                    hint: 'No specific site',
                    searchHint: 'Search sites',
                    errorText: _errors['site_id'],
                    onChanged: (id) => setState(() {
                      _siteId = id;
                      _siteName = null;
                    }),
                  ),
                  RemotePickerField<Project>(
                    key: const ValueKey('overtime-project'),
                    label: 'Project (optional)',
                    provider: projectsPickerProvider,
                    idOf: (project) => project.id,
                    labelOf: (project) => project.name,
                    value: _projectId,
                    selectedLabel: _projectName,
                    hint: 'No specific project',
                    searchHint: 'Search projects',
                    errorText: _errors['project_id'],
                    onChanged: (id) => setState(() {
                      _projectId = id;
                      _projectName = null;
                    }),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    key: const ValueKey('save-overtime'),
                    onPressed: _saving || _forbidden ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(_isCreate ? 'Save as draft' : 'Save changes'),
                  ),
                ],
              ),
            ),
    );
  }
}
