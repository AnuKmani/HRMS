import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';
import 'package:mobile/features/documents/data/api_document_repository.dart';
import 'package:mobile/features/documents/data/document_source.dart';
import 'package:mobile/features/documents/domain/document_repository.dart';
import 'package:mobile/features/documents/domain/document_type.dart';
import 'package:mobile/features/documents/domain/employee_document.dart';
import 'package:mobile/core/config/client_settings.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/features/employees/data/api_employees_repository.dart';
import 'package:mobile/features/onboarding/data/api_onboarding_repository.dart';
import 'package:mobile/features/onboarding/domain/onboarding.dart';
import 'package:mobile/features/onboarding/domain/onboarding_repository.dart';

import 'attendance.dart' show ScriptedCamera;
import 'fakes.dart' show buildUser;
import 'phase4.dart';
import 'phase9.dart' show ScriptedClientSettings;

export 'phase4.dart'
    show advance, useTallScreen, forbidden403, notFound404, unreachable;
export 'phase8.dart' show RecordingPdfOpener;

/// The documents repository, scripted.
///
/// **Rows arrive as JSON and are parsed**, rather than being built straight
/// from the model: the payloads *are* `EmployeeDocumentResource`, so a test
/// that shows a screen is also a test that the screen can read what the API
/// actually sends. It also lets a transition move a field — `verify` turns
/// `pending` into `valid` — by merging into the payload and re-parsing, the
/// way the server would, instead of the double inventing an answer the model
/// has no way to express.
class ScriptedDocuments extends Scripted<EmployeeDocument>
    implements DocumentRepository {
  /// `fallback` is deliberately absent: every answer this double gives is
  /// derived from a payload, and a staged row with no payload behind it would
  /// be a shape no endpoint ever sent.
  ScriptedDocuments({List<EmployeeDocument> items = const <EmployeeDocument>[]})
    : super(items: items, idOf: (document) => document.id);

  /// Parses [rows] into a double that can also move them.
  factory ScriptedDocuments.rows(List<Map<String, dynamic>> rows) {
    final script = ScriptedDocuments(
      items: rows.map(EmployeeDocument.fromJson).toList(growable: false),
    );

    script.payloads = <int, Map<String, dynamic>>{
      for (final row in rows)
        if (row['id'] is int) row['id']! as int: row,
    };

    return script;
  }

  /// The resource payload behind each row, keyed by id.
  Map<int, Map<String, dynamic>> payloads = <int, Map<String, dynamic>>{};

  /// `GET /employee-documents/expiring` — a *different pool* from the
  /// directory above. One list for both would make it impossible to show
  /// that the report screen asked for the report.
  List<EmployeeDocument> expiringRows = <EmployeeDocument>[];

  /// The document-type vocabulary, as JSON for the same reason as [payloads].
  List<Map<String, dynamic>> typeRows = <Map<String, dynamic>>[];

  Object? expiringError;
  Object? fileError;
  Object? verifyError;
  Object? rejectError;
  Object? archiveError;
  Object? typesError;

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

  int expiringCalls = 0;
  int fileCalls = 0;
  int verifyCalls = 0;
  int rejectCalls = 0;
  int archiveCalls = 0;
  int typesCalls = 0;

  int? lastFileId;
  String? lastRejectReason;

  /// The bytes and name the last `create`/`update` travelled with — the two
  /// facts an upload test cannot read off `lastBody`, because the form sends
  /// them beside the fields rather than inside them.
  Uint8List? lastFile;
  String? lastFilename;

  /// The last `query` the expiry report was asked with, so a test can show
  /// the window travelled rather than being applied in the browser.
  Map<String, Object?>? lastExpiringQuery;

  /// Ids handed out by [create], above any already scripted.
  int _nextId = 9000;

  @override
  Future<PageResult<EmployeeDocument>> expiring({
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

    return PageResult<EmployeeDocument>(
      items: expiringRows,
      currentPage: 1,
      lastPage: 1,
      perPage: total,
      total: total,
      hasNext: false,
    );
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

  /// The two verbs `Scripted` cannot answer: their contracts take a file the
  /// generic double knows nothing about, and dropping it would let a form
  /// "succeed" without ever having chosen one.
  @override
  Future<EmployeeDocument> create(
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) async {
    createCalls++;
    lastBody = body;
    lastFile = file;
    lastFilename = filename;

    final error = saveError;
    if (error != null) {
      saveError = null;
      throw error;
    }

    final id = ++_nextId;
    final payload = <String, dynamic>{
      ...documentRow(id: id),
      ...body,
      'id': id,
      // Uploading never arrives verified — the server's rule, mirrored so a
      // screen asserting the fresh row shows `pending` is asserting the
      // thing the API would actually send.
      'status': EmployeeDocument.statusPending,
      'verified_at': null,
      'rejection_reason': null,
      'has_file': file != null,
      'original_name': filename,
      'file_size': file?.lengthInBytes,
      'expiry_state': EmployeeDocument.expiryNone,
      'days_until_expiry': null,
    };

    payloads[id] = payload;
    items.add(EmployeeDocument.fromJson(payload));

    return EmployeeDocument.fromJson(payload);
  }

  @override
  Future<EmployeeDocument> update(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  }) async {
    updateCalls++;
    lastId = id;
    lastBody = body;
    lastFile = file;
    lastFilename = filename;

    final error = saveError;
    if (error != null) {
      saveError = null;
      throw error;
    }

    return _moved(id, <String, dynamic>{
      ...body,
      if (file != null) ...<String, dynamic>{
        'has_file': true,
        'original_name': filename,
        'file_size': file.lengthInBytes,
      },
      // Changing the file or any identifying field puts the row back to
      // pending and clears the sign-off, which is the server's rule rather
      // than a screen's optimistic guess.
      'status': EmployeeDocument.statusPending,
      'verified_at': null,
    });
  }

  @override
  Future<EmployeeDocument> verify(int id) async {
    verifyCalls++;

    final error = verifyError;
    if (error != null) {
      verifyError = null;
      throw error;
    }

    return _moved(id, <String, dynamic>{
      'status': EmployeeDocument.statusValid,
      'verified_at': '2026-10-01T09:00:00Z',
      'rejection_reason': null,
    });
  }

  @override
  Future<EmployeeDocument> reject(int id, {required String reason}) async {
    rejectCalls++;
    lastRejectReason = reason;

    final error = rejectError;
    if (error != null) {
      rejectError = null;
      throw error;
    }

    return _moved(id, <String, dynamic>{
      'status': EmployeeDocument.statusRejected,
      'rejection_reason': reason,
      'verified_at': null,
    });
  }

  @override
  Future<EmployeeDocument> archive(int id) async {
    archiveCalls++;

    final error = archiveError;
    if (error != null) {
      archiveError = null;
      throw error;
    }

    return _moved(id, <String, dynamic>{
      'status': EmployeeDocument.statusArchived,
      'archived_at': '2026-10-01T09:05:00Z',
    });
  }

  @override
  Future<List<DocumentType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    typesCalls++;

    final error = typesError;
    if (error != null) {
      typesError = null;
      throw error;
    }

    return typeRows.map(DocumentType.fromJson).toList(growable: false);
  }

  /// Applies [patch] to the payload behind [id] and re-parses it, keeping the
  /// pool in step so a later `find` agrees with what the transition said.
  ///
  /// A row with no payload behind it — one handed over as a model rather than
  /// as the resource the API sends — answers with itself. Recording the call
  /// is the part those tests assert on; a `StateError` here would fail a test
  /// about *permissions* for want of a fixture.
  EmployeeDocument _moved(int id, Map<String, dynamic> patch) {
    final row = payloads[id];
    final index = items.indexWhere((document) => document.id == id);

    if (row == null) {
      return index == -1 ? _unstaged(id) : items[index];
    }

    final merged = <String, dynamic>{...row, ...patch};
    final moved = EmployeeDocument.fromJson(merged);

    payloads[id] = merged;
    if (index != -1) items[index] = moved;

    return moved;
  }

  /// The row a transition was asked about when nothing scripted its payload.
  EmployeeDocument _unstaged(int id) => items.isEmpty
      ? EmployeeDocument.fromJson(documentRow(id: id))
      : items.first;

  /// Records the payloads behind a type list, so the upload form can be
  /// shown with — and without — a preselection.
  void scriptTypes(List<Map<String, dynamic>> rows) => typeRows = rows;
}

/// The onboarding repository, scripted.
///
/// Same payload-first rule as [ScriptedDocuments], for the same reason: the
/// checklist is computed by the server and handed over, so a test that
/// renders it is a test that the client can read `GET /onboarding/{employee}`
/// rather than a test of an invented shape.
class ScriptedOnboarding extends Scripted<Onboarding>
    implements OnboardingRepository {
  /// `fallback` is absent for the same reason as in [ScriptedDocuments].
  ScriptedOnboarding({List<Onboarding> items = const <Onboarding>[]})
    : super(items: items, idOf: (record) => record.employeeId);

  factory ScriptedOnboarding.rows(List<Map<String, dynamic>> rows) {
    final script = ScriptedOnboarding(
      items: rows.map(Onboarding.fromJson).toList(growable: false),
    );

    script.payloads = <int, Map<String, dynamic>>{
      for (final row in rows)
        if (row['employee_id'] is int) row['employee_id']! as int: row,
    };

    return script;
  }

  Map<int, Map<String, dynamic>> payloads = <int, Map<String, dynamic>>{};

  int completeCalls = 0;

  Object? completeError;

  /// The message a 409 would carry — "three requirements are outstanding".
  /// Scripted rather than built, because the *wording is the point*: the
  /// screen shows the server's sentence verbatim, and a double that threw a
  /// generic error could not prove that.
  String incompleteMessage =
      'Onboarding cannot be completed: passport, emirates_id, bank_information.';

  int? lastCompleteId;

  @override
  Future<Onboarding> find(int employeeId) async {
    findCalls++;
    lastId = employeeId;

    final error = findError;
    if (error != null) {
      findError = null;
      throw error;
    }

    for (final record in items) {
      if (record.employeeId == employeeId) return record;
    }

    throw notFound404;
  }

  @override
  Future<Onboarding> update(int employeeId, Map<String, Object?> body) async {
    updateCalls++;
    lastId = employeeId;
    lastBody = body;

    final error = saveError;
    if (error != null) {
      saveError = null;
      throw error;
    }

    return _merged(employeeId, body);
  }

  @override
  Future<Onboarding> complete(int employeeId) async {
    completeCalls++;
    lastCompleteId = employeeId;

    final error = completeError;
    if (error != null) {
      completeError = null;
      throw error;
    }

    return _merged(employeeId, <String, Object?>{
      'status': Onboarding.statusCompleted,
      'completed_at': '2026-10-01T09:30:00Z',
      'is_completed': true,
      'can_complete': true,
    });
  }

  Onboarding _merged(int employeeId, Map<String, Object?> body) {
    final row = payloads[employeeId];

    if (row == null) {
      // No payload: fall back to the record as scripted, with the fields the
      // body actually named applied. A test that only cares *what was sent*
      // should not have to also stage a response.
      final existing = items.cast<Onboarding?>().firstWhere(
        (record) => record?.employeeId == employeeId,
        orElse: () => null,
      );

      if (existing == null) throw notFound404;

      return Onboarding.fromJson(<String, dynamic>{
        ..._toJson(existing),
        ...body,
      });
    }

    final merged = <String, dynamic>{...row, ...body};
    final record = Onboarding.fromJson(merged);

    payloads[employeeId] = merged;

    final index = items.indexWhere((item) => item.employeeId == employeeId);
    if (index != -1) items[index] = record;

    return record;
  }

  static Map<String, dynamic> _toJson(Onboarding record) => <String, dynamic>{
    'employee_id': record.employeeId,
    'employee': <String, dynamic>{'full_name': record.employeeName},
    'status': record.status,
    'exists': record.exists,
    'is_completed': record.isCompleted,
    'started_at': record.startedAt,
    'completed_at': record.completedAt,
    'notes': record.notes,
    if (record.hasChecklist) ...{
      'requirements': <dynamic>[],
      'totals': <String, dynamic>{
        'total': record.total,
        'satisfied': record.satisfied,
      },
      'missing_requirements': record.missingRequirements,
      'can_complete': record.canComplete,
    },
  };
}

/// The gallery/PDF picker, scripted.
///
/// Without this, `FilePicker.pickFiles` is a platform channel nobody answers
/// and every upload test hangs on a dialog. The camera is *not* scripted
/// here — `ScriptedCamera` already is, and a second double for one sheet
/// would be the duplicate picker scope item T rules out.
class ScriptedDocumentPicker implements DocumentFilePicker {
  /// What the next pick returns; null means the person backed out.
  PickedDocument? next;

  Object? error;

  int imageCalls = 0;
  int pdfCalls = 0;

  @override
  Future<PickedDocument?> pickImage() async {
    imageCalls++;
    return _answer();
  }

  @override
  Future<PickedDocument?> pickPdf() async {
    pdfCalls++;
    return _answer();
  }

  PickedDocument? _answer() {
    final failure = error;
    if (failure != null) {
      error = null;
      throw failure;
    }

    return next;
  }
}

/// Wraps a screen in the providers Phase 10 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repository.
///
/// The camera and the file picker are **always** overridden. Both are
/// platform channels, and a widget test that reached either would wait on a
/// dialog nobody answers — the failure mode is a hang, not an assertion, so
/// there is no version of "override it in the test that needs it" that fails
/// safely in the test that forgot.
Widget scopedPhase10({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedDocuments? documents,
  ScriptedOnboarding? onboarding,
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
    if (documents != null)
      documentRepositoryProvider.overrideWithValue(documents),
    if (onboarding != null)
      onboardingRepositoryProvider.overrideWithValue(onboarding),
    if (employees != null)
      employeesRepositoryProvider.overrideWithValue(employees),
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

/// A router with enough of the app's real path table for a Phase 10 screen to
/// navigate the way it does in production.
GoRouter phase10Router(String initialLocation, List<GoRoute> routes) =>
    GoRouter(initialLocation: initialLocation, routes: routes);

/* ------------------------------------------------------------- fixtures */

/// One document type, as `DocumentTypeResource` shapes it.
///
/// The three `requires_*` flags are the whole reason this table exists in
/// the payload: a form that did not receive them would have to guess which
/// fields are mandatory, and a guess would eventually disagree with the
/// server's `after()` check on some deployment that configured its own types.
Map<String, dynamic> documentTypeRow({
  required int id,
  required String name,
  required String code,
  String? description,
  bool requiresNumber = false,
  bool requiresIssue = false,
  bool requiresExpiry = false,
  int warningDays = 0,
  String status = 'active',
  int sortOrder = 0,
}) => <String, dynamic>{
  'id': id,
  'name': name,
  'code': code,
  'description': description,
  'requires_document_number': requiresNumber,
  'requires_issue_date': requiresIssue,
  'requires_expiry_date': requiresExpiry,
  'expiry_warning_days': warningDays,
  'status': status,
  'sort_order': sortOrder,
  'created_at': '2026-09-01T00:00:00Z',
  'updated_at': '2026-09-01T00:00:00Z',
};

/// One document, as `EmployeeDocumentResource` sends one.
///
/// A builder rather than a constant because what makes a row interesting is
/// exactly what varies between the tests: whether it has a file, how far away
/// the expiry is, whether somebody has already signed it off.
Map<String, dynamic> documentRow({
  int id = 1,
  int employeeId = 7,
  String employeeName = 'Anu Kmani',
  int typeId = 1,
  String typeCode = 'PASSPORT',
  String typeName = 'Passport',
  bool requiresNumber = true,
  bool requiresIssue = true,
  bool requiresExpiry = true,
  int warningDays = 180,
  String? number = 'N1234567',
  String? issue = '2021-04-01',
  String? expiry,
  int? daysUntil,
  String expiryState = EmployeeDocument.expiryNone,
  String status = EmployeeDocument.statusPending,
  bool hasFile = true,
  String fileName = 'passport-scan.jpg',
  int fileSize = 245760,
  String? mime = 'image/jpeg',
  String? notes,
  String? rejectionReason,
  String? verifiedAt,
  String? archivedAt,
  String createdAt = '2026-09-28T08:00:00Z',
  bool isEditable = true,
}) => <String, dynamic>{
  'id': id,
  'employee_id': employeeId,
  'employee': <String, dynamic>{'full_name': employeeName},
  'document_type_id': typeId,
  'document_type': documentTypeRow(
    id: typeId,
    name: typeName,
    code: typeCode,
    requiresNumber: requiresNumber,
    requiresIssue: requiresIssue,
    requiresExpiry: requiresExpiry,
    warningDays: warningDays,
  ),
  'document_number': number,
  'issue_date': issue,
  'expiry_date': expiry,
  'notes': notes,
  'has_file': hasFile,
  'original_name': hasFile ? fileName : null,
  'mime_type': hasFile ? mime : null,
  'file_size': hasFile ? fileSize : null,
  'file_url': hasFile ? '/api/v1/employee-documents/$id/file' : null,
  'status': status,
  'expiry_state': expiryState,
  'warning_days': warningDays,
  'days_until_expiry': daysUntil,
  'expiry_notified_at': null,
  'rejection_reason': rejectionReason,
  'uploaded_by': 7,
  'verified_at': verifiedAt,
  'verified_by': verifiedAt == null ? null : 3,
  'archived_at': archivedAt,
  'is_editable': isEditable,
  'is_pending': status == EmployeeDocument.statusPending,
  'created_at': createdAt,
  'updated_at': createdAt,
};

/// One line of the checklist, as `OnboardingChecklistItemResource` shapes it.
///
/// The five states are all reachable from this builder on purpose: every one
/// of them tells a different person a different thing, and a fixture that
/// could only produce two would never show that the screen keeps them apart.
Map<String, dynamic> checklistItem({
  required String code,
  required String name,
  String? description,
  String kind = OnboardingChecklistItem.kindDocument,
  bool mandatory = true,
  int sortOrder = 10,
  String state = OnboardingChecklistItem.stateMissing,
  List<String> missingFields = const <String>[],
  Map<String, dynamic>? document,
}) => <String, dynamic>{
  'code': code,
  'name': name,
  'description': description,
  'kind': kind,
  'is_mandatory': mandatory,
  'sort_order': sortOrder,
  'state': state,
  'missing_fields': missingFields,
  'document': document,
};

/// Where one employee stands, as `OnboardingResource` sends one.
Map<String, dynamic> onboardingRow({
  required int employeeId,
  String name = 'Anu Kmani',
  String code = 'EMP-0007',
  String status = Onboarding.statusDraft,
  bool exists = true,
  String? notes,
  String? startedAt,
  String? completedAt,
  List<Map<String, dynamic>> requirements = const <Map<String, dynamic>>[],
  List<String> missing = const <String>[],
  int? total,
  int? satisfied,
  bool canComplete = false,
}) => <String, dynamic>{
  'employee_id': employeeId,
  'employee': <String, dynamic>{'full_name': name, 'employee_code': code},
  'status': status,
  'exists': exists,
  'is_completed': status == Onboarding.statusCompleted,
  'started_at': startedAt,
  'completed_at': completedAt,
  'completed_by': completedAt == null ? null : 3,
  'notes': notes,
  'created_at': exists ? '2026-09-28T08:00:00Z' : null,
  'updated_at': exists ? '2026-09-28T08:00:00Z' : null,
  if (requirements.isNotEmpty || total != null) ...{
    'requirements': requirements,
    'missing_requirements': missing,
    'totals': <String, dynamic>{
      'total': total ?? requirements.length,
      'satisfied': satisfied ?? requirements.length,
    },
    'can_complete': canComplete,
  },
};

/// Bytes for an upload, with an extension a server's `mimes` rule would read.
PickedDocument pickedDocument({
  String filename = 'scan.jpg',
  List<int>? bytes,
}) => PickedDocument(
  bytes: Uint8List.fromList(
    bytes ?? const <int>[255, 216, 255, 224, 0, 16, 74, 70, 73, 70, 0],
  ),
  filename: filename,
);

/// Too large for `hrms.storage.document_max_kilobytes` (10 MB).
PickedDocument oversizedDocument() => PickedDocument(
  bytes: Uint8List(10 * 1024 * 1024 + 1),
  filename: 'huge-scan.jpg',
);
