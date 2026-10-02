import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/config/client_settings.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';
import 'package:mobile/features/assets/data/api_asset_repository.dart';
import 'package:mobile/features/assets/domain/asset.dart';
import 'package:mobile/features/assets/domain/asset_assignment.dart';
import 'package:mobile/features/assets/domain/asset_repository.dart';
import 'package:mobile/features/assets/domain/asset_type.dart';
import 'package:mobile/features/documents/data/document_source.dart';
import 'package:mobile/features/employees/data/api_employees_repository.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/training/data/api_training_repository.dart';
import 'package:mobile/features/training/domain/employee_training.dart';
import 'package:mobile/features/training/domain/training_compliance.dart';
import 'package:mobile/features/training/domain/training_program.dart';
import 'package:mobile/features/training/domain/training_repository.dart';
import 'package:mobile/features/training/domain/training_type.dart';

import 'attendance.dart' show ScriptedCamera;
import 'fakes.dart' show buildUser;
import 'phase10.dart' show ScriptedDocumentPicker;
import 'phase4.dart';
import 'phase9.dart' show ScriptedClientSettings;

export 'phase4.dart'
    show advance, useTallScreen, forbidden403, notFound404, unreachable;

/// The training repository, scripted.
///
/// **Rows arrive as JSON and are parsed**, for the same reason
/// `ScriptedDocuments` does it that way: the payloads *are*
/// `EmployeeTrainingResource`, so a test that shows a screen is also a test
/// that the screen can read what the API actually sends.
///
/// The two writes that matter are modelled rather than merely recorded.
/// `complete` and `cancel` re-derive the row from its payload with the new
/// status merged in, because the assertion after them is "what the screen
/// can then say" — a double that only counted the call would leave the
/// detail screen drawing `Enrolled` after a completion that just happened,
/// and a test would pass while the app told a lie.
class ScriptedTraining extends Scripted<EmployeeTraining>
    implements TrainingRepository {
  /// `fallback` is deliberately absent: every answer this double gives is
  /// derived from a payload, and a staged row with no payload behind it
  /// would be a shape no endpoint ever sent.
  ScriptedTraining({List<EmployeeTraining> items = const <EmployeeTraining>[]})
    : super(items: items, idOf: (row) => row.id);

  /// Parses [rows] into a double that can also move them.
  factory ScriptedTraining.rows(List<Map<String, dynamic>> rows) {
    final script = ScriptedTraining(
      items: rows.map(EmployeeTraining.fromJson).toList(growable: false),
    );

    script.payloads = <int, Map<String, dynamic>>{
      for (final row in rows)
        if (row['id'] is int) row['id']! as int: row,
    };

    return script;
  }

  /// The resource payload behind each row, keyed by id.
  Map<int, Map<String, dynamic>> payloads = <int, Map<String, dynamic>>{};

  /// `GET /employee-training/expiring` — a *different pool* from the list
  /// above, so a test can show that the report asked for the report.
  List<EmployeeTraining> expiringRows = <EmployeeTraining>[];

  /// The compliance reply, as JSON for the same reason as [payloads].
  Map<String, dynamic>? complianceReply;

  /// The catalogue, kept beside the enrolments because `programs`,
  /// `findProgram` and `createProgram` are three questions about a table
  /// this double also owns.
  List<TrainingProgram> programItems = <TrainingProgram>[];

  /// The training-type vocabulary, as JSON.
  List<Map<String, dynamic>> typeRows = <Map<String, dynamic>>[];

  Object? expiringError;
  Object? complianceError;
  Object? completeError;
  Object? cancelError;
  Object? fileError;
  Object? typesError;
  Object? programsError;
  Object? enrollError;

  /// Writes to the catalogue, counted apart from `createCalls` — which
  /// counts *seats* written into `employee_trainings`, a different table
  /// with a different question behind it.
  int createProgramCalls = 0;
  int updateProgramCalls = 0;

  /// Thrown once by the next program write. Its own field rather than
  /// [saveError] because [saveError] belongs to an *enrolment* save, and a
  /// catalogue refusal and a seat refusal are two different questions a test
  /// asks about.
  Object? programError;

  int expiringCalls = 0;
  int complianceCalls = 0;
  int completeCalls = 0;
  int cancelCalls = 0;
  int fileCalls = 0;
  int typesCalls = 0;
  int programsCalls = 0;
  int enrollCalls = 0;

  int? lastCompleteId;
  int? lastCancelId;
  int? lastEnrollId;
  int? lastFileId;
  int? lastProgramId;

  Map<String, Object?>? lastCompleteBody;
  Map<String, Object?>? lastCancelBody;
  Map<String, Object?>? lastEnrollBody;
  Map<String, Object?>? lastProgramBody;
  Map<String, Object?>? lastExpiringQuery;
  Map<String, Object?>? lastProgramQuery;
  Map<String, Object?>? lastTypeQuery;

  /// The certificate bytes and name the last `complete` travelled with —
  /// the two facts a completion test cannot read off `lastCompleteBody`,
  /// because the form sends them beside the fields rather than inside them.
  Uint8List? lastCompleteFile;
  String? lastCompleteFilename;

  /// What [file] answers with. `%PDF-` prefixed so a test that asserts an
  /// opened file really was a PDF is asserting something about bytes.
  Uint8List fileBytes = Uint8List.fromList(const <int>[
    37,
    80,
    68,
    70,
    45,
    49,
    46,
    55,
  ]);

  int _nextId = 9000;

  /// Re-derives one row from its payload with [changes] merged in — the way
  /// the server would, instead of the double inventing an answer the model
  /// has no way to express.
  void _rebuild(int id, Map<String, dynamic> changes) {
    final payload = payloads[id];
    if (payload == null) return;

    final merged = <String, dynamic>{...payload, ...changes};
    payloads[id] = merged;

    final index = items.indexWhere((row) => row.id == id);
    if (index >= 0) items[index] = EmployeeTraining.fromJson(merged);
  }

  @override
  Future<PageResult<EmployeeTraining>> expiring({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    expiringCalls++;
    lastExpiringQuery = query;

    final error = expiringError;
    if (error != null) {
      expiringError = null;
      throw error;
    }

    final total = expiringRows.length;

    return PageResult<EmployeeTraining>(
      items: expiringRows,
      currentPage: 1,
      lastPage: 1,
      perPage: total,
      total: total,
      hasNext: false,
    );
  }

  @override
  Future<TrainingCompliance> compliance() async {
    complianceCalls++;

    final error = complianceError;
    if (error != null) {
      complianceError = null;
      throw error;
    }

    return TrainingCompliance.fromJson(
      complianceReply ?? trainingComplianceJson(),
    );
  }

  @override
  Future<EmployeeTraining> enroll(Map<String, Object?> body) async {
    enrollCalls++;
    lastEnrollBody = body;

    final error = enrollError;
    if (error != null) {
      enrollError = null;
      throw error;
    }

    final id = _nextId++;
    lastEnrollId = id;
    final payload = employeeTrainingRow(
      id: id,
      employeeId: body['employee_id'] as int? ?? 7,
      employeeName: 'Anu Kmani',
      programId: body['training_program_id'] as int? ?? 1,
      enrollmentDate: body['enrollment_date'] as String?,
      trainingDate: body['training_date'] as String?,
      trainer: body['trainer'] as String?,
      status: EmployeeTraining.statusEnrolled,
      remarks: body['remarks'] as String?,
      program: _programPayload(body['training_program_id'] as int?),
    );

    payloads[id] = payload;
    items.add(EmployeeTraining.fromJson(payload));

    return items.last;
  }

  @override
  Future<EmployeeTraining> complete(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) async {
    completeCalls++;
    lastCompleteId = id;
    lastCompleteBody = body;
    lastCompleteFile = file;
    lastCompleteFilename = filename;

    final error = completeError;
    if (error != null) {
      completeError = null;
      throw error;
    }

    _rebuild(id, <String, dynamic>{
      'status': EmployeeTraining.statusCompleted,
      'completion_date': body['completion_date'],
      'training_date': body['training_date'] ?? payloadOf(id)['training_date'],
      'trainer': body['trainer'],
      'result': body['result'],
      'remarks': body['remarks'],
      'certificate_number': body['certificate_number'],
      'certificate_issue_date': body['certificate_issue_date'],
      'certificate_expiry_date': body['certificate_expiry_date'],
      'has_certificate': file != null,
      'certificate_original_name': filename,
      'certificate_mime_type': file == null ? null : 'application/pdf',
      'certificate_size': file?.length,
      'certificate_expiry_state': file == null
          ? EmployeeTraining.expiryNone
          : EmployeeTraining.expiryValid,
      'is_editable': false,
      'is_terminal': true,
      'is_completable': false,
    });

    return find(id);
  }

  @override
  Future<EmployeeTraining> cancel(int id, {String? remarks}) async {
    cancelCalls++;
    lastCancelId = id;
    lastCancelBody = remarks == null
        ? null
        : <String, Object?>{'remarks': remarks};

    final error = cancelError;
    if (error != null) {
      cancelError = null;
      throw error;
    }

    _rebuild(id, <String, dynamic>{
      'status': EmployeeTraining.statusCancelled,
      'remarks': remarks ?? payloadOf(id)['remarks'],
      'is_editable': false,
      'is_terminal': true,
      'is_completable': false,
    });

    return find(id);
  }

  @override
  Future<Uint8List> file(int id) async {
    fileCalls++;
    lastFileId = id;

    final error = fileError;
    if (error != null) {
      fileError = null;
      throw error;
    }

    return fileBytes;
  }

  @override
  Future<List<TrainingType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    typesCalls++;
    lastTypeQuery = query;

    final error = typesError;
    if (error != null) {
      typesError = null;
      throw error;
    }

    return typeRows.map(TrainingType.fromJson).toList(growable: false);
  }

  @override
  Future<PageResult<TrainingProgram>> programs({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    programsCalls++;
    lastProgramQuery = query;

    final error = programsError;
    if (error != null) {
      programsError = null;
      throw error;
    }

    final total = programItems.length;
    final last = total == 0 ? 1 : (total + pageSize - 1) ~/ pageSize;
    final start = (page - 1) * pageSize;
    final end = start + pageSize > total ? total : start + pageSize;

    return PageResult<TrainingProgram>(
      items: start >= total
          ? <TrainingProgram>[]
          : programItems.sublist(start, end),
      currentPage: page,
      lastPage: last,
      perPage: pageSize,
      total: total,
      hasNext: page < last,
    );
  }

  @override
  Future<TrainingProgram> findProgram(int id) async {
    lastProgramId = id;

    for (final program in programItems) {
      if (program.id == id) return program;
    }

    throw notFound404;
  }

  @override
  Future<TrainingProgram> createProgram(Map<String, Object?> body) async {
    lastProgramBody = body;
    createProgramCalls++;

    final error = programError;
    if (error != null) {
      programError = null;
      throw error;
    }

    final id = _nextId++;
    final program = TrainingProgram.fromJson(_formPayload(body, id: id));

    programItems.add(program);

    return program;
  }

  @override
  Future<TrainingProgram> updateProgram(
    int id,
    Map<String, Object?> body,
  ) async {
    lastProgramBody = body;
    lastId = id;
    updateProgramCalls++;

    final error = programError;
    if (error != null) {
      programError = null;
      throw error;
    }

    final index = programItems.indexWhere((program) => program.id == id);
    if (index < 0) throw notFound404;

    final current = _storedPayload(programItems[index]);
    programItems[index] = TrainingProgram.fromJson(<String, dynamic>{
      ...current,
      ...body,
      'training_type': _typePayload(body['training_type_id'] as int?),
      'is_active': body['status'] == TrainingProgram.statusActive,
    });

    return programItems[index];
  }

  /// The nested `training_program` a payload should carry, read from
  /// [programItems] so an enrolment never names a course the catalogue
  /// behind it does not have.
  Map<String, dynamic>? _programPayload(Object? programId) {
    for (final program in programItems) {
      if (program.id == programId) return _storedPayload(program);
    }

    return null;
  }

  /// A catalogue row as its own payload, so a nested copy of it is built
  /// the same way `TrainingProgramResource` builds one.
  Map<String, dynamic> _storedPayload(TrainingProgram program) =>
      <String, dynamic>{
        'id': program.id,
        'training_type_id': program.trainingTypeId,
        'training_type': _typePayload(program.trainingTypeId),
        'code': program.code,
        'name': program.name,
        'description': program.description,
        'provider': program.provider,
        'duration_days': program.durationDays,
        'certificate_required': program.certificateRequired,
        'certificate_validity_days': program.certificateValidityDays,
        'status': program.status,
        'is_active': program.isActive,
        'created_at': program.createdAt,
      };

  /// A form's body as a payload — the shape `createProgram` answers with.
  Map<String, dynamic> _formPayload(Map<String, Object?> body, {int? id}) {
    final status = body['status'] ?? TrainingProgram.statusActive;

    return <String, dynamic>{
      'id': id ?? 0,
      'training_type_id': body['training_type_id'] ?? 1,
      'training_type': _typePayload(body['training_type_id'] as int?),
      'code': body['code'] ?? '',
      'name': body['name'] ?? '',
      'description': body['description'],
      'provider': body['provider'],
      'duration_days': body['duration_days'],
      'certificate_required': body['certificate_required'] == true,
      'certificate_validity_days': body['certificate_validity_days'],
      'status': status,
      'is_active': status == TrainingProgram.statusActive,
      'created_at': '2026-10-01T00:00:00Z',
    };
  }

  Map<String, dynamic>? _typePayload(int? id) {
    for (final row in typeRows) {
      if (row['id'] == id) return row;
    }

    return typeRows.isEmpty ? null : typeRows.first;
  }

  Map<String, dynamic> payloadOf(int id) => payloads[id] ?? <String, dynamic>{};
}

/// The asset repository, scripted.
///
/// Three writes are modelled rather than recorded, and each for the same
/// reason `ScriptedTraining.complete` is: what the screen can *then* say is
/// the assertion. `assign` puts a hand-over on the row and moves the status,
/// `returnAsset` closes it and sets the condition to whatever came back, and
/// `changeStatus` moves the lifecycle — so a test can show the register
/// drawing `Available` after a hand-back instead of only asserting that a
/// method was called.
class ScriptedAssets extends Scripted<Asset> implements AssetRepository {
  ScriptedAssets({List<Asset> items = const <Asset>[]})
    : super(items: items, idOf: (asset) => asset.id);

  /// Parses [rows] into a double that can also move them.
  factory ScriptedAssets.rows(List<Map<String, dynamic>> rows) {
    final script = ScriptedAssets(
      items: rows.map(Asset.fromJson).toList(growable: false),
    );

    script.payloads = <int, Map<String, dynamic>>{
      for (final row in rows)
        if (row['id'] is int) row['id']! as int: row,
    };

    return script;
  }

  Map<int, Map<String, dynamic>> payloads = <int, Map<String, dynamic>>{};

  /// `GET /asset-assignments` — a *different pool* from the register above.
  List<AssetAssignment> assignmentRows = <AssetAssignment>[];

  /// The asset-type vocabulary, as JSON.
  List<Map<String, dynamic>> typeRows = <Map<String, dynamic>>[];

  Object? assignmentsError;
  Object? typesError;
  Object? assignError;
  Object? returnError;
  Object? statusError;

  int assignmentsCalls = 0;
  int findAssignmentCalls = 0;
  int typesCalls = 0;
  int assignCalls = 0;
  int returnCalls = 0;
  int statusCalls = 0;

  int? lastAssignId;
  int? lastReturnId;
  int? lastStatusId;

  Map<String, Object?>? lastAssignBody;
  Map<String, Object?>? lastReturnBody;
  Map<String, Object?>? lastAssignmentsQuery;
  Map<String, Object?>? lastTypeQuery;

  String? lastStatus;
  String? lastStatusNotes;

  int _nextAssignmentId = 5000;

  void _rebuild(int id, Map<String, dynamic> changes) {
    final payload = payloads[id];
    if (payload == null) return;

    final merged = <String, dynamic>{...payload, ...changes};
    payloads[id] = merged;

    final index = items.indexWhere((asset) => asset.id == id);
    if (index >= 0) items[index] = Asset.fromJson(merged);
  }

  @override
  Future<Asset> assign(int id, Map<String, Object?> body) async {
    assignCalls++;
    lastAssignId = id;
    lastAssignBody = body;

    final error = assignError;
    if (error != null) {
      assignError = null;
      throw error;
    }

    final asset = await find(id);
    final assignedDate = body['assigned_date'] as String? ?? '2026-10-01';

    final handOver = assetAssignmentRow(
      id: _nextAssignmentId++,
      assetId: id,
      employeeId: body['employee_id'] as int? ?? 7,
      employeeName: 'Anu Kmani',
      assignedDate: assignedDate,
      expectedReturnDate: body['expected_return_date'] as String?,
      assignedCondition: body['assigned_condition'] as String? ?? 'good',
      remarks: body['remarks'] as String?,
      status: AssetAssignment.statusActive,
    );

    _rebuild(id, <String, dynamic>{
      'status': Asset.statusAssigned,
      'current_condition': body['assigned_condition'] ?? asset.currentCondition,
      'current_assignment': handOver,
      'current_holder': <String, dynamic>{
        'employee_code': 'EMP-0007',
        'name': 'Anu Kmani',
      },
      'assignments': <Map<String, dynamic>>[
        ...(payloadOf(id)['assignments'] as List? ?? const <dynamic>[]),
        handOver,
      ],
      'is_assignable': false,
    });

    return find(id);
  }

  @override
  Future<Asset> returnAsset(int id, Map<String, Object?> body) async {
    returnCalls++;
    lastReturnId = id;
    lastReturnBody = body;

    final error = returnError;
    if (error != null) {
      returnError = null;
      throw error;
    }

    final condition = body['returned_condition'] as String? ?? 'good';

    _rebuild(id, <String, dynamic>{
      'status': condition == Asset.conditionPoor
          ? Asset.statusMaintenance
          : Asset.statusAvailable,
      'current_condition': condition,
      // The return's remarks replace the hand-over's, and a return with
      // none leaves the hand-over's standing — the server's own rule,
      // restated here so a test can show it on screen.
      'current_assignment': null,
      'current_holder': null,
      'is_assignable': condition != Asset.conditionPoor,
      if ((body['remarks'] as String? ?? '').isNotEmpty)
        'notes': body['remarks'],
    });

    return find(id);
  }

  @override
  Future<Asset> changeStatus(
    int id, {
    required String status,
    String? notes,
  }) async {
    statusCalls++;
    lastStatusId = id;
    lastStatus = status;
    lastStatusNotes = notes;

    final error = statusError;
    if (error != null) {
      statusError = null;
      throw error;
    }

    _rebuild(id, <String, dynamic>{
      'status': status,
      'is_retired': status == Asset.statusRetired,
      'is_assignable': status == Asset.statusAvailable,
      if ((notes ?? '').isNotEmpty) 'notes': notes,
    });

    return find(id);
  }

  @override
  Future<PageResult<AssetAssignment>> assignments({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    assignmentsCalls++;
    lastAssignmentsQuery = query;

    final error = assignmentsError;
    if (error != null) {
      assignmentsError = null;
      throw error;
    }

    final status = query['status'];
    final visible = (status is String && status.isNotEmpty)
        ? assignmentRows.where((row) => row.status == status).toList()
        : assignmentRows;

    return PageResult<AssetAssignment>(
      items: visible,
      currentPage: 1,
      lastPage: 1,
      perPage: visible.length,
      total: visible.length,
      hasNext: false,
    );
  }

  @override
  Future<AssetAssignment> findAssignment(int id) async {
    findAssignmentCalls++;

    for (final row in assignmentRows) {
      if (row.id == id) return row;
    }

    throw notFound404;
  }

  @override
  Future<List<AssetType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    typesCalls++;
    lastTypeQuery = query;

    final error = typesError;
    if (error != null) {
      typesError = null;
      throw error;
    }

    return typeRows.map(AssetType.fromJson).toList(growable: false);
  }

  Map<String, dynamic> payloadOf(int id) => payloads[id] ?? <String, dynamic>{};
}

/// Wraps a screen in the providers Phase 11 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repository.
///
/// The training, asset, employee, picker, camera and client-settings
/// providers are **always** overridden. Each is either a platform channel
/// (which a widget test would wait on forever, making the failure mode a
/// hang rather than an assertion) or an HTTP call nobody is behind to
/// answer — and several screens watch one of them the moment they mount,
/// so "override it in the test that needs it" is not a distinction that
/// survives contact with a picker field.
Widget scopedPhase11({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedTraining? training,
  ScriptedAssets? assets,
  ScriptedEmployees? employees,
  ScriptedDocumentPicker? picker,
  ScriptedCamera? camera,
  ScriptedClientSettings? clientSettings,
  PdfOpener? pdfOpener,
}) => ProviderScope(
  overrides: [
    permissionScopeProvider.overrideWithValue(
      PermissionScope(buildUser(permissions: permissions, roles: roles)),
    ),
    trainingRepositoryProvider.overrideWithValue(
      training ?? ScriptedTraining(),
    ),
    assetRepositoryProvider.overrideWithValue(assets ?? ScriptedAssets()),
    // Always overridden: the enrol form and the hand-over sheet both open
    // an employee picker, and an empty answer is a *correct* one here —
    // these tests are about the screen's own behaviour, not about the
    // directory. Two rows rather than none so a picker has something to
    // open instead of a search box that only ever answers "no results".
    employeesRepositoryProvider.overrideWithValue(
      employees ??
          ScriptedEmployees(items: employeeDirectory(), idOf: (e) => e.id),
    ),
    documentFilePickerProvider.overrideWithValue(
      picker ?? ScriptedDocumentPicker(),
    ),
    documentCameraProvider.overrideWithValue(camera ?? ScriptedCamera()),
    clientSettingsProvider.overrideWithValue(
      clientSettings ?? ScriptedClientSettings(),
    ),
    if (pdfOpener != null) pdfOpenerProvider.overrideWithValue(pdfOpener),
  ],
  child: child,
);

/// A router with enough of the app's real path table for a Phase 11 screen to
/// navigate the way it does in production.
GoRouter phase11Router(String initialLocation, List<GoRoute> routes) =>
    GoRouter(initialLocation: initialLocation, routes: routes);

/* ------------------------------------------------------------- fixtures */

/// One kind of training, as `TrainingTypeResource` shapes it.
Map<String, dynamic> trainingTypeRow({
  required int id,
  String code = 'WORKING_AT_HEIGHTS',
  String name = 'Working at heights',
  String? description = 'Heights work, harnesses and rescue.',
  String status = TrainingType.statusActive,
}) => <String, dynamic>{
  'id': id,
  'code': code,
  'name': name,
  'description': description,
  'status': status,
  'is_active': status == TrainingType.statusActive,
  'created_at': '2026-10-01T00:00:00Z',
  'updated_at': '2026-10-01T00:00:00Z',
};

/// One course, as `TrainingProgramResource` sends one.
///
/// The nested `training_type` is included by default because every screen
/// that draws a course draws its kind beside it — a builder that omitted it
/// would let a test pass on a payload the real API never sends.
Map<String, dynamic> trainingProgramRow({
  required int id,
  String code = 'WAH-101',
  String name = 'Working at heights',
  String? description = 'Harnesses, ladders and rescue.',
  String? provider = 'Gulf Safety Training',
  int? durationDays = 2,
  int typeId = 1,
  String typeCode = 'WORKING_AT_HEIGHTS',
  String typeName = 'Working at heights',
  bool certificateRequired = true,
  int? certificateValidityDays = 365,
  String status = TrainingProgram.statusActive,
  bool nestedType = true,
  String? createdAt = '2026-10-01T00:00:00Z',
}) => <String, dynamic>{
  'id': id,
  'training_type_id': typeId,
  if (nestedType)
    'training_type': trainingTypeRow(
      id: typeId,
      code: typeCode,
      name: typeName,
    ),
  'code': code,
  'name': name,
  'description': description,
  'provider': provider,
  'duration_days': durationDays,
  'certificate_required': certificateRequired,
  'certificate_validity_days': certificateValidityDays,
  'status': status,
  'is_active': status == TrainingProgram.statusActive,
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// One enrolment, as `EmployeeTrainingResource` sends one.
///
/// The four certificate states are all reachable from here on purpose:
/// `none`, `valid`, `expiring_soon` and `expired` read four different
/// sentences, and a builder that could only produce one would never show
/// that the screen keeps them apart.
Map<String, dynamic> employeeTrainingRow({
  int id = 1,
  int employeeId = 7,
  String employeeName = 'Anu Kmani',
  int programId = 1,
  Map<String, dynamic>? program,
  String? enrollmentDate = '2026-09-01',
  String? trainingDate = '2026-09-10',
  String? completionDate,
  String? trainer = 'Gulf Safety Training',
  String? programProvider = 'Gulf Safety Training',
  String status = EmployeeTraining.statusEnrolled,
  String? result,
  String? remarks,
  String? certificateNumber,
  String? certificateIssueDate,
  String? certificateExpiryDate,
  bool hasCertificate = false,
  String fileName = 'card.pdf',
  String mime = 'application/pdf',
  int fileSize = 102400,
  String expiryState = EmployeeTraining.expiryNone,
  int warningDays = 30,
  int? daysUntilExpiry,
  bool isEditable = true,
  String createdAt = '2026-09-01T08:00:00Z',
}) => <String, dynamic>{
  'id': id,
  'employee_id': employeeId,
  'employee': <String, dynamic>{
    'employee_code': 'EMP-0007',
    'name': employeeName,
  },
  'training_program_id': programId,
  'training_program':
      program ?? trainingProgramRow(id: programId, nestedType: true),
  'enrollment_date': enrollmentDate,
  'training_date': trainingDate,
  'completion_date': completionDate,
  'trainer': trainer,
  'program_provider': programProvider,
  'status': status,
  'result': result,
  'remarks': remarks,
  'certificate_number': certificateNumber,
  'certificate_issue_date': certificateIssueDate,
  'certificate_expiry_date': certificateExpiryDate,
  'has_certificate': hasCertificate,
  'certificate_original_name': hasCertificate ? fileName : null,
  'certificate_mime_type': hasCertificate ? mime : null,
  'certificate_size': hasCertificate ? fileSize : null,
  'certificate_file_url': hasCertificate
      ? '/api/v1/employee-training/$id/file'
      : null,
  'certificate_expiry_state': expiryState,
  'warning_days': warningDays,
  'days_until_expiry': daysUntilExpiry,
  'expiry_notified_at': null,
  'created_by': 3,
  'is_editable': isEditable,
  'is_terminal': const <String>[
    EmployeeTraining.statusCompleted,
    EmployeeTraining.statusFailed,
    EmployeeTraining.statusCancelled,
    EmployeeTraining.statusExpired,
  ].contains(status),
  'is_completable': !const <String>[
    EmployeeTraining.statusCompleted,
    EmployeeTraining.statusFailed,
    EmployeeTraining.statusCancelled,
    EmployeeTraining.statusExpired,
  ].contains(status),
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// The compliance answer, zero-filled the way the endpoint zero-fills it.
///
/// A builder rather than a constant because every test that draws the
/// report is asking a different question of the same four buckets.
Map<String, dynamic> trainingComplianceJson({
  String generatedAt = '2026-10-01T06:20:00Z',
  int warningDays = 30,
  List<Map<String, dynamic>> programs = const <Map<String, dynamic>>[],
  Map<String, int> byStatus = const <String, int>{},
  Map<String, int> certificates = const <String, int>{},
  int enrollments = 0,
  int certificatesTotal = 0,
}) => <String, dynamic>{
  'generated_at': generatedAt,
  'warning_days': warningDays,
  'programs': programs,
  'enrollments_by_status': <String, int>{
    for (final status in EmployeeTraining.statuses) status: 0,
    ...byStatus,
  },
  'certificates': <String, int>{
    EmployeeTraining.expiryValid: 0,
    EmployeeTraining.expirySoon: 0,
    EmployeeTraining.expiryExpired: 0,
    EmployeeTraining.expiryNone: 0,
    ...certificates,
  },
  'totals': <String, int>{
    'enrollments': enrollments,
    'certificates': certificatesTotal,
  },
};

/// One kind of company property, as `AssetTypeResource` shapes it.
Map<String, dynamic> assetTypeRow({
  required int id,
  String code = 'LAPTOP',
  String name = 'Laptop',
  String? description = 'Company computing kit.',
  String status = AssetType.statusActive,
}) => <String, dynamic>{
  'id': id,
  'code': code,
  'name': name,
  'description': description,
  'status': status,
  'is_active': status == AssetType.statusActive,
  'created_at': '2026-10-01T00:00:00Z',
  'updated_at': '2026-10-01T00:00:00Z',
};

/// One hand-over, as `AssetAssignmentResource` sends one.
Map<String, dynamic> assetAssignmentRow({
  required int id,
  int assetId = 1,
  int employeeId = 7,
  String employeeName = 'Anu Kmani',
  String? assignedDate = '2026-09-15',
  String? expectedReturnDate,
  String? returnedDate,
  String assignedCondition = Asset.conditionGood,
  String? returnedCondition,
  int? assignedBy = 3,
  int? returnedBy,
  String status = AssetAssignment.statusActive,
  String? remarks,
  int? daysOut,
  bool? isOverdue,
  String createdAt = '2026-09-15T09:00:00Z',
  bool nestedAsset = false,
}) => <String, dynamic>{
  'id': id,
  'asset_id': assetId,
  if (nestedAsset) 'asset': assetRow(id: assetId),
  'employee_id': employeeId,
  'employee': <String, dynamic>{
    'employee_code': 'EMP-0007',
    'name': employeeName,
  },
  'assigned_date': assignedDate,
  'expected_return_date': expectedReturnDate,
  'returned_date': returnedDate,
  'assigned_condition': assignedCondition,
  'returned_condition': returnedCondition,
  'assigned_by': assignedBy,
  'returned_by': returnedBy,
  'status': status,
  'remarks': remarks,
  'days_out': daysOut,
  'is_overdue': isOverdue ?? false,
  'is_active': status == AssetAssignment.statusActive,
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// One asset, as `AssetResource` sends one.
///
/// `purchase_cost` is **omitted** unless [cost] is passed, matching the
/// server's behaviour rather than sending a `null`: a payload without the
/// key and a payload whose asset was free are two different statements, and
/// the detail screen records which arrived.
Map<String, dynamic> assetRow({
  int id = 1,
  String assetCode = 'TOOL-00042',
  int typeId = 1,
  String typeCode = 'LAPTOP',
  String typeName = 'Laptop',
  String name = 'Dell Latitude 5540',
  String? description = 'Issued to the site office.',
  String? serialNumber = 'SN-1234',
  String? manufacturer = 'Dell',
  String? model = 'Latitude 5540',
  String? purchaseDate = '2026-01-15',
  String? cost,
  String currentCondition = Asset.conditionGood,
  String status = Asset.statusAvailable,
  String? notes,
  bool hasHolder = false,
  String holderName = 'Anu Kmani',
  Map<String, dynamic>? currentAssignment,
  List<Map<String, dynamic>>? assignments,
  bool isAssignable = true,
  bool isRetired = false,
  String createdAt = '2026-01-15T09:00:00Z',
}) => <String, dynamic>{
  'id': id,
  'asset_code': assetCode,
  'asset_type_id': typeId,
  'asset_type': assetTypeRow(id: typeId, code: typeCode, name: typeName),
  'name': name,
  'description': description,
  'serial_number': serialNumber,
  'manufacturer': manufacturer,
  'model': model,
  'purchase_date': purchaseDate,
  'purchase_cost': ?cost,
  'current_condition': currentCondition,
  'status': status,
  'notes': notes,
  'current_assignment': currentAssignment,
  'current_holder': hasHolder
      ? <String, dynamic>{'employee_code': 'EMP-0007', 'name': holderName}
      : null,
  'assignments': assignments,
  'is_assignable': isAssignable,
  'is_retired': isRetired,
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// One person, as `EmployeeResource` sends one — the shape the employee
/// picker reads.
Map<String, dynamic> employeeRow({
  int id = 7,
  String code = 'EMP-0007',
  String name = 'Anu Kmani',
  String status = 'active',
  String createdAt = '2026-01-05T08:00:00Z',
}) => <String, dynamic>{
  'id': id,
  'employee_code': code,
  'full_name': name,
  'employment_status': status,
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// The directory behind the pickers on the enrol form and the hand-over
/// sheet.
List<Employee> employeeDirectory() => <Employee>[
  Employee.fromJson(employeeRow()),
  Employee.fromJson(employeeRow(id: 8, code: 'EMP-0008', name: 'Meera Nair')),
  Employee.fromJson(employeeRow(id: 9, code: 'EMP-0009', name: 'Ravi Varma')),
];
