import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/storage/device_identity.dart';
import 'package:mobile/features/attendance/data/api_attendance_repository.dart';
import 'package:mobile/features/attendance/data/device_location.dart';
import 'package:mobile/features/attendance/data/offline_queue.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/features/attendance/data/selfie_compressor.dart';
import 'package:mobile/features/attendance/domain/attendance_record.dart';
import 'package:mobile/features/attendance/domain/attendance_repository.dart';
import 'package:mobile/features/attendance/domain/location_fix.dart';
import 'package:mobile/features/attendance/domain/movement_event.dart';
import 'package:mobile/features/attendance/domain/today_status.dart';

import 'fakes.dart';
import 'phase4.dart';

/* ------------------------------------------------------------ plumbing */

/// The server's answers, on tap.
///
/// Nothing here reaches a socket: every method records what it was asked and
/// returns whatever the test pre-loaded. Errors are *consumed* when thrown,
/// so "the network was down the first time and worked the second" is one
/// assignment rather than a counter the test has to watch.
class ScriptedAttendance implements AttendanceRepository {
  ScriptedAttendance({TodayStatus? today, List<SiteVisit>? visits})
    : todayResult = today ?? todayStatus(),
      todayVisits = visits ?? <SiteVisit>[];

  TodayStatus todayResult;
  ApiException? todayError;

  /// When set, `today()` waits here — the hook that makes the loading state
  /// observable rather than a frame that never appears.
  Completer<void>? todayHold;

  List<SiteVisit> todayVisits;
  ApiException? visitsError;

  ApiException? checkInError;
  ApiException? checkOutError;
  ApiException? startVisitError;
  ApiException? endVisitError;
  ApiException? movementError;

  /// When set, every `checkIn` waits here — the hook that makes a second tap
  /// land while the first is still in flight.
  Completer<void>? hold;

  int todayCalls = 0;
  int checkInCalls = 0;
  int checkOutCalls = 0;
  int startVisitCalls = 0;
  int endVisitCalls = 0;
  int movementCalls = 0;

  final List<CheckInSubmission> checkIns = <CheckInSubmission>[];
  final List<CheckOutSubmission> checkOuts = <CheckOutSubmission>[];
  final List<SiteVisitStartSubmission> visitStarts =
      <SiteVisitStartSubmission>[];
  final List<SiteVisitEndSubmission> visitEnds = <SiteVisitEndSubmission>[];

  @override
  Future<TodayStatus> today() async {
    final held = todayHold;
    if (held != null) await held.future;

    todayCalls++;
    final error = todayError;
    if (error != null) {
      todayError = null;
      throw error;
    }
    return todayResult;
  }

  @override
  Future<List<SiteVisit>> siteVisitsToday() async {
    final error = visitsError;
    if (error != null) {
      visitsError = null;
      throw error;
    }
    return todayVisits;
  }

  @override
  Future<AttendanceRecord> checkIn(CheckInSubmission submission) async {
    final held = hold;
    if (held != null) await held.future;

    checkInCalls++;
    checkIns.add(submission);

    final error = checkInError;
    if (error != null) {
      checkInError = null;
      throw error;
    }

    return attendanceRecord();
  }

  @override
  Future<AttendanceRecord> checkOut(CheckOutSubmission submission) async {
    checkOutCalls++;
    checkOuts.add(submission);

    final error = checkOutError;
    if (error != null) {
      checkOutError = null;
      throw error;
    }

    return attendanceRecord(
      checkOutAt: '2026-09-27T18:00:00+00:00',
      status: 'present',
      workingMinutes: 505,
    );
  }

  @override
  Future<SiteVisit> startSiteVisit(SiteVisitStartSubmission submission) async {
    startVisitCalls++;
    visitStarts.add(submission);

    final error = startVisitError;
    if (error != null) {
      startVisitError = null;
      throw error;
    }

    return siteVisit();
  }

  @override
  Future<SiteVisit> endSiteVisit(SiteVisitEndSubmission submission) async {
    endVisitCalls++;
    visitEnds.add(submission);

    final error = endVisitError;
    if (error != null) {
      endVisitError = null;
      throw error;
    }

    return siteVisit(endedAt: '2026-09-27T12:05:00+00:00', durationMinutes: 45);
  }

  @override
  Future<List<MovementEvent>> movementToday() async {
    movementCalls++;
    final error = movementError;
    if (error != null) {
      movementError = null;
      throw error;
    }
    return const <MovementEvent>[];
  }
}

/// A phone with no radio, no permission and no satellites — unless the test
/// says otherwise.
class ScriptedLocation implements LocationGateway {
  bool serviceEnabled = true;
  LocationStatus permission = LocationStatus.granted;

  /// What a *request* resolves to, so a test can model a refusal that the
  /// platform will not show again.
  LocationStatus? requestedAs;

  LocationFix fix = const LocationFix(
    latitude: 12.9716,
    longitude: 77.5946,
    accuracy: 9,
  );

  Object? fixError;

  int checks = 0;
  int requests = 0;
  int appSettingsOpens = 0;
  int locationSettingsOpens = 0;

  @override
  Future<LocationStatus> checkPermission() async {
    checks++;
    return permission;
  }

  @override
  Future<LocationStatus> requestPermission() async {
    requests++;
    return requestedAs ?? (permission = LocationStatus.granted);
  }

  @override
  Future<bool> isServiceEnabled() async => serviceEnabled;

  @override
  Future<LocationFix> currentFix({
    Duration timeLimit = const Duration(seconds: 20),
  }) async {
    final error = fixError;
    if (error != null) throw error;
    return fix;
  }

  @override
  Future<void> openAppSettings() async {
    appSettingsOpens++;
  }

  @override
  Future<void> openLocationSettings() async {
    locationSettingsOpens++;
  }
}

/// The camera, with every way it can say no — and with a photograph that is
/// a real image.
///
/// `Image.memory` decodes whatever the shutter produced, so a fake made of
/// arbitrary bytes makes the review frame report "Invalid image data" and
/// the test fails for a reason that has nothing to do with the flow under
/// test. A known-good 1×1 PNG keeps the fake honest without asking the
/// engine to rasterise anything, which a fake-async test cannot wait for.
class ScriptedCamera implements SelfieCamera {
  CameraStatus status = CameraStatus.ready;
  int opens = 0;
  int captures = 0;
  int closes = 0;
  Object? captureError;

  ScriptedCamera({Uint8List? bytes}) : _supplied = bytes;

  final Uint8List? _supplied;
  Uint8List? _frame;

  CameraStatus? lastStatus;

  @override
  Widget? get preview => lastStatus == CameraStatus.ready
      ? const SizedBox(key: ValueKey('scripted-preview'))
      : null;

  @override
  Future<CameraStatus> open() async {
    opens++;
    lastStatus = status;
    return status;
  }

  @override
  Future<Uint8List> capture() async {
    captures++;

    final error = captureError;
    if (error != null) throw error;

    return _frame ??= _supplied ?? _png;
  }

  @override
  Future<void> close() async {
    closes++;
  }

  /// A 1×1 PNG, decoded lazily and reused.
  static final _png = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhf'
    'DwAChwGA60e6kgAAAABJRU5ErkJggg==',
  );
}

/// Compression that does not spawn an isolate.
///
/// A widget test runs in a fake-async zone, and `compute` — a real isolate —
/// cannot complete inside one. What the tests care about is *that* the bytes
/// handed over are the bytes stored and sent, not the pixel maths, and that
/// lives in its own plain test anyway.
class PassthroughCompressor implements SelfieCompressor {
  const PassthroughCompressor();

  @override
  Future<Uint8List> compress(Uint8List raw, {bool isolate = true}) async =>
      Uint8List.fromList(raw);
}

class InMemoryQueueStore implements OfflineQueueStore {
  List<OfflineEvent> events = <OfflineEvent>[];

  @override
  Future<List<OfflineEvent>> read() async => List.of(events);

  @override
  Future<void> write(List<OfflineEvent> stored) async {
    events = List.of(stored);
  }
}

class InMemorySelfieStore implements PendingSelfieStore {
  final Map<String, Uint8List> files = <String, Uint8List>{};

  @override
  Future<String> put(String clientEventId, Uint8List bytes) async {
    final path = '/pending/$clientEventId.jpg';
    files[path] = Uint8List.fromList(bytes);
    return path;
  }

  @override
  Future<Uint8List?> read(String path) async => files[path];

  @override
  Future<void> delete(String path) async {
    files.remove(path);
  }
}

/* ------------------------------------------------------------ overrides */

class AttendanceHarness {
  AttendanceHarness({
    ScriptedAttendance? repository,
    ScriptedLocation? location,
    ScriptedCamera? camera,
    InMemoryQueueStore? queue,
    InMemorySelfieStore? selfies,
  }) : repository = repository ?? ScriptedAttendance(),
       location = location ?? ScriptedLocation(),
       camera = camera ?? ScriptedCamera(),
       queue = queue ?? InMemoryQueueStore(),
       selfies = selfies ?? InMemorySelfieStore();

  final ScriptedAttendance repository;
  final ScriptedLocation location;
  final ScriptedCamera camera;
  final InMemoryQueueStore queue;
  final InMemorySelfieStore selfies;

  /// A container with the hardware replaced.
  ///
  /// Builds rather than exposes the override list so nothing here has to
  /// name the `Override` type, which `package:flutter_riverpod` does not
  /// re-export.
  ProviderContainer createContainer() => ProviderContainer(
    overrides: [
      attendanceRepositoryProvider.overrideWithValue(repository),
      locationGatewayProvider.overrideWithValue(location),
      selfieCameraProvider.overrideWithValue(camera),
      selfieCompressorProvider.overrideWithValue(const PassthroughCompressor()),
      offlineQueueStoreProvider.overrideWithValue(queue),
      pendingSelfieStoreProvider.overrideWithValue(selfies),
      deviceIdentityProvider.overrideWithValue(
        const FixedDeviceIdentity('android-testphone'),
      ),
    ],
  );

  OfflineQueue get offlineQueue => OfflineQueue(store: queue, selfies: selfies);
}

/// Pumps [child] with the harness wired in.
Future<void> pumpAttendance(
  WidgetTester tester,
  AttendanceHarness harness, {
  required Widget child,
}) async {
  // The screen is a scrolling list of cards and buttons; on the default
  // 800×600 surface everything below the first card is not even built yet,
  // so `tap()` warns and the assertion after it fails for reasons that have
  // nothing to do with behaviour. Same reasoning as the employee form —
  // see `useTallScreen`.
  useTallScreen(tester);

  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: harness.createContainer(),
      child: MaterialApp(home: child),
    ),
  );
}

/// Lets the futures started by `initState` — and the ones they start — land.
///
/// `pumpAndSettle` is wrong here: the attendance screen has no looping
/// animation, but it does start its load in a microtask, and each `pump`
/// drains one round of them.
Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump();
  }
}

/* ---------------------------------------------------------- JSON shapes */

Map<String, dynamic> siteJson({
  int id = 1,
  String name = 'Whitefield Yard',
  String code = 'SITE-1',
  int? projectId = 7,
  String projectName = 'Metro Line 3',
  double? latitude = 12.9716,
  double? longitude = 77.5946,
  double? radius = 100,
}) => <String, dynamic>{
  'id': id,
  'name': name,
  'code': code,
  'project_id': projectId,
  'project_name': projectName,
  'latitude': latitude,
  'longitude': longitude,
  'geofence_radius': radius,
};

Map<String, dynamic> attendanceJson({
  int id = 501,
  int siteId = 1,
  String status = 'present',
  String? checkInAt = '2026-09-27T09:05:00+00:00',
  String? checkOutAt,
  int workingMinutes = 0,
  int lateMinutes = 0,
  bool hasSelfie = true,
}) => <String, dynamic>{
  'id': id,
  'employee_id': 10,
  'project_id': 7,
  'site_id': siteId,
  'site': <String, dynamic>{'id': siteId, 'name': 'Whitefield Yard'},
  'attendance_date': '2026-09-27',
  'status': status,
  'check_in_at': checkInAt,
  'check_out_at': checkOutAt,
  'check_in_latitude': '12.9716000',
  'check_in_longitude': '77.5946000',
  'check_in_accuracy': '9.00',
  'check_in_distance': '4.20',
  'working_minutes': workingMinutes,
  'break_minutes': 60,
  'overtime_minutes': 0,
  'late_minutes': lateMinutes,
  'early_departure_minutes': 0,
  'has_selfie': hasSelfie,
  'source': 'online',
};

Map<String, dynamic> visitJson({
  int id = 300,
  int siteId = 1,
  String? endedAt,
  int? durationMinutes,
  String purpose = 'Material delivery',
}) => <String, dynamic>{
  'id': id,
  'employee_id': 10,
  'project_id': 7,
  'site_id': siteId,
  'site': <String, dynamic>{'id': siteId, 'name': 'Whitefield Yard'},
  'started_at': '2026-09-27T11:00:00+00:00',
  'ended_at': endedAt,
  'duration_minutes': durationMinutes,
  'purpose': purpose,
  'status': endedAt == null ? 'open' : 'closed',
  'start_latitude': '12.9716000',
  'start_longitude': '77.5946000',
  'start_accuracy': '9.00',
  'start_distance': '4.20',
};

Map<String, dynamic> todayJson({
  bool checkedIn = false,
  bool checkedOut = false,
  Map<String, dynamic>? attendance,
  Map<String, dynamic>? openAttendance,
  int? siteId = 1,
  List<Map<String, dynamic>>? sites,
  bool canCheckIn = true,
  bool canCheckOut = false,
  bool canStartSiteVisit = true,
  int workingMinutes = 0,
  int lateMinutes = 0,
}) {
  final siteList = sites ?? <Map<String, dynamic>>[siteJson(id: siteId ?? 1)];

  Object? current;

  if (siteId != null) {
    for (final row in siteList) {
      if (row['id'] == siteId) {
        current = row;
        break;
      }
    }

    current ??= siteList.isEmpty ? null : siteList.first;
  }

  return <String, dynamic>{
    'date': '2026-09-27',
    'server_time': '2026-09-27T10:00:00+00:00',
    'employee_id': 10,
    'attendance': attendance,
    'open_attendance': openAttendance,
    'checked_in': checkedIn,
    'checked_out': checkedOut,
    'site': current,
    'sites': siteList,
    'shift': <String, dynamic>{
      'shift_id': null,
      'name': null,
      'starts_at': '09:00',
      'ends_at': '18:00',
      'grace_minutes': 10,
      'break_minutes': 60,
      'minimum_working_minutes': 480,
      'overtime_threshold_minutes': 30,
      'crosses_midnight': false,
    },
    'working_minutes': workingMinutes,
    'late_minutes': lateMinutes,
    'can_check_in': canCheckIn,
    'can_check_out': canCheckOut,
    'can_start_site_visit': canStartSiteVisit,
    'max_gps_accuracy_metres': 100,
  };
}

/* -------------------------------------------------------------- records */

AttendanceRecord attendanceRecord({
  int id = 501,
  String? checkOutAt,
  String status = 'present',
  int workingMinutes = 0,
}) => AttendanceRecord.fromJson(
  attendanceJson(
    id: id,
    checkOutAt: checkOutAt,
    status: status,
    workingMinutes: workingMinutes,
  ),
);

SiteVisit siteVisit({String? endedAt, int? durationMinutes}) =>
    SiteVisit.fromJson(
      visitJson(endedAt: endedAt, durationMinutes: durationMinutes),
    );

TodayStatus todayStatus({
  bool checkedIn = false,
  bool canCheckIn = true,
  bool canCheckOut = false,
  int? siteId = 1,
  List<int>? siteIds,
  int workingMinutes = 0,
  int lateMinutes = 0,
}) {
  final ids = siteIds ?? <int>[?siteId];

  return TodayStatus.fromJson(
    todayJson(
      checkedIn: checkedIn,
      canCheckIn: canCheckIn,
      canCheckOut: canCheckOut,
      siteId: siteId,
      sites: [for (final id in ids) siteJson(id: id)],
      workingMinutes: workingMinutes,
      lateMinutes: lateMinutes,
    ),
  );
}
