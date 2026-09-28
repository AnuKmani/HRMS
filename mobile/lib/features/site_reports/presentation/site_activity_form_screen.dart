import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../attendance/domain/location_fix.dart';
import '../../sites/domain/site.dart';
import '../data/api_site_activity_repository.dart';
import '../data/report_draft_store.dart';
import '../domain/site_activity_report.dart';
import '../domain/site_activity_repository.dart';
import '../domain/site_report_photo.dart';
import 'report_gps_field.dart';
import 'report_photos_section.dart';
import 'site_report_photo_sheet.dart';
import 'site_reports_controller.dart';

/// Files or corrects a site activity report.
///
/// The shape of this screen is set by two decisions made elsewhere and
/// obeyed here rather than re-litigated:
///
///  - **the author is never a field.** The server reads `employee_id` from
///    the bearer token, so this form does not have one to send — and does
///    not have one to get wrong. What it *does* ask for is the site, from
///    `reportable-sites`, which is the only site list a field worker holds
///    the permission to read.
///  - **draft and submit are the same screen with one extra step.** A
///    report is a draft until it is submitted, and a person standing at a
///    work front should not have to learn where "save" ends and "file" is
///    kept. What separates them is the location reading: optional while
///    drafting, mandatory to submit, and carried in the submit payload
///    itself rather than assumed from whatever was saved earlier — because
///    the whole point of the reading is that it was taken *now*, not an
///    hour ago in a different place.
///
/// Photographs are staged on this phone until the report exists, then
/// uploaded as one batch. They are never sent with the create body: a
/// correction would otherwise re-upload every frame, and on a site with
/// one bar of signal that is the difference between a report and a
/// report nobody finishes.
class SiteActivityFormScreen extends ConsumerStatefulWidget {
  const SiteActivityFormScreen({super.key, this.reportId});

  /// Null to file a new report; otherwise the draft to correct.
  final int? reportId;

  @override
  ConsumerState<SiteActivityFormScreen> createState() =>
      _SiteActivityFormScreenState();
}

class _SiteActivityFormScreenState
    extends ConsumerState<SiteActivityFormScreen> {
  late final TextEditingController _date;
  late final TextEditingController _workCategory;
  late final TextEditingController _workPerformed;
  late final TextEditingController _manpower;
  late final TextEditingController _materials;
  late final TextEditingController _equipment;
  late final TextEditingController _issues;
  late final TextEditingController _safety;
  late final TextEditingController _remarks;

  int? _siteId;
  String? _siteName;

  /// Derived from the site, never chosen independently. Both request
  /// classes require `project_id` *and* check it against the site's own
  /// project, so a form that let a person pick the two separately would be
  /// offering a combination the server refuses — and would be offering it
  /// as a decision, when the site already contains the answer.
  int? _projectId;

  double _progress = 0;

  LocationFix? _fix;

  final List<SiteReportPhoto> _attached = <SiteReportPhoto>[];
  final List<Uint8List> _pending = <Uint8List>[];

  /// Set to the server's id the first time a *create* succeeds, so a photo
  /// upload that failed afterwards does not turn a retry into a second
  /// report. [_reportId] is the only thing consulted after that point —
  /// "is this new?" stops being a property of the widget the moment the
  /// server has answered.
  int? _savedId;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;
  bool _draftRestored = false;
  bool _submitting = false;

  String? _banner;
  String? _photoError;
  Map<String, String> _errors = const <String, String>{};

  Timer? _draftTimer;

  bool get _categoryIsPresentable {
    final text = _workCategory.text.trim();

    return text.isNotEmpty && text.length <= 60;
  }

  int? get _reportId => widget.reportId ?? _savedId;

  SiteActivityRepository get _repository =>
      ref.read(siteActivityRepositoryProvider);

  PermissionScope get _scope => ref.read(permissionScopeProvider);

  @override
  void initState() {
    super.initState();

    _date = TextEditingController();
    _workCategory = TextEditingController();
    _workPerformed = TextEditingController();
    _manpower = TextEditingController();
    _materials = TextEditingController();
    _equipment = TextEditingController();
    _issues = TextEditingController();
    _safety = TextEditingController();
    _remarks = TextEditingController();

    if (widget.reportId != null) {
      // The gate is applied here as well as in `build`. A screen whose
      // editor is not allowed to be opened should not ask the server for
      // the row first and decline to draw it a moment later — the fetch is
      // the same information leak the gate exists to prevent, just with a
      // request attached to it.
      if (_scope.canUpdateSiteActivityReports) _load();
    } else {
      _restoreDraft();
    }
  }

  @override
  void dispose() {
    _draftTimer?.cancel();
    _date.dispose();
    _workCategory.dispose();
    _workPerformed.dispose();
    _manpower.dispose();
    _materials.dispose();
    _equipment.dispose();
    _issues.dispose();
    _safety.dispose();
    _remarks.dispose();
    super.dispose();
  }

  /* ------------------------------------------------------------- loading */

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final report = await _repository.find(widget.reportId!);
      if (!mounted) return;

      setState(() {
        _date.text = report.reportDate;
        _workCategory.text = report.workCategory;
        _workPerformed.text = report.workPerformed;
        _manpower.text = report.manpower ?? '';
        _materials.text = report.materialsUsed ?? '';
        _equipment.text = report.equipmentUsed ?? '';
        _issues.text = report.issues ?? '';
        _safety.text = report.safetyIssues ?? '';
        _remarks.text = report.remarks ?? '';
        _siteId = report.siteId;
        _siteName = report.siteName;
        _projectId = report.projectId;
        _progress = report.progressPercentage.toDouble();
        _attached
          ..clear()
          ..addAll(report.photos);
        if (report.hasUsableGps) {
          _fix = LocationFix(
            latitude: report.latitude!,
            longitude: report.longitude!,
            accuracy: report.gpsAccuracy!,
          );
        }
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _banner = failure is ApiException
            ? failure.message
            : 'Something went wrong while loading this report.';
        _loading = false;
      });
    }
  }

  /* --------------------------------------------------------------- draft */

  Map<String, Object?> _snapshot() => <String, Object?>{
    'site_id': _siteId,
    'site_name': _siteName,
    'project_id': _projectId,
    'report_date': _date.text,
    'work_category': _workCategory.text,
    'work_performed': _workPerformed.text,
    'progress': _progress,
    'manpower': _manpower.text,
    'materials_used': _materials.text,
    'equipment_used': _equipment.text,
    'issues': _issues.text,
    'safety_issues': _safety.text,
    'remarks': _remarks.text,
    // Photographs are deliberately *not* in the snapshot: they are the
    // one part of a report that cannot be described as text, and a local
    // copy of a JPEG that has already been discarded from the form would
    // come back as evidence of work nobody photographed.
  };

  void _applyDraft(Map<String, Object?> draft) {
    // A stored document with nothing in it is not a draft worth an
    // announcement — it is what a save fired between two keystrokes leaves
    // behind, and telling somebody "your unfinished report was restored"
    // for an empty form trains them to ignore the sentence when it matters.
    final hasContent =
        (draft['site_id'] is int) ||
        (draft['report_date'] as String? ?? '').isNotEmpty ||
        (draft['work_category'] as String? ?? '').isNotEmpty ||
        (draft['work_performed'] as String? ?? '').isNotEmpty ||
        ((draft['progress'] as num?) ?? 0) > 0;

    if (!hasContent) return;

    setState(() {
      _siteId = draft['site_id'] is int ? draft['site_id'] as int : null;
      _siteName = draft['site_name'] as String?;
      _projectId = draft['project_id'] is int
          ? draft['project_id'] as int
          : null;
      _date.text = draft['report_date'] as String? ?? '';
      _workCategory.text = draft['work_category'] as String? ?? '';
      _workPerformed.text = draft['work_performed'] as String? ?? '';
      _progress =
          (draft['progress'] as num?)?.toDouble().clamp(0, 100).toDouble() ?? 0;
      _manpower.text = draft['manpower'] as String? ?? '';
      _materials.text = draft['materials_used'] as String? ?? '';
      _equipment.text = draft['equipment_used'] as String? ?? '';
      _issues.text = draft['issues'] as String? ?? '';
      _safety.text = draft['safety_issues'] as String? ?? '';
      _remarks.text = draft['remarks'] as String? ?? '';
      _draftRestored = true;
    });
  }

  Future<void> _restoreDraft() async {
    final draft = await ref
        .read(reportDraftStoreProvider)
        .read(activityDraftSlot);

    if (draft == null || !mounted || widget.reportId != null) return;

    _applyDraft(draft);
  }

  /// Debounced, and fired after every edit rather than on a save button —
  /// a draft that only exists until the app is swiped away has not
  /// actually saved anything.
  void _scheduleDraftSave() {
    if (widget.reportId != null) return;

    _draftTimer?.cancel();
    _draftTimer = Timer(SharedPreferencesReportDraftStore.debounce, () {
      _draftTimer = null;
      unawaited(
        ref
            .read(reportDraftStoreProvider)
            .write(activityDraftSlot, _snapshot()),
      );
    });
  }

  Future<void> _forgetDraft() async {
    _draftTimer?.cancel();
    _draftTimer = null;
    await ref.read(reportDraftStoreProvider).clear(activityDraftSlot);
  }

  Future<void> _startOver() async {
    await _forgetDraft();
    if (!mounted) return;

    setState(() {
      _siteId = null;
      _siteName = null;
      _projectId = null;
      _date.clear();
      _workCategory.clear();
      _workPerformed.clear();
      _manpower.clear();
      _materials.clear();
      _equipment.clear();
      _issues.clear();
      _safety.clear();
      _remarks.clear();
      _progress = 0;
      _fix = null;
      _pending.clear();
      _draftRestored = false;
      _banner = null;
      _errors = const <String, String>{};
    });
  }

  /* -------------------------------------------------------------- photos */

  Future<void> _addPhoto() async {
    final bytes = await SiteReportPhotoSheet.show(context);
    if (bytes == null || !mounted) return;

    setState(() {
      _pending.add(bytes);
      _photoError = null;
    });

    _scheduleDraftSave();
  }

  void _removePending(int index) {
    setState(() {
      if (index >= 0 && index < _pending.length) _pending.removeAt(index);
    });

    _scheduleDraftSave();
  }

  Future<void> _removeAttached(SiteReportPhoto photo) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Remove this photograph?'),
        content: const Text(
          'It is taken off the report straight away and cannot be put '
          'back from here.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Remove'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final id = _reportId;
    if (id == null) return;

    setState(() => _saving = true);

    try {
      await _repository.removePhoto(id, photo.id);
      if (!mounted) return;

      setState(() {
        _attached.removeWhere((candidate) => candidate.id == photo.id);
        _saving = false;
        _photoError = null;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _photoError = failure is ApiException
            ? failure.message
            : 'That photograph could not be removed. Please try again.';
      });
    }
  }

  Future<bool> _uploadPending(int reportId) async {
    if (_pending.isEmpty) return true;

    final batch = List<Uint8List>.of(_pending);

    try {
      final uploaded = await _repository.addPhotos(reportId, batch);

      if (!mounted) return false;

      setState(() {
        _pending.removeRange(0, batch.length);
        _attached
          ..addAll(uploaded)
          ..sort((a, b) => a.sortOrder.compareTo(b.sortOrder));
        _photoError = null;
      });

      return true;
    } catch (failure) {
      if (!mounted) return false;

      setState(() {
        _photoError = failure is ApiException
            ? failure.message
            : 'The report was saved but its photographs were not. '
                  'Open it again and add them.';
      });

      return false;
    }
  }

  /* --------------------------------------------------------------- save */

  bool _validate() {
    final errors = <String, String>{};

    if (_siteId == null) {
      errors['site_id'] = 'Choose the site this report is about.';
    } else if (_projectId == null) {
      // The picker only knows a project once it has actually seen the row
      // for the site. Rather than sending `project_id: null` and letting
      // the server's 422 explain a problem the person on the phone cannot
      // see, ask them to pick it once more.
      errors['site_id'] =
          'That site has not been matched to its project yet. '
          'Choose it again.';
    }

    if (_date.text.trim().isEmpty) {
      errors['report_date'] = 'Choose the day this work happened.';
    }

    if (_workCategory.text.trim().isEmpty) {
      errors['work_category'] = 'Say what kind of work this was.';
    } else if (_workCategory.text.trim().length > 60) {
      errors['work_category'] = 'Keep this to 60 characters or fewer.';
    }

    if (_workPerformed.text.trim().isEmpty) {
      errors['work_performed'] = 'Describe the work that was done.';
    }

    setState(() => _errors = errors);

    return errors.isEmpty;
  }

  Map<String, Object?> _body() => <String, Object?>{
    'site_id': _siteId,
    'project_id': _projectId,
    'report_date': _date.text.trim(),
    'work_category': _workCategory.text.trim(),
    'work_performed': _workPerformed.text.trim(),
    'progress_percentage': _progress.round(),
    'manpower': _manpower.text.trim(),
    'materials_used': _materials.text.trim(),
    'equipment_used': _equipment.text.trim(),
    'issues': _issues.text.trim(),
    'safety_issues': _safety.text.trim(),
    'remarks': _remarks.text.trim(),
    // The fix travels with the draft when there is one and is left out
    // entirely when there is not: a key the server never received cannot
    // erase a reading it already holds, which is what `present` validation
    // on the request class relies on.
    if (_fix != null) ...<String, Object?>{
      'latitude': _fix!.latitude,
      'longitude': _fix!.longitude,
      'gps_accuracy': _fix!.accuracy,
    },
  };

  /// Persists the report (and any staged photographs) and returns its id,
  /// or null when something went wrong and the screen has been told why.
  Future<int?> _persist() async {
    setState(() {
      _saving = true;
      _banner = null;
      _photoError = null;
    });

    try {
      final SiteActivityReport saved;

      if (_reportId == null) {
        saved = await _repository.create(_body());
        _savedId = saved.id;
      } else {
        saved = await _repository.update(_reportId!, _body());
      }

      if (!mounted) return null;

      final photosOk = await _uploadPending(saved.id);
      if (!mounted || !photosOk) return null;

      ref.read(siteActivityReportsListProvider.notifier).reload();

      return saved.id;
    } on ApiException catch (failure) {
      if (!mounted) return null;

      setState(() {
        _saving = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });

      return null;
    } catch (_) {
      if (!mounted) return null;

      setState(() {
        _saving = false;
        _banner = 'Something went wrong while saving. Please try again.';
      });

      return null;
    }
  }

  Future<void> _saveDraft() async {
    if (_saving || _submitting) return;
    if (!_validate()) return;

    final id = await _persist();
    if (id == null || !mounted) return;

    // Only a form that was *creating* owns a local copy. Clearing it
    // unconditionally would throw away the half-written report parked on
    // this phone the moment a different, already-filed one was corrected.
    if (widget.reportId == null) await _forgetDraft();
    if (!mounted) return;

    setState(() => _saving = false);

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('Report saved as a draft.')));

    context.go('/site-reports/$id');
  }

  Future<void> _submit() async {
    if (_saving || _submitting) return;
    if (!_validate()) return;

    // Sent now, not remembered from the draft. A fix taken an hour ago
    // while the report was being typed proves where the *phone* was, not
    // where the person filing it is standing — and the server only ever
    // sees this payload.
    final fix = _fix;

    if (fix == null || !fix.isUsable) {
      setState(() {
        _banner =
            'A location reading is needed before this can be submitted. '
            'Take one below, then try again.';
      });

      return;
    }

    setState(() => _submitting = true);

    final id = await _persist();
    if (id == null || !mounted) {
      setState(() => _submitting = false);
      return;
    }

    try {
      await _repository.submit(
        id,
        latitude: fix.latitude,
        longitude: fix.longitude,
        accuracy: fix.accuracy,
      );
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _submitting = false;
        _saving = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });

      return;
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _submitting = false;
        _saving = false;
        _banner = 'The report was saved but not submitted. Please try again.';
      });

      return;
    }

    if (!mounted) return;

    if (widget.reportId == null) await _forgetDraft();
    if (!mounted) return;

    context.go('/site-reports/$id');
  }

  /* --------------------------------------------------------------- build */

  @override
  Widget build(BuildContext context) {
    final allowed = widget.reportId == null
        ? _scope.canCreateSiteActivityReports
        : _scope.canUpdateSiteActivityReports;

    if (!allowed) {
      return const Scaffold(
        body: NoPermission(module: 'Site activity reports'),
      );
    }

    if (_forbidden) {
      return const Scaffold(
        body: NoPermission(module: 'Site activity reports'),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.reportId == null ? 'New site report' : 'Edit site report',
        ),
      ),
      body: _loading
          ? const Center(
              key: ValueKey('site-report-loading'),
              child: CircularProgressIndicator(),
            )
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_draftRestored) _draftNotice(),
                  if (_banner != null) _bannerBox(_banner!),
                  _section(
                    title: 'Where and when',
                    children: [
                      RemotePickerField<Site>(
                        key: const ValueKey('site-report-site'),
                        label: 'Site',
                        provider: reportableSitesPickerProvider,
                        idOf: (site) => site.id,
                        labelOf: (site) => site.projectName == null
                            ? site.name
                            : '${site.name} — ${site.projectName}',
                        value: _siteId,
                        selectedLabel: _siteName,
                        isRequired: true,
                        searchHint: 'Search your sites',
                        sheetTitle: 'Choose a site',
                        errorText: _errors['site_id'],
                        onChanged: (id) {
                          // The picker shows the loaded row's own label when
                          // it can find it; this fallback is only for a
                          // draft restored before page one has arrived, so
                          // the field is never blank next to an id.
                          String? name;
                          int? project;

                          if (id != null) {
                            for (final site
                                in ref
                                    .read(reportableSitesPickerProvider)
                                    .items) {
                              if (site.id == id) {
                                name = site.name;
                                project = site.projectId;
                                break;
                              }
                            }
                          }

                          setState(() {
                            _siteId = id;
                            _siteName = name;
                            _projectId = project;
                          });
                          _scheduleDraftSave();
                        },
                      ),
                      DateField(
                        key: const ValueKey('site-report-date'),
                        label: 'Date',
                        controller: _date,
                        isRequired: true,
                        // A site activity report describes a day that has
                        // happened. The server enforces the same bound, and
                        // offering tomorrow would only set up a refusal the
                        // person could not have predicted.
                        lastDate: DateTime.now(),
                        errorText: _errors['report_date'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Work',
                    children: [
                      LabeledTextField(
                        key: const ValueKey('site-report-work-category'),
                        label: 'Work category',
                        controller: _workCategory,
                        isRequired: true,
                        hint: 'e.g. RCC, blockwork, plumbing, painting',
                        helper:
                            'A short label for the trade or activity. Free '
                            'text on purpose — the trades on a project are '
                            'not the same as the ones on the next one.',
                        errorText: _errors['work_category'],
                        onChanged: (_) {
                          // The message describes the text as it stood a
                          // keystroke ago; carrying it forward once the
                          // sentence is short enough is a lie the person
                          // then has to disprove themselves.
                          if (_errors['work_category'] != null &&
                              _categoryIsPresentable) {
                            setState(
                              () =>
                                  _errors = {..._errors}
                                    ..remove('work_category'),
                            );
                          }
                          _scheduleDraftSave();
                        },
                      ),
                      LabeledTextField(
                        key: const ValueKey('site-report-work-performed'),
                        label: 'Work performed',
                        controller: _workPerformed,
                        isRequired: true,
                        maxLines: 5,
                        hint: 'What was actually done today',
                        errorText: _errors['work_performed'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      _progressField(),
                    ],
                  ),
                  _section(
                    title: 'Resources',
                    children: [
                      LabeledTextField(
                        key: const ValueKey('site-report-manpower'),
                        label: 'Manpower',
                        controller: _manpower,
                        maxLines: 3,
                        hint: 'e.g. 12 masons, 8 helpers, 2 bar benders',
                        helper:
                            'A note for this report. The official daily '
                            'report counts these as categories instead.',
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('site-report-materials'),
                        label: 'Materials used',
                        controller: _materials,
                        maxLines: 3,
                        hint: 'e.g. 3 tonnes TMT, 40 bags cement',
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('site-report-equipment'),
                        label: 'Equipment used',
                        controller: _equipment,
                        maxLines: 3,
                        hint: 'e.g. concrete pump, 2 tower cranes',
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Safety, issues and remarks',
                    children: [
                      LabeledTextField(
                        key: const ValueKey('site-report-safety'),
                        label: 'Safety issues',
                        controller: _safety,
                        maxLines: 3,
                        hint: 'Anything unsafe seen today',
                        errorText: _errors['safety_issues'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('site-report-issues'),
                        label: 'Issues / blockers',
                        controller: _issues,
                        maxLines: 3,
                        hint: 'What stopped or slowed the work',
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('site-report-remarks'),
                        label: 'Remarks',
                        controller: _remarks,
                        maxLines: 3,
                        hint: 'Anything else worth recording',
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Evidence',
                    children: [
                      ReportPhotosSection(
                        attached: List<SiteReportPhoto>.unmodifiable(_attached),
                        pending: List<Uint8List>.unmodifiable(_pending),
                        pathOf: (photo) => SiteReportPhoto.pathFor(
                          SiteActivityReport.photoBasePath,
                          _reportId ?? 0,
                          photo.id,
                        ),
                        onAdd: _addPhoto,
                        onRemoveAttached: _removeAttached,
                        onRemovePending: _removePending,
                        busy: _saving || _submitting,
                        errorText: _photoError,
                        emptyHint:
                            'No photographs yet. One of the work front is '
                            'worth more than a paragraph.',
                      ),
                      ReportGpsField(
                        fix: _fix,
                        onCaptured: (fix) {
                          setState(() => _fix = fix);
                          _scheduleDraftSave();
                        },
                        required: true,
                        busy: _saving || _submitting,
                        errorText: _errors['latitude'],
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    key: const ValueKey('save-site-report'),
                    onPressed: _saving || _submitting ? null : _saveDraft,
                    child: _saving && !_submitting
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(
                            widget.reportId == null
                                ? 'Save as draft'
                                : 'Save changes',
                          ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    key: const ValueKey('submit-site-report'),
                    onPressed: _saving || _submitting ? null : _submit,
                    child: _submitting
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Submit report'),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Submitting closes the report to further edits. The '
                    'location reading is taken at that moment and travels '
                    'with the submission.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
    );
  }

  Widget _progressField() {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text('Progress', style: theme.textTheme.labelLarge),
              const SizedBox(width: 8),
              Text(
                '${_progress.round()}%',
                key: const ValueKey('site-report-progress-value'),
                style: theme.textTheme.titleMedium,
              ),
            ],
          ),
          Slider(
            key: const ValueKey('site-report-progress'),
            value: _progress,
            min: 0,
            max: 100,
            divisions: 100,
            label: '${_progress.round()}%',
            onChanged: (value) {
              setState(() => _progress = value);
              _scheduleDraftSave();
            },
          ),
          Text(
            'How much of this trade\'s scope for the day is complete.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  Widget _draftNotice() => Material(
    key: const ValueKey('site-report-draft-notice'),
    color: Theme.of(context).colorScheme.secondaryContainer,
    child: Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
      child: Row(
        children: [
          const Icon(Icons.history, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'A draft you left unfinished was restored from this phone. '
              'Nothing has been sent to the server yet.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          TextButton(
            key: const ValueKey('site-report-draft-start-over'),
            onPressed: _startOver,
            child: const Text('Start over'),
          ),
        ],
      ),
    ),
  );

  Widget _bannerBox(String message) => Padding(
    key: const ValueKey('site-report-banner'),
    padding: const EdgeInsets.only(bottom: 16),
    child: Material(
      color: Theme.of(context).colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Text(
          message,
          style: TextStyle(
            color: Theme.of(context).colorScheme.onErrorContainer,
          ),
        ),
      ),
    ),
  );

  Widget _section({required String title, required List<Widget> children}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 10),
              child: Text(
                title,
                key: ValueKey('site-report-section-$title'),
                style: Theme.of(context).textTheme.titleSmall
                    ?.copyWith(color: Theme.of(context).colorScheme.primary),
              ),
            ),
            ...children,
          ],
        ),
      );
}
