import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../domain/location_fix.dart';
import 'client_event_id.dart';

/// The four acts that can be queued, one per server endpoint.
abstract final class OfflineAction {
  static const String checkIn = 'check_in';
  static const String checkOut = 'check_out';
  static const String visitStart = 'site_visit_start';
  static const String visitEnd = 'site_visit_end';
}

/// One thing that happened on this phone and has not reached the server yet.
///
/// The shape mirrors what each endpoint needs and *nothing the server would
/// derive for itself* — no distance, no status, no working minutes. Replaying
/// a queued event sends where the phone was and when the person pressed the
/// button; every judgement about that day is made again, server-side, as if
/// the request had arrived on time. Which it did not, and `source: offline`
/// says so.
class OfflineEvent {
  const OfflineEvent({
    required this.clientEventId,
    required this.action,
    required this.siteId,
    required this.capturedAt,
    required this.fix,
    required this.deviceReference,
    this.siteVisitId,
    this.purpose,
    this.remarks,
    this.selfiePath,
    this.syncStatus = syncPending,
    this.attempts = 0,
    this.lastError,
  });

  static const String syncPending = 'pending_sync';
  static const String syncFailed = 'failed';

  /// The idempotency key. Fixed at enqueue time and never regenerated —
  /// see `newClientEventId`.
  final String clientEventId;

  final String action;

  final int siteId;

  /// For `visit_end`, the row to close. The start of a visit carries
  /// `siteId`; the end carries the visit it belongs to, because those are
  /// two separate queued events with two separate keys.
  final int? siteVisitId;

  final String? purpose;
  final String? remarks;

  /// The device's own clock at the moment of the act. Kept for diagnostics
  /// and for a screen that says "queued 12 minutes ago"; the server does not
  /// accept it as the time anything happened.
  final DateTime capturedAt;

  final LocationFix fix;

  /// Local path to the stored JPEG, for `check_in` only.
  final String? selfiePath;

  final String deviceReference;

  final String syncStatus;
  final int attempts;
  final String? lastError;

  bool get isPending => syncStatus == syncPending;

  OfflineEvent copyWith({
    String? syncStatus,
    int? attempts,
    String? lastError,
    String? selfiePath,
  }) => OfflineEvent(
    clientEventId: clientEventId,
    action: action,
    siteId: siteId,
    siteVisitId: siteVisitId,
    purpose: purpose,
    remarks: remarks,
    capturedAt: capturedAt,
    fix: fix,
    selfiePath: selfiePath ?? this.selfiePath,
    deviceReference: deviceReference,
    syncStatus: syncStatus ?? this.syncStatus,
    attempts: attempts ?? this.attempts,
    lastError: lastError,
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'client_event_id': clientEventId,
    'action': action,
    'site_id': siteId,
    'site_visit_id': siteVisitId,
    'purpose': purpose,
    'remarks': remarks,
    'captured_at': capturedAt.toIso8601String(),
    'latitude': fix.latitude,
    'longitude': fix.longitude,
    'accuracy': fix.accuracy,
    'selfie_path': selfiePath,
    'device_reference': deviceReference,
    'sync_status': syncStatus,
    'attempts': attempts,
    'last_error': lastError,
  };

  factory OfflineEvent.fromJson(Map<String, dynamic> json) => OfflineEvent(
    clientEventId: json['client_event_id'] as String? ?? '',
    action: json['action'] as String? ?? '',
    siteId: (json['site_id'] as num?)?.toInt() ?? 0,
    siteVisitId: (json['site_visit_id'] as num?)?.toInt(),
    purpose: json['purpose'] as String?,
    remarks: json['remarks'] as String?,
    capturedAt:
        DateTime.tryParse(json['captured_at'] as String? ?? '') ??
        DateTime.fromMillisecondsSinceEpoch(0),
    fix: LocationFix(
      latitude: (json['latitude'] as num?)?.toDouble() ?? 0,
      longitude: (json['longitude'] as num?)?.toDouble() ?? 0,
      accuracy: (json['accuracy'] as num?)?.toDouble() ?? 0,
    ),
    selfiePath: json['selfie_path'] as String?,
    deviceReference: json['device_reference'] as String? ?? '',
    syncStatus: json['sync_status'] as String? ?? OfflineEvent.syncPending,
    attempts: (json['attempts'] as num?)?.toInt() ?? 0,
    lastError: json['last_error'] as String?,
  );
}

/// Where the queue itself is kept.
abstract class OfflineQueueStore {
  Future<List<OfflineEvent>> read();

  Future<void> write(List<OfflineEvent> events);
}

/// SharedPreferences-backed, as one JSON string under one key.
///
/// One key rather than one per event: the queue is a handful of rows, they
/// are read and written together, and a partial write across several keys
/// is how an offline queue ends up with a selfie and no record of it.
class SharedPreferencesOfflineQueueStore implements OfflineQueueStore {
  SharedPreferencesOfflineQueueStore(this._preferences);

  static const String key = 'attendance.offline_queue.v1';

  final SharedPreferences _preferences;

  @override
  Future<List<OfflineEvent>> read() async {
    final raw = _preferences.getString(key);
    if (raw == null || raw.isEmpty) return const <OfflineEvent>[];

    // Unreadable storage reads as an empty queue, never as an exception.
    //
    // The alternative is an attendance screen that will not open at all
    // because one preference went bad — and the events behind that blob are
    // already gone, so refusing to show the rest would be the only real
    // damage left to do.
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return const <OfflineEvent>[];

      return <OfflineEvent>[
        for (final row in decoded)
          if (row is Map<String, dynamic>) OfflineEvent.fromJson(row),
      ];
    } catch (_) {
      return const <OfflineEvent>[];
    }
  }

  @override
  Future<void> write(List<OfflineEvent> events) => _preferences.setString(
    key,
    jsonEncode([for (final event in events) event.toJson()]),
  );
}

final offlineQueueStoreProvider = Provider<OfflineQueueStore>((ref) {
  throw UnimplementedError(
    'offlineQueueStoreProvider must be overridden with an initialised '
    'SharedPreferences instance — see main().',
  );
});

/// Personal data waiting to be uploaded. Overridden in tests with a store
/// that keeps everything in memory.
final pendingSelfieStoreProvider = Provider<PendingSelfieStore>(
  (ref) => LocalPendingSelfieStore(),
);

/// The queue itself: which events exist, where their photographs are, and
/// which key each one will be replayed under.
final offlineQueueProvider = Provider<OfflineQueue>(
  (ref) => OfflineQueue(
    store: ref.watch(offlineQueueStoreProvider),
    selfies: ref.watch(pendingSelfieStoreProvider),
  ),
);

/// Where a queued selfie's bytes live while it waits.
///
/// A file rather than a string inside the queue: a 300 KB JPEG base64'd into
/// preferences would be read and rewritten in full on every enqueue, and
/// preferences is not the place for personal data that has not been
/// consented to a second time.
abstract class PendingSelfieStore {
  Future<String> put(String clientEventId, Uint8List bytes);

  Future<Uint8List?> read(String path);

  Future<void> delete(String path);
}

/// Under the app's documents directory, in a folder nothing else writes to.
class LocalPendingSelfieStore implements PendingSelfieStore {
  LocalPendingSelfieStore({Future<Directory>? directory})
    : _directory = directory ?? _documents();

  static const String folder = 'attendance-offline-selfies';

  final Future<Directory> _directory;

  static Future<Directory> _documents() async {
    final base = await getApplicationDocumentsDirectory();
    return Directory('${base.path}/$folder');
  }

  @override
  Future<String> put(String clientEventId, Uint8List bytes) async {
    final dir = await _directory;
    if (!await dir.exists()) await dir.create(recursive: true);

    final file = File('${dir.path}/$clientEventId.jpg');
    await file.writeAsBytes(bytes, flush: true);

    return file.path;
  }

  @override
  Future<Uint8List?> read(String path) async {
    final file = File(path);
    if (!await file.exists()) return null;

    return Uint8List.fromList(await file.readAsBytes());
  }

  @override
  Future<void> delete(String path) async {
    final file = File(path);
    if (await file.exists()) await file.delete();
  }
}

/// The offline queue: enqueue, count, retry, forget.
///
/// Deliberately *not* a background synchroniser. Phase 5 ships the
/// foundation — the event, its stable key, its storage, and a manual retry —
/// and leaves automatic replay for the step that needs a connectivity signal
/// and a policy about backoff. Everything here is written so that step can be
/// added without touching a single queued row.
class OfflineQueue {
  OfflineQueue({required this.store, required this.selfies});

  final OfflineQueueStore store;
  final PendingSelfieStore selfies;

  /// Adds one event, generating an idempotency key unless the caller already
  /// has one.
  ///
  /// [clientEventId] is passed through when the caller generated it *before*
  /// attempting the request — which is the rule, not the exception. A retry
  /// has to present the same key as the attempt it is replacing: if that
  /// first request actually reached the server and only the response was
  /// lost, a fresh key would record the day a second time, and the unique
  /// index that exists to stop exactly that would never fire.
  Future<OfflineEvent> enqueue({
    required String action,
    required int siteId,
    required LocationFix fix,
    required String deviceReference,
    String? clientEventId,
    int? siteVisitId,
    String? purpose,
    String? remarks,
    Uint8List? selfieBytes,
  }) async {
    final id = clientEventId ?? newEventId();

    String? selfiePath;
    if (selfieBytes != null) {
      selfiePath = await selfies.put(id, selfieBytes);
    }

    final event = OfflineEvent(
      clientEventId: id,
      action: action,
      siteId: siteId,
      siteVisitId: siteVisitId,
      purpose: purpose,
      remarks: remarks,
      capturedAt: DateTime.now(),
      fix: fix,
      selfiePath: selfiePath,
      deviceReference: deviceReference,
    );

    final events = await store.read();
    await store.write([...events, event]);

    return event;
  }

  Future<List<OfflineEvent>> all() => store.read();

  Future<List<OfflineEvent>> pending() async =>
      (await store.read()).where((event) => event.isPending).toList();

  Future<int> pendingCount() async => (await pending()).length;

  /// A failed attempt: kept, counted, and told why. The row is not dropped —
  /// losing it would lose the key, and a fresh key on a retry is exactly the
  /// duplicate the unique index exists to prevent.
  Future<void> markFailed(String clientEventId, String message) async {
    final events = await store.read();

    await store.write([
      for (final event in events)
        event.clientEventId == clientEventId
            ? event.copyWith(
                syncStatus: OfflineEvent.syncFailed,
                attempts: event.attempts + 1,
                lastError: message,
              )
            : event,
    ]);
  }

  /// The server has the event: the row goes, and so does the photograph —
  /// a selfie that has been uploaded and is still sitting in a local folder
  /// is personal data with nothing left to do.
  Future<void> confirm(String clientEventId) async {
    final events = await store.read();
    final match = events.where((e) => e.clientEventId == clientEventId);

    for (final event in match) {
      final path = event.selfiePath;
      if (path != null) await selfies.delete(path);
    }

    await store.write([
      for (final event in events)
        if (event.clientEventId != clientEventId) event,
    ]);
  }

  /// Overridable so a test can assert that two enqueues really do produce
  /// two different keys without stubbing randomness elsewhere.
  String Function() newEventId = newClientEventId;
}
