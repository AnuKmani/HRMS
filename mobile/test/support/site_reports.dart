import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/attendance/data/device_location.dart';
import 'package:mobile/features/sites/domain/site.dart';
import 'package:mobile/features/site_reports/data/api_daily_site_report_repository.dart';
import 'package:mobile/features/site_reports/data/api_site_activity_repository.dart';
import 'package:mobile/features/site_reports/data/report_draft_store.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report_repository.dart';
import 'package:mobile/features/site_reports/domain/site_activity_report.dart';
import 'package:mobile/features/site_reports/domain/site_activity_repository.dart';
import 'package:mobile/features/site_reports/domain/site_report_photo.dart';
import 'package:mobile/features/site_reports/presentation/report_pdf_opener.dart';
import 'package:mobile/features/site_reports/presentation/report_photo_source.dart';

import 'attendance.dart';
import 'fakes.dart';
import 'phase4.dart';
import 'phase6.dart';

export 'phase4.dart'
    show advance, forbidden403, notFound404, unreachable, useTallScreen;
export 'phase6.dart' show expectQuery, pageOf, phase6Router;

/* --------------------------------------------------------------- fixtures */

/// A site the picker may offer, with the project the server will check the
/// report against.
Site testSite({
  int id = 1,
  String name = 'Block A',
  int projectId = 10,
  String project = 'Riverfront Towers',
}) => Site.fromJson(<String, dynamic>{
  'id': id,
  'name': name,
  'code': 'S$id',
  'project_id': projectId,
  'project': <String, dynamic>{'name': project},
  'status': 'active',
});

/// One photograph, as the resource emits it: ids and sizes, and never a path
/// or a URL — those are what this model exists to avoid representing.
SiteReportPhoto testPhoto({int id = 901, int sortOrder = 0}) => SiteReportPhoto(
  id: id,
  sortOrder: sortOrder,
  mimeType: 'image/jpeg',
  sizeBytes: 1024,
  createdAt: '2026-09-28 10:00:00',
);

SiteActivityReport testActivityReport({
  int id = 1,
  String status = SiteActivityReport.statusDraft,
  String date = '2026-09-28',
  bool editable = true,
  bool hasGps = false,
  List<SiteReportPhoto> photos = const <SiteReportPhoto>[],
  int photoCount = 0,
}) => SiteActivityReport.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': 1,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'project_id': 10,
  'project': <String, dynamic>{'name': 'Riverfront Towers'},
  'site_id': 1,
  'site': <String, dynamic>{'name': 'Block A'},
  'report_date': date,
  'work_category': 'RCC',
  'work_performed': 'Slab pour on the third floor.',
  'progress_percentage': 60,
  'manpower': '12 masons, 8 helpers',
  'status': status,
  'is_draft': status == SiteActivityReport.statusDraft,
  // The server never calls a filed report editable — the two are opposites
  // there — so the fixture models both together rather than letting a test
  // assemble a combination the API will not send.
  'is_editable': editable && status == SiteActivityReport.statusDraft,
  'has_gps_fix': hasGps,
  if (hasGps) ...<String, Object?>{
    'latitude': 12.9716,
    'longitude': 77.5946,
    'gps_accuracy': 8.5,
  },
  'photo_count': photoCount,
  if (photos.isNotEmpty) 'photos': [for (final p in photos) _photoJson(p)],
  'submitted_at': status == SiteActivityReport.statusSubmitted
      ? '2026-09-28 18:05:00'
      : null,
});

Map<String, dynamic> _photoJson(SiteReportPhoto photo) => <String, dynamic>{
  'id': photo.id,
  'caption': photo.caption,
  'sort_order': photo.sortOrder,
  'mime_type': photo.mimeType,
  'size_bytes': photo.sizeBytes,
  'created_at': photo.createdAt,
};

DailySiteReport testDailyReport({
  int id = 1,
  String status = DailySiteReport.statusDraft,
  String date = '2026-09-28',
  bool editable = true,
  List<ManpowerRow> manpower = const <ManpowerRow>[
    ManpowerRow(category: 'Masons', count: 12),
    ManpowerRow(category: 'Helpers', count: 6),
  ],
  List<MaterialRow> materials = const <MaterialRow>[],
  List<EquipmentRow> equipment = const <EquipmentRow>[],
  List<SiteReportPhoto> photos = const <SiteReportPhoto>[],
}) => DailySiteReport.fromJson(<String, dynamic>{
  'id': id,
  'created_by': 1,
  'creator': <String, dynamic>{'full_name': 'Anu Kmani'},
  'project_id': 10,
  'project': <String, dynamic>{'name': 'Riverfront Towers'},
  'site_id': 1,
  'site': <String, dynamic>{'name': 'Block A'},
  'report_date': date,
  'total_manpower': manpower.fold<int>(0, (sum, row) => sum + row.count),
  'work_planned': 'Slab shuttering on the third floor.',
  'work_completed': 'Shuttering finished up to grid C.',
  'safety_observations': 'Two harnesses reissued.',
  'status': status,
  'is_draft': status == DailySiteReport.statusDraft,
  // As on the activity fixture: a filed document is never editable.
  'is_editable': editable && status == DailySiteReport.statusDraft,
  'submitted_at': status == DailySiteReport.statusSubmitted
      ? '2026-09-28 18:30:00'
      : null,
  'manpower': [for (final row in manpower) _manpowerJson(row)],
  'materials': [for (final row in materials) _materialJson(row)],
  'equipment': [for (final row in equipment) _equipmentJson(row)],
  if (photos.isNotEmpty) 'photos': [for (final p in photos) _photoJson(p)],
});

Map<String, dynamic> _manpowerJson(ManpowerRow row) => <String, dynamic>{
  'category': row.category,
  'count': row.count,
  'sort_order': row.sortOrder,
};

Map<String, dynamic> _materialJson(MaterialRow row) => <String, dynamic>{
  'material_name': row.name,
  'quantity': row.quantity,
  'unit': row.unit,
  'remarks': row.remarks,
  'sort_order': row.sortOrder,
};

Map<String, dynamic> _equipmentJson(EquipmentRow row) => <String, dynamic>{
  'equipment_name': row.name,
  'quantity': row.quantity,
  'operating_hours': row.operatingHours,
  'condition': row.condition,
  'remarks': row.remarks,
  'sort_order': row.sortOrder,
};

/* ----------------------------------------------------------- repositories */

/// The activity repository, scripted.
///
/// The four verbs that carry *state* — submit, add photos, remove photo —
/// record what they were given rather than modelling what the server would
/// do with it. A screen test is asking "did pressing submit carry the fix it
/// read off the field?", and a double that enforced the server's own rules
/// would only be re-testing them in Dart.
class ScriptedSiteActivityReports extends Scripted<SiteActivityReport>
    implements SiteActivityRepository {
  ScriptedSiteActivityReports({
    super.items,
    required super.idOf,
    super.fallback,
    List<Site>? reportable,
  }) : reportable = List<Site>.of(reportable ?? <Site>[testSite()]);

  /// The pool `reportable-sites` pages over — a different collection from
  /// [items], exactly as it is on the server.
  List<Site> reportable;

  int submitCalls = 0;
  int? lastSubmitId;
  double? lastLatitude;
  double? lastLongitude;
  double? lastAccuracy;
  Object? submitError;

  int addPhotosCalls = 0;
  int? lastPhotosFor;
  int? lastPhotoCount;
  List<Uint8List>? lastPhotos;
  List<SiteReportPhoto> uploadedPhotos = <SiteReportPhoto>[testPhoto()];
  Object? photosError;

  int removePhotoCalls = 0;
  int? lastRemovedPhotoId;
  Object? removePhotoError;

  @override
  Future<SiteActivityReport> submit(
    int id, {
    required double latitude,
    required double longitude,
    required double accuracy,
  }) async {
    submitCalls++;
    lastSubmitId = id;
    lastLatitude = latitude;
    lastLongitude = longitude;
    lastAccuracy = accuracy;

    final error = submitError;
    if (error != null) {
      submitError = null;
      throw error;
    }

    return super.find(id);
  }

  @override
  Future<List<SiteReportPhoto>> addPhotos(
    int reportId,
    List<Uint8List> photos, {
    String? caption,
  }) async {
    addPhotosCalls++;
    lastPhotosFor = reportId;
    lastPhotoCount = photos.length;
    lastPhotos = photos;

    final error = photosError;
    if (error != null) {
      photosError = null;
      throw error;
    }

    return uploadedPhotos;
  }

  @override
  Future<void> removePhoto(int reportId, int photoId) async {
    removePhotoCalls++;
    lastRemovedPhotoId = photoId;

    final error = removePhotoError;
    if (error != null) {
      removePhotoError = null;
      throw error;
    }
  }

  @override
  Future<PageResult<Site>> reportableSites({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async => pageOf(reportable, page, pageSize: 100);
}

/// The daily report repository, scripted — plus the PDF, which is the one
/// method here with a *side effect on the device* rather than on the server:
/// it writes a file and asks the operating system to open it, and neither
/// half exists in a widget test.
class ScriptedDailySiteReports extends Scripted<DailySiteReport>
    implements DailySiteReportRepository {
  ScriptedDailySiteReports({super.items, required super.idOf, super.fallback});

  int submitCalls = 0;
  int? lastSubmitId;
  Object? submitError;

  int pdfCalls = 0;
  int? lastPdfId;
  Uint8List? pdfBytes;
  Object? pdfError;

  int addPhotosCalls = 0;
  int? lastPhotosFor;
  int? lastPhotoCount;
  List<Uint8List>? lastPhotos;
  List<SiteReportPhoto> uploadedPhotos = <SiteReportPhoto>[testPhoto()];
  Object? photosError;

  int removePhotoCalls = 0;
  int? lastRemovedPhotoId;
  Object? removePhotoError;

  @override
  Future<DailySiteReport> submit(int id) async {
    submitCalls++;
    lastSubmitId = id;

    final error = submitError;
    if (error != null) {
      submitError = null;
      throw error;
    }

    return super.find(id);
  }

  @override
  Future<List<SiteReportPhoto>> addPhotos(
    int reportId,
    List<Uint8List> photos, {
    String? caption,
  }) async {
    addPhotosCalls++;
    lastPhotosFor = reportId;
    lastPhotoCount = photos.length;
    lastPhotos = photos;

    final error = photosError;
    if (error != null) {
      photosError = null;
      throw error;
    }

    return uploadedPhotos;
  }

  @override
  Future<void> removePhoto(int reportId, int photoId) async {
    removePhotoCalls++;
    lastRemovedPhotoId = photoId;

    final error = removePhotoError;
    if (error != null) {
      removePhotoError = null;
      throw error;
    }
  }

  @override
  Future<Uint8List> pdf(int id) async {
    pdfCalls++;
    lastPdfId = id;

    final error = pdfError;
    if (error != null) throw error;

    return pdfBytes ?? Uint8List.fromList(<int>[37, 80, 68, 70]);
  }
}

/* ------------------------------------------------------------ other fakes */

/// Drafts, in memory and immediately visible.
///
/// The production store debounces by 600 ms before it touches
/// `SharedPreferences`; this one does not, because the behaviour under test
/// is *whether the screen wrote a draft at all*, and a test that had to wait
/// out a timer to learn that would be testing the timer instead. The timer
/// itself is exercised by advancing the fake clock — `advance()` already
/// pumps far enough for it to fire.
class MemoryReportDraftStore implements ReportDraftStore {
  final Map<String, Map<String, Object?>> slots =
      <String, Map<String, Object?>>{};

  int writeCalls = 0;
  int clearCalls = 0;
  String? lastSlot;
  Map<String, Object?>? lastDraft;

  @override
  Future<Map<String, Object?>?> read(String slot) async => slots[slot];

  @override
  Future<void> write(String slot, Map<String, Object?> draft) async {
    writeCalls++;
    lastSlot = slot;
    lastDraft = draft;
    slots[slot] = draft;
  }

  @override
  Future<void> clear(String slot) async {
    clearCalls++;
    slots.remove(slot);
  }

  @override
  Future<void> flush() async {}
}

/// Report photographs, served from a constant 1×1 PNG.
///
/// Bytes rather than a URL, and always overridden: the real source goes
/// through `ApiClient`, and a widget test that let a request reach it would
/// hang on a fake-async clock rather than fail on the assertion.
class FakeReportPhotoSource implements ReportPhotoSource {
  final List<String> fetched = <String>[];

  Object? error;
  Uint8List? reply;

  @override
  Future<Uint8List> fetch(String path) async {
    fetched.add(path);

    final failure = error;
    if (failure != null) {
      error = null;
      throw failure;
    }

    return reply ?? pngBytes;
  }
}

/// The PDF opener, recorded.
///
/// Nothing is downloaded and no file is written: what the screen owes a
/// person is *telling* them what happened, and that is the same whether the
/// bytes came from a server or from here.
class FakeReportPdfOpener implements ReportPdfOpener {
  int calls = 0;
  int? lastId;
  String? lastFilename;
  Object? error;

  /// When set, `open` parks until the test completes it — the only honest
  /// way to assert on a *loading* state, since a double that answers
  /// immediately never renders one.
  Completer<void>? hold;

  @override
  Future<void> open(int id, {String? filename}) async {
    calls++;
    lastId = id;
    lastFilename = filename;

    final gate = hold;
    if (gate != null) await gate.future;

    final failure = error;
    if (failure != null) throw failure;
  }
}

/// A 1×1 PNG. A real image, so `Image.memory` in a thumbnail or a review
/// frame decodes instead of reporting invalid data for the wrong reason.
final Uint8List pngBytes = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhf'
  'DwAChwGA60e6kgAAAABJRU5ErkJggg==',
);

/* ------------------------------------------------------------- harness */

/// Wraps a screen in the providers Phase 7 needs.
///
/// Everything the feature touches is overridden unconditionally — the draft
/// store, the photo source, the PDF opener, the camera and the location
/// gateway. Four of those five reach outside the module, and a test that
/// forgot one of them would not fail loudly: it would quietly build the real
/// object and fail later, on something else.
Widget scopedSiteReports({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedSiteActivityReports? siteActivity,
  ScriptedDailySiteReports? daily,
  MemoryReportDraftStore? drafts,
  ScriptedLocation? location,
  ScriptedCamera? camera,
  FakeReportPhotoSource? photos,
  FakeReportPdfOpener? pdf,
}) => ProviderScope(
  overrides: [
    permissionScopeProvider.overrideWithValue(
      PermissionScope(buildUser(permissions: permissions, roles: roles)),
    ),
    // Both repositories are overridden whether or not the test passed one.
    // The daily form opens the *activity* repository for its site picker,
    // so leaving it alone would quietly build the real HTTP client — a
    // request to a server that does not exist, behind a spinner that never
    // stops, timing out a `pumpAndSettle` on something no test asked about.
    siteActivityRepositoryProvider.overrideWithValue(
      siteActivity ??
          ScriptedSiteActivityReports(
            idOf: (report) => report.id,
            items: <SiteActivityReport>[testActivityReport()],
          ),
    ),
    dailySiteReportRepositoryProvider.overrideWithValue(
      daily ??
          ScriptedDailySiteReports(
            idOf: (report) => report.id,
            items: <DailySiteReport>[testDailyReport()],
          ),
    ),
    reportDraftStoreProvider.overrideWithValue(
      drafts ?? MemoryReportDraftStore(),
    ),
    locationGatewayProvider.overrideWithValue(location ?? ScriptedLocation()),
    documentCameraProvider.overrideWithValue(camera ?? ScriptedCamera()),
    reportPhotoSourceProvider.overrideWithValue(
      photos ?? FakeReportPhotoSource(),
    ),
    reportPdfOpenerProvider.overrideWithValue(pdf ?? FakeReportPdfOpener()),
  ],
  child: child,
);

/// A router carrying exactly the eight Phase 7 paths, with the one under
/// test wired to [screen] and the rest to a stub.
///
/// The screens do not know they are being tested: `context.go('/site-reports/7')`
/// after a save runs either way, and a router that could not resolve it
/// would throw and fail the test for the wrong reason — while a screen
/// pumped under a bare `MaterialApp(home: …)` would fail on `GoRouter.of`
/// for a reason that has nothing to do with the save.
GoRouter siteReportsRouter({required String location, required Widget screen}) {
  // `/site-reports/7/edit` and `/site-reports/:id/edit` name the same route;
  // comparing the URL as written would wire the stub instead of the screen
  // under test, and the very first frame would be an empty one.
  final template = location.replaceAll(RegExp(r'/\d+'), '/:id');

  Widget at(String path, GoRouterState state) =>
      path == template ? screen : _RouteStub(state.uri.toString());

  return GoRouter(
    initialLocation: location,
    routes: [
      GoRoute(
        path: '/site-reports',
        builder: (_, state) => at('/site-reports', state),
      ),
      GoRoute(
        path: '/site-reports/new',
        builder: (_, state) => at('/site-reports/new', state),
      ),
      GoRoute(
        path: '/site-reports/:id',
        builder: (_, state) => at('/site-reports/:id', state),
      ),
      GoRoute(
        path: '/site-reports/:id/edit',
        builder: (_, state) => at('/site-reports/:id/edit', state),
      ),
      GoRoute(
        path: '/daily-reports',
        builder: (_, state) => at('/daily-reports', state),
      ),
      GoRoute(
        path: '/daily-reports/new',
        builder: (_, state) => at('/daily-reports/new', state),
      ),
      GoRoute(
        path: '/daily-reports/:id',
        builder: (_, state) => at('/daily-reports/:id', state),
      ),
      GoRoute(
        path: '/daily-reports/:id/edit',
        builder: (_, state) => at('/daily-reports/:id/edit', state),
      ),
    ],
  );
}

/// The body of a path the test did not ask to see.
///
/// Two things it has to be, rather than the obvious `SizedBox`:
///
///  - a [Scaffold]. `ScaffoldMessenger` presents a queued `SnackBar` on the
///    next Scaffold it is handed, and a route with none would swallow the
///    "saved" message a form shows immediately before it navigates here —
///    making the test fail on a message that was in fact said.
///  - named after the URI it resolved. The one thing a test wants to know
///    after a save is *where the router went*, and `stub /site-reports/7`
///    says both that the walk happened and which id it carried, without the
///    test needing an API on `GoRouter` to ask.
class _RouteStub extends StatelessWidget {
  const _RouteStub(this.uri);

  final String uri;

  @override
  Widget build(BuildContext context) =>
      Scaffold(body: Center(child: Text('stub $uri')));
}

/// Taps [finder] after scrolling it into view.
///
/// The activity form is two thousand pixels tall; a `tap()` that misses
/// warns rather than fails, and the assertion afterwards then fails for a
/// reason unrelated to the behaviour being tested.
Future<void> tapIn(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.tap(finder);
  await tester.pump();
}

/// Chooses today through the date picker [DateField] opens, in the field
/// named by [field].
///
/// Driven through the real dialog rather than writing the controller: the
/// control is `readOnly`, so the picker is the only route a person has, and
/// a test that filled the controller directly would stay green if the
/// picker were broken. Today rather than an arbitrary day, because the
/// dialog opens on it and `lastDate` is today on the activity form — an
/// arbitrary day would have to be navigated to first.
Future<void> pickDate(WidgetTester tester, Key field) async {
  await tapIn(tester, find.byKey(field));
  await tester.pumpAndSettle();

  final day = find.text('${DateTime.now().day}');
  expect(day, findsWidgets, reason: 'the picker should be showing today');
  await tapIn(tester, day.first);
  await tester.pump();

  await tapIn(tester, find.text('OK'));
  await tester.pumpAndSettle();
}

/// Opens the site picker and chooses the first site it offers.
///
/// [field] is the picker's key, which differs between the two forms.
Future<void> chooseSite(
  WidgetTester tester, {
  Key field = const ValueKey('site-report-site'),
}) async {
  await tapIn(tester, find.byKey(field));
  await tester.pumpAndSettle();

  await tapIn(tester, find.textContaining('Block A').first);
  await tester.pumpAndSettle();
}

/// Types into [field] with a pump, so the `onChanged` that schedules a
/// draft save has run before the next line asserts on anything.
Future<void> type(WidgetTester tester, Key field, String value) async {
  await tester.enterText(find.byKey(field), value);
  await tester.pump();
}

/* ------------------------------------------------------------- HTTP fake */

/// One verb, as the repository sent it.
class RecordedCall {
  const RecordedCall(this.verb, this.path, {this.body, this.query, this.files});

  final String verb;
  final String path;
  final Object? body;
  final Map<String, Object?>? query;
  final Map<String, Object>? files;
}

/// An [ApiClient] whose answers are scripted, so a repository test can
/// assert on the *request* — path, verb, body, query — without a server.
///
/// Worth having rather than skipping the data layer: the interesting claims
/// about these two repositories are all about what leaves the phone (`submit`
/// sends three keys and no `status`; `pdf` bypasses the envelope; photos go
/// up as one multipart batch), and none of them is visible from a model test
/// that starts after the response has already arrived.
class ScriptedApiClient extends ApiClient {
  ScriptedApiClient({this.reply})
    : super(baseUrl: 'http://localhost', tokenStore: InMemoryTokenStore());

  final List<RecordedCall> calls = <RecordedCall>[];

  /// Answered in order, ahead of [reply], for "the second page differs".
  final List<ApiEnvelope> queue = <ApiEnvelope>[];

  ApiEnvelope? reply;
  Object? error;
  Uint8List? byteReply;

  RecordedCall? get last => calls.isEmpty ? null : calls.last;

  ApiEnvelope _answer(
    String verb,
    String path, {
    Object? body,
    Map<String, Object?>? query,
    Map<String, Object>? files,
  }) {
    calls.add(RecordedCall(verb, path, body: body, query: query, files: files));

    final failure = error;
    if (failure != null) {
      error = null;
      throw failure;
    }

    if (queue.isNotEmpty) return queue.removeAt(0);

    return reply ?? const ApiEnvelope(message: '', data: null);
  }

  @override
  Future<ApiEnvelope> get(String path, {Map<String, Object?>? query}) async =>
      _answer('GET', path, query: query);

  @override
  Future<ApiEnvelope> post(String path, {Object? body}) async =>
      _answer('POST', path, body: body);

  @override
  Future<ApiEnvelope> put(String path, {Object? body}) async =>
      _answer('PUT', path, body: body);

  @override
  Future<ApiEnvelope> delete(String path) async => _answer('DELETE', path);

  @override
  Future<ApiEnvelope> postMultipart(
    String path, {
    required Map<String, Object?> fields,
    Map<String, Object>? files,
  }) async => _answer('POST', path, body: fields, files: files);

  @override
  Future<Uint8List> bytes(String path) async {
    calls.add(RecordedCall('BYTES', path));

    final failure = error;
    if (failure != null) {
      error = null;
      throw failure;
    }

    return byteReply ?? Uint8List.fromList(const <int>[37, 80, 68, 70]);
  }
}
