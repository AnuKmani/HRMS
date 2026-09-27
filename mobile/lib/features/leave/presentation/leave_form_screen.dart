import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../sites/domain/site.dart';
import '../../sites/presentation/sites_controller.dart';
import '../data/api_leave_repository.dart';
import '../domain/leave_repository.dart';
import '../domain/leave_type.dart';
import 'leave_controller.dart';

/// Apply for leave, or edit a draft — [leaveId] null means create.
///
/// Three things this form deliberately does *not* do:
///
///  - **count the days.** There is no days field. The number is derived from
///    the range, the weekends and the holiday calendar by the server, and
///    accepting a claim from the client would let one screen say three and
///    the reservation say five.
///
///  - **re-implement a rule.** Leave types, entitlements, caps and overlap
///    checks all live on the server; the errors come back as a
///    `{field: message}` map and are drawn under the field they belong to.
///
///  - **offer a status.** Status is the state machine's alone. A draft is
///    what `POST /leave` creates, always, and what `PUT /leave/{id}` may
///    still touch — submitting is its own action on the detail screen.
class LeaveFormScreen extends ConsumerStatefulWidget {
  const LeaveFormScreen({super.key, this.leaveId});

  final int? leaveId;

  @override
  ConsumerState<LeaveFormScreen> createState() => _LeaveFormScreenState();
}

class _LeaveFormScreenState extends ConsumerState<LeaveFormScreen> {
  late final TextEditingController _reason;
  late final TextEditingController _start;
  late final TextEditingController _end;

  int? _typeId;
  int? _siteId;
  String? _typeName;
  String? _siteName;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.leaveId == null;

  LeaveRepository get _repository => ref.read(leaveRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _reason = TextEditingController();
    _start = TextEditingController();
    _end = TextEditingController();

    if (!_isCreate) _load();
  }

  @override
  void dispose() {
    _reason.dispose();
    _start.dispose();
    _end.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final request = await _repository.find(widget.leaveId!);
      if (!mounted) return;

      setState(() {
        _typeId = request.leaveTypeId;
        _typeName = request.leaveTypeName;
        _siteId = request.siteId;
        _siteName = request.siteName;
        _start.text = request.startDate;
        _end.text = request.endDate;
        _reason.text = request.reason ?? '';
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _banner = _messageFor(failure);
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
      'leave_type_id': _typeId,
      'start_date': _start.text.trim(),
      'end_date': _end.text.trim(),
      'site_id': _siteId,
      'reason': _reason.text.trim(),
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.leaveId!, body);
      }

      ref.read(leaveListProvider.notifier).reload();
      if (!mounted) return;

      // `go` rather than `pop`: from the edit route the user wants to be back
      // on the record they just changed, and the detail screen reads it once
      // in `initState` — popping would land them on a screen still showing
      // the draft they just saved away.
      if (_isCreate) {
        context.go('/leave');
      } else {
        context.go('/leave/${widget.leaveId}');
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

  LeaveType? get _selectedType {
    if (_typeId == null) return null;

    for (final type in ref.watch(leaveTypesProvider).items) {
      if (type.id == _typeId) return type;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_isCreate ? 'Apply leave' : 'Edit draft')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('leave-form-banner'),
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
                  RemotePickerField<LeaveType>(
                    key: const ValueKey('leave-type'),
                    label: 'Leave type',
                    provider: leaveTypesProvider,
                    idOf: (type) => type.id,
                    labelOf: (type) => type.pickerLabel,
                    value: _typeId,
                    selectedLabel: _typeName,
                    isRequired: true,
                    searchHint: 'Search leave types',
                    sheetTitle: 'Leave type',
                    onChanged: (id) {
                      setState(() {
                        _typeId = id;
                        _typeName = null;
                        _errors = Map<String, String>.of(_errors)
                          ..remove('leave_type_id');
                      });
                    },
                    errorText: _errors['leave_type_id'],
                  ),
                  if (_selectedType != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Text(
                        _selectedType!.helpText,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  DateField(
                    key: const ValueKey('leave-start'),
                    label: 'From',
                    controller: _start,
                    isRequired: true,
                    errorText: _errors['start_date'],
                  ),
                  DateField(
                    key: const ValueKey('leave-end'),
                    label: 'To',
                    controller: _end,
                    isRequired: true,
                    errorText: _errors['end_date'],
                  ),
                  RemotePickerField<Site>(
                    key: const ValueKey('leave-site'),
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
                  LabeledTextField(
                    key: const ValueKey('leave-reason'),
                    label: 'Reason',
                    controller: _reason,
                    maxLines: 3,
                    hint: 'Optional — helps your approver decide',
                    errorText: _errors['reason'],
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    key: const ValueKey('save-leave'),
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

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}
