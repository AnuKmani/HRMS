import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/presentation/employees_controller.dart';
import '../../projects/presentation/projects_controller.dart';
import '../data/api_sites_repository.dart';
import '../domain/sites_repository.dart';

const _siteStatuses = [
  StatusOption('active', 'Active'),
  StatusOption('inactive', 'Inactive'),
];

/// Create or edit a site — [siteId] null means create.
///
/// Latitude, longitude and radius are validated together before the request
/// goes anywhere. That mirrors a rule the API also enforces (three fields or
/// none), and it is worth repeating here rather than only server-side: the
/// common case by far is a half-typed coordinate, and telling somebody their
/// *latitude* is wrong when what they have not done is enter a radius sends
/// them looking at the wrong field.
class SiteFormScreen extends ConsumerStatefulWidget {
  const SiteFormScreen({super.key, this.siteId});

  final int? siteId;

  @override
  ConsumerState<SiteFormScreen> createState() => _SiteFormScreenState();
}

class _SiteFormScreenState extends ConsumerState<SiteFormScreen> {
  late final TextEditingController _name;
  late final TextEditingController _code;
  late final TextEditingController _address;
  late final TextEditingController _latitude;
  late final TextEditingController _longitude;
  late final TextEditingController _radius;

  String _status = 'active';

  int? _projectId;
  String? _projectLabel;
  int? _siteManagerId;
  String? _siteManagerLabel;
  int? _siteSupervisorId;
  String? _siteSupervisorLabel;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  bool _forbidden = false;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.siteId == null;

  SitesRepository get _repository => ref.read(sitesRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _code = TextEditingController();
    _address = TextEditingController();
    _latitude = TextEditingController();
    _longitude = TextEditingController();
    _radius = TextEditingController();

    if (!_isCreate) {
      _loading = true;
      _load();
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _address.dispose();
    _latitude.dispose();
    _longitude.dispose();
    _radius.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final site = await _repository.find(widget.siteId!);

      if (!mounted) return;

      setState(() {
        _loading = false;
        _name.text = site.name;
        _code.text = site.code;
        _address.text = site.address ?? '';
        _latitude.text = site.latitude?.toStringAsFixed(7) ?? '';
        _longitude.text = site.longitude?.toStringAsFixed(7) ?? '';
        _radius.text = site.geofenceRadius?.toStringAsFixed(2) ?? '';
        _status = site.status ?? 'active';
        _projectId = site.projectId;
        _projectLabel = site.projectName;
        _siteManagerId = site.siteManagerId;
        _siteManagerLabel = site.siteManagerName;
        _siteSupervisorId = site.siteSupervisorId;
        _siteSupervisorLabel = site.siteSupervisorName;
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

    final lat = _latitude.text.trim();
    final lng = _longitude.text.trim();
    final radius = _radius.text.trim();
    final geofenceCount = [lat, lng, radius].where((v) => v.isNotEmpty).length;

    final local = <String, String>{};
    if (_name.text.trim().isEmpty) local['name'] = 'This field is required.';
    if (_code.text.trim().isEmpty) local['code'] = 'This field is required.';
    if (_projectId == null) {
      local['project_id'] = 'A site belongs to a project.';
    }

    if (geofenceCount != 0 && geofenceCount != 3) {
      local['geofence_radius'] = 'Latitude, longitude and radius must be given together, or not at all.';
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
      'code': _code.text.trim(),
      'address': blank(_address.text),
      'project_id': _projectId,
      'site_manager_id': _siteManagerId,
      'site_supervisor_id': _siteSupervisorId,
      'latitude': blank(lat) == null ? null : double.tryParse(lat),
      'longitude': blank(lng) == null ? null : double.tryParse(lng),
      'geofence_radius': blank(radius) == null ? null : double.tryParse(radius),
      'status': _status,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.siteId!, body);
      }

      if (!mounted) return;

      if (_isCreate) {
        context.go('/sites');
      } else {
        context.go('/sites/${widget.siteId}');
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
      appBar: AppBar(title: Text(_isCreate ? 'New site' : 'Edit site')),
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
                      hint: 'Block C, Level 3',
                    ),
                    LabeledTextField(
                      label: 'Code',
                      controller: _code,
                      isRequired: true,
                      enabled: !_saving,
                      textInputAction: TextInputAction.next,
                      errorText: _errors['code'],
                      hint: 'SITE-04',
                      helper: 'Letters, numbers, dashes and underscores.',
                    ),
                    RemotePickerField(
                      label: 'Project',
                      provider: projectsPickerProvider,
                      idOf: (project) => project.id,
                      labelOf: (project) => project.name,
                      value: _projectId,
                      selectedLabel: _projectLabel,
                      searchHint: 'Search projects',
                      sheetTitle: 'Project',
                      isRequired: true,
                      errorText: _errors['project_id'],
                      onChanged: (id) => setState(() {
                        _projectId = id;
                        if (id == null) _projectLabel = null;
                      }),
                    ),
                    LabeledTextField(
                      label: 'Address',
                      controller: _address,
                      enabled: !_saving,
                      maxLines: 3,
                      errorText: _errors['address'],
                    ),
                    LabeledTextField(
                      label: 'Latitude',
                      controller: _latitude,
                      enabled: !_saving,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                        signed: true,
                      ),
                      errorText: _errors['latitude'] ?? _errors['coordinate'],
                      hint: '12.9715995',
                      helper: 'Between -90 and 90.',
                    ),
                    LabeledTextField(
                      label: 'Longitude',
                      controller: _longitude,
                      enabled: !_saving,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                        signed: true,
                      ),
                      errorText: _errors['longitude'],
                      hint: '77.5945667',
                      helper: 'Between -180 and 180.',
                    ),
                    LabeledTextField(
                      label: 'Geofence radius (m)',
                      controller: _radius,
                      enabled: !_saving,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      errorText: _errors['geofence_radius'],
                      helper:
                          'Latitude, longitude and radius go together or not '
                          'at all.',
                    ),
                    RemotePickerField(
                      label: 'Site manager',
                      provider: employeesPickerProvider,
                      idOf: (employee) => employee.id,
                      labelOf: (employee) => employee.fullName.isEmpty
                          ? employee.employeeCode
                          : employee.fullName,
                      value: _siteManagerId,
                      selectedLabel: _siteManagerLabel,
                      searchHint: 'Search employees',
                      sheetTitle: 'Site manager',
                      errorText: _errors['site_manager_id'],
                      onChanged: (id) => setState(() {
                        _siteManagerId = id;
                        if (id == null) _siteManagerLabel = null;
                      }),
                    ),
                    RemotePickerField(
                      label: 'Site supervisor',
                      provider: employeesPickerProvider,
                      idOf: (employee) => employee.id,
                      labelOf: (employee) => employee.fullName.isEmpty
                          ? employee.employeeCode
                          : employee.fullName,
                      value: _siteSupervisorId,
                      selectedLabel: _siteSupervisorLabel,
                      searchHint: 'Search employees',
                      sheetTitle: 'Site supervisor',
                      errorText: _errors['site_supervisor_id'],
                      onChanged: (id) => setState(() {
                        _siteSupervisorId = id;
                        if (id == null) _siteSupervisorLabel = null;
                      }),
                    ),
                    StatusField(
                      label: 'Status',
                      isRequired: true,
                      options: _siteStatuses,
                      value: _status,
                      onChanged: _saving
                          ? (_) {}
                          : (v) => setState(() => _status = v),
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
