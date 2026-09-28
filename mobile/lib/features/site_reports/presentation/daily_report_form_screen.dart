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
import '../../sites/domain/site.dart';
import '../data/api_daily_site_report_repository.dart';
import '../data/report_draft_store.dart';
import '../domain/daily_site_report.dart';
import '../domain/daily_site_report_repository.dart';
import '../domain/site_report_photo.dart';
import 'repeatable_rows.dart';
import 'report_photos_section.dart';
import 'daily_reports_controller.dart';
import 'site_report_photo_sheet.dart';
import 'site_reports_controller.dart';

/// Prepares, or corrects, the official document for one site-day.
///
/// Everything here follows from three backend rules the screen does not
/// get to renegotiate:
///
///  - **one document per site per date.** The server enforces it twice —
///    a 422 naming `report_date` for the human, a unique index for the
///    database — and this form turns that 422 into a field error rather
///    than a banner, because "somebody has already written this day" is
///    an answer about one input.
///  - **the total is derived from the rows.** `total_manpower` is sent as
///    the sum of the categories and is recomputed by the server anyway;
///    the point of sending it is that the two can never disagree, not
///    that the number is editable.
///  - **manpower, materials and equipment are rows, not prose.** Unlike
///    the activity report's free text, this document is read by somebody
///    who was not there, so the counts have to be countable.
///
/// Photographs are staged here and uploaded once the report exists, for
/// the same reason as on the activity form: a correction must not re-send
/// every frame over a site's connection.
class DailySiteReportFormScreen extends ConsumerStatefulWidget {
  const DailySiteReportFormScreen({super.key, this.reportId});

  /// Null to start a new document; otherwise the draft to correct.
  final int? reportId;

  @override
  ConsumerState<DailySiteReportFormScreen> createState() =>
      _DailySiteReportFormScreenState();
}

class _DailySiteReportFormScreenState
    extends ConsumerState<DailySiteReportFormScreen> {
  late final TextEditingController _date;
  late final TextEditingController _workPlanned;
  late final TextEditingController _workCompleted;
  late final TextEditingController _safety;
  late final TextEditingController _delays;
  late final TextEditingController _issues;
  late final TextEditingController _remarks;

  int? _siteId;
  String? _siteName;
  int? _projectId;

  /// One row is `columns.length` controllers, in column order. Owned here
  /// — and disposed here — because only this screen knows when the whole
  /// set is finished with: a rebuild must not throw away text somebody is
  /// halfway through typing.
  final List<List<TextEditingController>> _manpower =
      <List<TextEditingController>>[];
  final List<List<TextEditingController>> _materials =
      <List<TextEditingController>>[];
  final List<List<TextEditingController>> _equipment =
      <List<TextEditingController>>[];

  static const List<ReportColumn> _manpowerColumns = <ReportColumn>[
    ReportColumn(id: 'category', label: 'Category', required: true, flex: 3),
    ReportColumn(
      id: 'count',
      label: 'Count',
      required: true,
      flex: 1,
      keyboardType: TextInputType.number,
    ),
  ];

  static const List<ReportColumn> _materialColumns = <ReportColumn>[
    ReportColumn(id: 'name', label: 'Material', required: true, flex: 3),
    ReportColumn(
      id: 'quantity',
      label: 'Qty',
      required: true,
      flex: 1,
      keyboardType: TextInputType.number,
    ),
    ReportColumn(id: 'unit', label: 'Unit', required: true, flex: 1),
    ReportColumn(id: 'remarks', label: 'Notes', flex: 2, maxLines: 2),
  ];

  static const List<ReportColumn> _equipmentColumns = <ReportColumn>[
    ReportColumn(id: 'name', label: 'Equipment', required: true, flex: 3),
    ReportColumn(
      id: 'quantity',
      label: 'Qty',
      required: true,
      flex: 1,
      keyboardType: TextInputType.number,
    ),
    ReportColumn(
      id: 'hours',
      label: 'Hours',
      flex: 1,
      keyboardType: TextInputType.number,
    ),
    ReportColumn(id: 'condition', label: 'Condition', flex: 2),
    ReportColumn(id: 'remarks', label: 'Notes', flex: 2, maxLines: 2),
  ];

  final List<SiteReportPhoto> _attached = <SiteReportPhoto>[];
  final List<Uint8List> _pending = <Uint8List>[];

  /// See the same field on the activity form: once the server has an id,
  /// a retry must not create a second document for the same site and day.
  int? _savedId;

  bool _loading = false;
  bool _saving = false;
  bool _submitting = false;
  bool _forbidden = false;
  bool _draftRestored = false;

  String? _banner;
  String? _photoError;
  Map<String, String> _errors = const <String, String>{};

  /// Whether a save has been attempted at least once.
  ///
  /// The three repeatable sections validate live rather than from `_errors`,
  /// so without this flag the Manpower card would open by telling a person
  /// that Row 1 needs a category — on a form they have not touched yet,
  /// with the row they have not typed into sitting right above it. The
  /// field errors stay live after the first attempt, because from then on
  /// typing is what makes them wrong or right.
  bool _rowsValidated = false;

  Timer? _draftTimer;

  int? get _reportId => widget.reportId ?? _savedId;

  DailySiteReportRepository get _repository =>
      ref.read(dailySiteReportRepositoryProvider);

  PermissionScope get _scope => ref.read(permissionScopeProvider);

  @override
  void initState() {
    super.initState();

    _date = TextEditingController();
    _workPlanned = TextEditingController();
    _workCompleted = TextEditingController();
    _safety = TextEditingController();
    _delays = TextEditingController();
    _issues = TextEditingController();
    _remarks = TextEditingController();

    if (widget.reportId != null) {
      // Gate before the fetch; see the same note on the activity form.
      if (_scope.canUpdateDailySiteReports) _load();
    } else {
      _addRow(_manpower, _manpowerColumns);
      _restoreDraft();
    }
  }

  @override
  void dispose() {
    _draftTimer?.cancel();
    _date.dispose();
    _workPlanned.dispose();
    _workCompleted.dispose();
    _safety.dispose();
    _delays.dispose();
    _issues.dispose();
    _remarks.dispose();
    _disposeRows(_manpower);
    _disposeRows(_materials);
    _disposeRows(_equipment);
    super.dispose();
  }

  /* ---------------------------------------------------------------- rows */

  List<TextEditingController> _addRow(
    List<List<TextEditingController>> rows,
    List<ReportColumn> columns,
  ) {
    final row = columns
        .map((_) => TextEditingController())
        .toList(growable: false);

    rows.add(row);

    return row;
  }

  void _removeRow(List<List<TextEditingController>> rows, int index) {
    if (index < 0 || index >= rows.length) return;

    _disposeRows([rows.removeAt(index)]);
    _scheduleDraftSave();
  }

  void _disposeRows(List<List<TextEditingController>> rows) {
    for (final row in rows) {
      for (final controller in row) {
        controller.dispose();
      }
    }
  }

  /// Rows as plain lists of strings, which is both what the draft store
  /// can serialise and what a restored form needs: one round trip, no
  /// intermediate objects, and a shape that cannot silently disagree with
  /// the column order it came from because it *is* the column order.
  List<List<String>> _rowsFor(List<List<TextEditingController>> rows) => [
    for (final row in rows) [for (final controller in row) controller.text],
  ];

  /* ------------------------------------------------------------- loading */

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final report = await _repository.find(widget.reportId!);
      if (!mounted) return;

      _disposeRows(_manpower);
      _disposeRows(_materials);
      _disposeRows(_equipment);

      setState(() {
        _date.text = report.reportDate;
        _workPlanned.text = report.workPlanned;
        _workCompleted.text = report.workCompleted;
        _safety.text = report.safetyObservations ?? '';
        _delays.text = report.delays ?? '';
        _issues.text = report.issues ?? '';
        _remarks.text = report.remarks ?? '';
        _siteId = report.siteId;
        _siteName = report.siteName;
        _projectId = report.projectId;
        _attached
          ..clear()
          ..addAll(report.photos);

        _manpower.clear();
        _materials.clear();
        _equipment.clear();

        for (final row in report.manpower) {
          _addRow(_manpower, _manpowerColumns)
            ..[0].text = row.category
            ..[1].text = '${row.count}';
        }

        for (final row in report.materials) {
          _addRow(_materials, _materialColumns)
            ..[0].text = row.name
            ..[1].text = row.quantityLabel
            ..[2].text = row.unit ?? ''
            ..[3].text = row.remarks ?? '';
        }

        for (final row in report.equipment) {
          _addRow(_equipment, _equipmentColumns)
            ..[0].text = row.name
            ..[1].text = row.quantityLabel
            ..[2].text = row.operatingHours == null
                ? ''
                : '${row.operatingHours!}'
            ..[3].text = row.condition ?? ''
            ..[4].text = row.remarks ?? '';
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

  /* ---------------------------------------------------------------- draft */

  Map<String, Object?> _snapshot() => <String, Object?>{
    'site_id': _siteId,
    'site_name': _siteName,
    'project_id': _projectId,
    'report_date': _date.text,
    'work_planned': _workPlanned.text,
    'work_completed': _workCompleted.text,
    'safety_observations': _safety.text,
    'delays': _delays.text,
    'issues': _issues.text,
    'remarks': _remarks.text,
    'manpower': _rowsFor(_manpower),
    'materials': _rowsFor(_materials),
    'equipment': _rowsFor(_equipment),
    // Photographs are deliberately absent — see the activity form: a local
    // copy of a frame the person has already discarded would come back as
    // evidence of a photograph nobody took.
  };

  void _applyDraft(Map<String, Object?> draft) {
    final hasContent =
        (draft['site_id'] is int) ||
        (draft['report_date'] as String? ?? '').isNotEmpty ||
        (draft['work_planned'] as String? ?? '').isNotEmpty ||
        (draft['work_completed'] as String? ?? '').isNotEmpty;

    if (!hasContent) return;

    setState(() {
      _siteId = draft['site_id'] is int ? draft['site_id'] as int : null;
      _siteName = draft['site_name'] as String?;
      _projectId = draft['project_id'] is int
          ? draft['project_id'] as int
          : null;
      _date.text = draft['report_date'] as String? ?? '';
      _workPlanned.text = draft['work_planned'] as String? ?? '';
      _workCompleted.text = draft['work_completed'] as String? ?? '';
      _safety.text = draft['safety_observations'] as String? ?? '';
      _delays.text = draft['delays'] as String? ?? '';
      _issues.text = draft['issues'] as String? ?? '';
      _remarks.text = draft['remarks'] as String? ?? '';
      _draftRestored = true;

      _restoreRows(_manpower, _manpowerColumns, draft['manpower']);
      _restoreRows(_materials, _materialColumns, draft['materials']);
      _restoreRows(_equipment, _equipmentColumns, draft['equipment']);

      if (_manpower.isEmpty) _addRow(_manpower, _manpowerColumns);
    });
  }

  void _restoreRows(
    List<List<TextEditingController>> rows,
    List<ReportColumn> columns,
    Object? raw,
  ) {
    _disposeRows(rows);
    rows.clear();

    if (raw is! List) return;

    for (final entry in raw) {
      if (entry is! List || entry.length != columns.length) continue;

      _addRow(rows, columns);

      for (var index = 0; index < columns.length; index++) {
        rows.last[index].text = '${entry[index]}';
      }
    }
  }

  Future<void> _restoreDraft() async {
    final draft = await ref.read(reportDraftStoreProvider).read(dailyDraftSlot);

    if (draft == null || !mounted || widget.reportId != null) return;

    _applyDraft(draft);
  }

  /// What happens when any cell of any repeatable row is edited.
  ///
  /// Two things, because those are the two pieces of this form that live
  /// entirely below the rows and cannot otherwise hear them: the derived
  /// head count, and the deferred local copy. The rows themselves hold no
  /// state of their own — the controllers belong to the screen — so an
  /// edit that told nobody would leave both showing yesterday's answer.
  void _rowEdited(String _) {
    setState(() {});
    _scheduleDraftSave();
  }

  void _scheduleDraftSave() {
    if (widget.reportId != null) return;

    _draftTimer?.cancel();
    _draftTimer = Timer(SharedPreferencesReportDraftStore.debounce, () {
      _draftTimer = null;
      unawaited(
        ref.read(reportDraftStoreProvider).write(dailyDraftSlot, _snapshot()),
      );
    });
  }

  Future<void> _forgetDraft() async {
    _draftTimer?.cancel();
    _draftTimer = null;
    await ref.read(reportDraftStoreProvider).clear(dailyDraftSlot);
  }

  Future<void> _startOver() async {
    await _forgetDraft();
    if (!mounted) return;

    setState(() {
      _siteId = null;
      _siteName = null;
      _projectId = null;
      _date.clear();
      _workPlanned.clear();
      _workCompleted.clear();
      _safety.clear();
      _delays.clear();
      _issues.clear();
      _remarks.clear();
      _pending.clear();
      _draftRestored = false;
      _banner = null;
      _errors = const <String, String>{};
      _rowsValidated = false;

      _disposeRows(_manpower);
      _disposeRows(_materials);
      _disposeRows(_equipment);
      _manpower.clear();
      _materials.clear();
      _equipment.clear();
      _addRow(_manpower, _manpowerColumns);
    });
  }

  /* --------------------------------------------------------------- photos */

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

  /* ----------------------------------------------------------------- save */

  int get _totalManpower {
    var total = 0;

    for (final row in _manpower) {
      total += int.tryParse(row[1].text.trim()) ?? 0;
    }

    return total;
  }

  /// The section errors, as one sentence naming the row that failed.
  ///
  /// A per-cell message would be more precise, but `RepeatableRowsField`
  /// takes one error per section by design: a person told "row 3: count is
  /// needed" knows exactly where to look, and a table with red underlines
  /// down it reads as though everything failed rather than as though one
  /// cell did.
  String? _rowError(
    List<List<TextEditingController>> rows,
    List<ReportColumn> columns,
    List<bool Function(List<TextEditingController>)> checks,
  ) {
    for (var index = 0; index < rows.length; index++) {
      for (var check = 0; check < checks.length; check++) {
        if (!checks[check](rows[index])) {
          return 'Row ${index + 1}: ${columns[check].label.toLowerCase()} '
              'is needed.';
        }
      }
    }

    return null;
  }

  String? get _manpowerError => _rowError(_manpower, _manpowerColumns, [
    (row) => row[0].text.trim().isNotEmpty,
    (row) => (int.tryParse(row[1].text.trim()) ?? -1) >= 0,
  ]);

  String? get _materialError => _rowError(_materials, _materialColumns, [
    (row) => row[0].text.trim().isNotEmpty,
    (row) => (double.tryParse(row[1].text.trim()) ?? -1) >= 0,
    (row) => row[2].text.trim().isNotEmpty,
  ]);

  String? get _equipmentError => _rowError(_equipment, _equipmentColumns, [
    (row) => row[0].text.trim().isNotEmpty,
    (row) => (int.tryParse(row[1].text.trim()) ?? 0) >= 1,
  ]);

  bool _validate() {
    final errors = <String, String>{};

    if (_siteId == null) {
      errors['site_id'] = 'Choose the site this report is about.';
    } else if (_projectId == null) {
      errors['site_id'] =
          'That site has not been matched to its project yet. '
          'Choose it again.';
    }

    if (_date.text.trim().isEmpty) {
      errors['report_date'] = 'Choose the day this report covers.';
    }

    if (_workPlanned.text.trim().isEmpty) {
      errors['work_planned'] = 'Say what was planned for the day.';
    }

    if (_workCompleted.text.trim().isEmpty) {
      errors['work_completed'] = 'Say what was actually completed.';
    }

    setState(() {
      _errors = errors;
      _rowsValidated = true;
    });

    return errors.isEmpty &&
        _manpowerError == null &&
        _materialError == null &&
        _equipmentError == null;
  }

  Map<String, Object?> _body() => <String, Object?>{
    'site_id': _siteId,
    'project_id': _projectId,
    'report_date': _date.text.trim(),
    'work_planned': _workPlanned.text.trim(),
    'work_completed': _workCompleted.text.trim(),
    // Both, always: the rows are the truth and the total is what the PDF
    // prints, and a payload carrying only one of them could let the two
    // drift for exactly as long as it takes somebody to notice.
    'manpower': [
      for (final row in _manpower)
        <String, Object?>{
          'category': row[0].text.trim(),
          'count': int.tryParse(row[1].text.trim()) ?? 0,
        },
    ],
    'total_manpower': _totalManpower,
    'materials': [
      for (final row in _materials)
        <String, Object?>{
          'material_name': row[0].text.trim(),
          'quantity': double.tryParse(row[1].text.trim()) ?? 0,
          'unit': row[2].text.trim(),
          if (row[3].text.trim().isNotEmpty) 'remarks': row[3].text.trim(),
        },
    ],
    'equipment': [
      for (final row in _equipment)
        <String, Object?>{
          'equipment_name': row[0].text.trim(),
          'quantity': int.tryParse(row[1].text.trim()) ?? 1,
          if (row[2].text.trim().isNotEmpty)
            'operating_hours': double.tryParse(row[2].text.trim()),
          if (row[3].text.trim().isNotEmpty) 'condition': row[3].text.trim(),
          if (row[4].text.trim().isNotEmpty) 'remarks': row[4].text.trim(),
        },
    ],
    'safety_observations': _safety.text.trim(),
    'delays': _delays.text.trim(),
    'issues': _issues.text.trim(),
    'remarks': _remarks.text.trim(),
  };

  Future<int?> _persist() async {
    setState(() {
      _saving = true;
      _banner = null;
      _photoError = null;
    });

    try {
      final DailySiteReport saved;

      if (_reportId == null) {
        saved = await _repository.create(_body());
        _savedId = saved.id;
      } else {
        saved = await _repository.update(_reportId!, _body());
      }

      if (!mounted) return null;

      final photosOk = await _uploadPending(saved.id);
      if (!mounted || !photosOk) return null;

      ref.read(dailySiteReportsListProvider.notifier).reload();

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

    // Only a form that was *creating* owns a local copy; see the activity
    // form for why this is conditional rather than unconditional.
    if (widget.reportId == null) await _forgetDraft();
    if (!mounted) return;

    setState(() => _saving = false);

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Daily report saved as a draft.')),
    );

    context.go('/daily-reports/$id');
  }

  Future<void> _submit() async {
    if (_saving || _submitting) return;
    if (!_validate()) return;

    setState(() => _submitting = true);

    final id = await _persist();
    if (id == null || !mounted) {
      setState(() => _submitting = false);
      return;
    }

    try {
      await _repository.submit(id);
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

    context.go('/daily-reports/$id');
  }

  /* ---------------------------------------------------------------- build */

  @override
  Widget build(BuildContext context) {
    final allowed = widget.reportId == null
        ? _scope.canCreateDailySiteReports
        : _scope.canUpdateDailySiteReports;

    if (!allowed || _forbidden) {
      return const Scaffold(body: NoPermission(module: 'daily site reports'));
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.reportId == null
              ? 'New daily site report'
              : 'Edit daily site report',
        ),
      ),
      body: _loading
          ? const Center(
              key: ValueKey('daily-report-loading'),
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
                        key: const ValueKey('daily-report-site'),
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
                        key: const ValueKey('daily-report-date'),
                        label: 'Date',
                        controller: _date,
                        isRequired: true,
                        // One document per site per date, so a future date
                        // would break a rule nobody could have prepared
                        // for. The server enforces the same bound.
                        lastDate: DateTime.now(),
                        errorText: _errors['report_date'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Work planned and completed',
                    children: [
                      LabeledTextField(
                        key: const ValueKey('daily-report-planned'),
                        label: 'Work planned',
                        controller: _workPlanned,
                        isRequired: true,
                        maxLines: 4,
                        hint: 'What the day was supposed to produce',
                        errorText: _errors['work_planned'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('daily-report-completed'),
                        label: 'Work completed',
                        controller: _workCompleted,
                        isRequired: true,
                        maxLines: 4,
                        hint: 'What it actually produced',
                        errorText: _errors['work_completed'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Manpower',
                    children: [
                      RepeatableRowsField(
                        id: 'manpower',
                        label: 'Workforce on site',
                        columns: _manpowerColumns,
                        rows: _manpower,
                        addRowLabel: 'Add a category',
                        isRequired: true,
                        busy: _saving || _submitting,
                        errorText: _rowsValidated ? _manpowerError : null,
                        helper:
                            'Whatever word this site uses for its people. '
                            'Categories are not a fixed list — type the one '
                            'that fits.',
                        emptyHint: 'No categories yet.',
                        onAdd: () {
                          setState(() => _addRow(_manpower, _manpowerColumns));
                          _scheduleDraftSave();
                        },
                        onRemove: (index) {
                          setState(() => _removeRow(_manpower, index));
                          _scheduleDraftSave();
                        },
                        onRowChanged: _rowEdited,
                      ),
                      Padding(
                        padding: const EdgeInsets.only(bottom: 16),
                        child: Row(
                          key: const ValueKey('daily-report-total-manpower'),
                          children: [
                            Text(
                              'Total on site',
                              style: Theme.of(context).textTheme.labelLarge,
                            ),
                            const Spacer(),
                            Text(
                              '$_totalManpower people',
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  _section(
                    title: 'Materials',
                    children: [
                      RepeatableRowsField(
                        id: 'materials',
                        label: 'Materials used',
                        columns: _materialColumns,
                        rows: _materials,
                        addRowLabel: 'Add a material',
                        busy: _saving || _submitting,
                        errorText: _rowsValidated ? _materialError : null,
                        helper:
                            'What the day consumed — not what is in store. '
                            'This is not an inventory.',
                        emptyHint: 'No materials recorded.',
                        onAdd: () {
                          setState(() => _addRow(_materials, _materialColumns));
                          _scheduleDraftSave();
                        },
                        onRemove: (index) {
                          setState(() => _removeRow(_materials, index));
                          _scheduleDraftSave();
                        },
                        onRowChanged: _rowEdited,
                      ),
                    ],
                  ),
                  _section(
                    title: 'Equipment',
                    children: [
                      RepeatableRowsField(
                        id: 'equipment',
                        label: 'Plant and equipment',
                        columns: _equipmentColumns,
                        rows: _equipment,
                        addRowLabel: 'Add equipment',
                        busy: _saving || _submitting,
                        errorText: _rowsValidated ? _equipmentError : null,
                        helper:
                            'Hours are the reading taken that day, not a '
                            'running total.',
                        emptyHint: 'No equipment recorded.',
                        onAdd: () {
                          setState(
                            () => _addRow(_equipment, _equipmentColumns),
                          );
                          _scheduleDraftSave();
                        },
                        onRemove: (index) {
                          setState(() => _removeRow(_equipment, index));
                          _scheduleDraftSave();
                        },
                        onRowChanged: _rowEdited,
                      ),
                    ],
                  ),
                  _section(
                    title: 'Safety, delays, issues and remarks',
                    children: [
                      LabeledTextField(
                        key: const ValueKey('daily-report-safety'),
                        label: 'Safety observations',
                        controller: _safety,
                        maxLines: 3,
                        hint: 'Anything seen, done or missing today',
                        errorText: _errors['safety_observations'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('daily-report-delays'),
                        label: 'Delays',
                        controller: _delays,
                        maxLines: 3,
                        hint: 'What held the work up',
                        errorText: _errors['delays'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('daily-report-issues'),
                        label: 'Issues',
                        controller: _issues,
                        maxLines: 3,
                        hint: 'Open problems worth escalating',
                        errorText: _errors['issues'],
                        onChanged: (_) => _scheduleDraftSave(),
                      ),
                      LabeledTextField(
                        key: const ValueKey('daily-report-remarks'),
                        label: 'Remarks',
                        controller: _remarks,
                        maxLines: 3,
                        hint: 'Anything else the reviewer should read',
                        errorText: _errors['remarks'],
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
                          DailySiteReport.photoBasePath,
                          _reportId ?? 0,
                          photo.id,
                        ),
                        onAdd: _addPhoto,
                        onRemoveAttached: _removeAttached,
                        onRemovePending: _removePending,
                        busy: _saving || _submitting,
                        errorText: _photoError,
                        emptyHint:
                            'No photographs yet. The PDF embeds up to six, '
                            'in the order they appear here.',
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    key: const ValueKey('save-daily-report'),
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
                    key: const ValueKey('submit-daily-report'),
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
                    'There is one report per site per day. If somebody has '
                    'already prepared this date, the server will say so and '
                    'keep this as a draft.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
    );
  }

  Widget _draftNotice() => Material(
    key: const ValueKey('daily-report-draft-notice'),
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
            key: const ValueKey('daily-report-draft-start-over'),
            onPressed: _startOver,
            child: const Text('Start over'),
          ),
        ],
      ),
    ),
  );

  Widget _bannerBox(String message) => Padding(
    key: const ValueKey('daily-report-banner'),
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
                key: ValueKey('daily-report-section-$title'),
                style: Theme.of(context).textTheme.titleSmall
                    ?.copyWith(color: Theme.of(context).colorScheme.primary),
              ),
            ),
            ...children,
          ],
        ),
      );
}
