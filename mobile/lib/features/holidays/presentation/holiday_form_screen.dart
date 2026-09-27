import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../sites/domain/site.dart';
import '../../sites/presentation/sites_controller.dart';
import '../data/api_holidays_repository.dart';
import '../domain/holiday.dart';
import '../domain/holidays_repository.dart';
import 'holidays_controller.dart';

/// Add or retire a holiday — [holidayId] null means create.
///
/// There is no delete button here because there is no delete route on the
/// server. Retiring is a status change, and the form says so in its own
/// helper text rather than leaving somebody to wonder where the remove
/// option went.
///
/// A date, a type and (for a site day) a site together name one holiday
/// exactly once. The server checks that with a real `whereNull` before it
/// writes — a `Rule::unique` would pass a second copy of a public holiday,
/// since `site_id IS NULL` is never equal to anything in SQL — and the
/// duplicate arrives here as a 422 with a message rather than a field
/// error, because the conflict is between the whole row and another row and
/// no single field is to blame.
class HolidayFormScreen extends ConsumerStatefulWidget {
  const HolidayFormScreen({super.key, this.holidayId});

  final int? holidayId;

  @override
  ConsumerState<HolidayFormScreen> createState() => _HolidayFormScreenState();
}

class _HolidayFormScreenState extends ConsumerState<HolidayFormScreen> {
  late final TextEditingController _name;
  late final TextEditingController _date;
  late final TextEditingController _description;

  String _type = Holiday.typePublic;
  String _status = Holiday.statusActive;
  int? _siteId;
  String? _siteName;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.holidayId == null;

  HolidaysRepository get _repository => ref.read(holidaysRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _date = TextEditingController();
    _description = TextEditingController();

    if (!_isCreate) _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _date.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final holiday = await _repository.find(widget.holidayId!);
      if (!mounted) return;

      setState(() {
        _name.text = holiday.name;
        _date.text = holiday.date;
        _description.text = holiday.description ?? '';
        _type = holiday.type;
        _status = holiday.status;
        _siteId = holiday.siteId;
        _siteName = holiday.siteName;
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

    // The site is sent only when the type asks for one. A public holiday
    // with a site left in the payload would fail server-side — correct, but
    // a confusing way to be told that changing the type also changed the
    // fields the type has.
    final body = <String, Object?>{
      'name': _name.text.trim(),
      'date': _date.text.trim(),
      'type': _type,
      'site_id': _type == Holiday.typeSite ? _siteId : null,
      'description': _description.text.trim(),
      if (!_isCreate) 'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.holidayId!, body);
      }

      ref.read(holidaysListProvider.notifier).reload();
      if (!mounted) return;
      context.go('/holidays');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        // An `abort(422, ...)` — a duplicate, or a date already claimed by
        // another scope — carries no field map. Pointing at nothing is the
        // honest answer; pointing at `name` would blame the wrong thing.
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
      appBar: AppBar(title: Text(_isCreate ? 'Add holiday' : 'Edit holiday')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('holiday-form-banner'),
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
                  LabeledTextField(
                    key: const ValueKey('holiday-name'),
                    label: 'Name',
                    controller: _name,
                    isRequired: true,
                    errorText: _errors['name'],
                  ),
                  DateField(
                    key: const ValueKey('holiday-date'),
                    label: 'Date',
                    controller: _date,
                    isRequired: true,
                    errorText: _errors['date'],
                  ),
                  StatusField(
                    key: const ValueKey('holiday-type'),
                    label: 'Scope',
                    isRequired: true,
                    value: _type,
                    options: const [
                      StatusOption(Holiday.typePublic, 'Public'),
                      StatusOption(Holiday.typeCompany, 'Company'),
                      StatusOption(Holiday.typeSite, 'Site'),
                    ],
                    helper:
                        'Public and company days apply everywhere; a site '
                        'day applies to that site only.',
                    errorText: _errors['type'],
                    onChanged: (value) => setState(() {
                      _type = value;
                      if (value != Holiday.typeSite) {
                        _siteId = null;
                        _siteName = null;
                      }
                    }),
                  ),
                  if (_type == Holiday.typeSite)
                    RemotePickerField<Site>(
                      key: const ValueKey('holiday-site'),
                      label: 'Site',
                      provider: sitesPickerProvider,
                      idOf: (site) => site.id,
                      labelOf: (site) => site.name,
                      value: _siteId,
                      selectedLabel: _siteName,
                      isRequired: true,
                      searchHint: 'Search sites',
                      errorText: _errors['site_id'],
                      onChanged: (id) => setState(() {
                        _siteId = id;
                        _siteName = null;
                      }),
                    ),
                  LabeledTextField(
                    key: const ValueKey('holiday-description'),
                    label: 'Description',
                    controller: _description,
                    maxLines: 3,
                    hint: 'Optional',
                    errorText: _errors['description'],
                  ),
                  if (!_isCreate)
                    StatusField(
                      key: const ValueKey('holiday-status'),
                      label: 'Status',
                      value: _status,
                      options: const [
                        StatusOption(Holiday.statusActive, 'Active'),
                        StatusOption(Holiday.statusInactive, 'Retired'),
                      ],
                      helper:
                          'Retiring keeps the day in the calendar and '
                          'stops it being counted as time off.',
                      errorText: _errors['status'],
                      onChanged: (value) => setState(() => _status = value),
                    ),
                  const SizedBox(height: 8),
                  FilledButton(
                    key: const ValueKey('save-holiday'),
                    onPressed: _saving || _forbidden ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(_isCreate ? 'Add to calendar' : 'Save changes'),
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
