import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/data/client_event_id.dart';
import 'package:mobile/features/attendance/data/offline_queue.dart';
import 'package:mobile/features/attendance/domain/location_fix.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../support/attendance.dart';

/// The offline queue: what is remembered, for how long, and under which key
/// it will be replayed.
///
/// The single most important property in this file is that **the key does
/// not change between attempts**. The server's unique index on
/// `client_event_id` is what makes a retry safe; a queue that regenerated
/// the id on every replay would turn one missed check-in into five.
void main() {
  late InMemoryQueueStore store;
  late InMemorySelfieStore selfies;
  late OfflineQueue queue;

  const fix = LocationFix(latitude: 12.9716, longitude: 77.5946, accuracy: 8);

  final jpeg = Uint8List.fromList([1, 2, 3, 4, 5]);

  setUp(() {
    store = InMemoryQueueStore();
    selfies = InMemorySelfieStore();
    queue = OfflineQueue(store: store, selfies: selfies);
  });

  test('a client event id is a v4 uuid, and different every time', () {
    final first = newClientEventId();
    final second = newClientEventId();

    final pattern = RegExp(
      r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
    );

    expect(first, matches(pattern));
    expect(second, matches(pattern));
    expect(first, isNot(second));
  });

  test('enqueue keeps the photograph beside the event', () async {
    final event = await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );

    expect(event.syncStatus, OfflineEvent.syncPending);
    expect(event.isPending, isTrue);
    expect(event.attempts, 0);
    expect(event.lastError, isNull);
    expect(event.selfiePath, isNotNull);

    final stored = await selfies.read(event.selfiePath!);
    expect(stored, jpeg);

    expect(await queue.pendingCount(), 1);
  });

  test('a check-out needs no photograph', () async {
    final event = await queue.enqueue(
      action: OfflineAction.checkOut,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
    );

    expect(event.selfiePath, isNull);
    expect(selfies.files, isEmpty);
  });

  test('every enqueue is its own event under its own key', () async {
    final first = await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );
    final second = await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );

    expect(first.clientEventId, isNot(second.clientEventId));
    expect(await queue.pendingCount(), 2);
  });

  test('the queue survives being read back from storage', () async {
    final event = await queue.enqueue(
      action: OfflineAction.visitStart,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      purpose: 'Snag list walk',
      remarks: 'North block',
    );

    // A different OfflineQueue over the same bytes — the app after a restart.
    final reopened = OfflineQueue(store: store, selfies: selfies);
    final restored = await reopened.pending();

    expect(restored, hasLength(1));
    expect(restored.single.clientEventId, event.clientEventId);
    expect(restored.single.purpose, 'Snag list walk');
    expect(restored.single.remarks, 'North block');
    expect(restored.single.fix.latitude, closeTo(12.9716, 0.0001));
    expect(restored.single.fix.accuracy, 8);
    expect(
      restored.single.capturedAt.isAfter(
        DateTime.now().subtract(const Duration(minutes: 1)),
      ),
      isTrue,
    );
  });

  test('the queue round-trips through real preferences storage', () async {
    SharedPreferences.setMockInitialValues({});

    final prefs = await SharedPreferences.getInstance();
    OfflineQueue reopened() => OfflineQueue(
      store: SharedPreferencesOfflineQueueStore(prefs),
      selfies: selfies,
    );

    final event = await reopened().enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      purpose: 'Snag list walk',
      selfieBytes: jpeg,
    );

    // Same preferences, brand new objects — the app after a restart.
    final restored = await reopened().pending();

    expect(restored, hasLength(1));

    final row = restored.single;

    // The whole point: the key the app will replay under is the key it
    // queued under, even across a restart. A fresh one would make this a
    // different event as far as the server's unique index is concerned.
    expect(row.clientEventId, event.clientEventId);
    expect(row.action, OfflineAction.checkIn);
    expect(row.siteId, 7);
    expect(row.purpose, 'Snag list walk');
    expect(row.fix.latitude, closeTo(12.9716, 0.0001));
    expect(row.fix.accuracy, 8);
    expect(row.selfiePath, event.selfiePath);
    expect(
      row.capturedAt.isAfter(
        DateTime.now().subtract(const Duration(minutes: 1)),
      ),
      isTrue,
    );
  });

  test('unreadable storage reads as an empty queue, not as a crash', () async {
    SharedPreferences.setMockInitialValues({
      SharedPreferencesOfflineQueueStore.key: 'this is not json',
    });

    final prefs = await SharedPreferences.getInstance();
    final recovered = OfflineQueue(
      store: SharedPreferencesOfflineQueueStore(prefs),
      selfies: selfies,
    );

    expect(await recovered.all(), isEmpty);
    expect(await recovered.pendingCount(), 0);
  });

  test('a missing key reads as an empty queue', () async {
    SharedPreferences.setMockInitialValues({});

    final prefs = await SharedPreferences.getInstance();
    final fresh = OfflineQueue(
      store: SharedPreferencesOfflineQueueStore(prefs),
      selfies: selfies,
    );

    expect(await fresh.all(), isEmpty);
  });

  test('a failed attempt is kept, counted and explained', () async {
    final event = await queue.enqueue(
      action: OfflineAction.checkOut,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
    );

    await queue.markFailed(event.clientEventId, 'Site is outside the fence');

    final after = (await queue.all()).single;

    expect(after.clientEventId, event.clientEventId);
    expect(after.syncStatus, OfflineEvent.syncFailed);
    expect(after.isPending, isFalse);
    expect(after.attempts, 1);
    expect(after.lastError, 'Site is outside the fence');

    // Two failures do not lose the row either — and the key is unchanged,
    // so whichever attempt finally lands is the same event.
    await queue.markFailed(event.clientEventId, 'Still outside');
    final twice = (await queue.all()).single;

    expect(twice.attempts, 2);
    expect(twice.clientEventId, event.clientEventId);
    expect(twice.lastError, 'Still outside');
  });

  test('confirming removes the row and the photograph with it', () async {
    final event = await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );
    final path = event.selfiePath!;

    expect(await selfies.read(path), jpeg);

    await queue.confirm(event.clientEventId);

    expect(await queue.all(), isEmpty);
    expect(await selfies.read(path), isNull);
    expect(selfies.files, isEmpty);
  });

  test('confirming one event leaves its neighbours alone', () async {
    final first = await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );
    final second = await queue.enqueue(
      action: OfflineAction.checkOut,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
    );

    await queue.confirm(first.clientEventId);

    final remaining = await queue.all();
    expect(remaining, hasLength(1));
    expect(remaining.single.clientEventId, second.clientEventId);
  });

  test('confirming an unknown key is a no-op, not a wipe', () async {
    await queue.enqueue(
      action: OfflineAction.checkIn,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
      selfieBytes: jpeg,
    );

    await queue.confirm('not-a-real-id');

    expect(await queue.pendingCount(), 1);
  });

  test('pending() hides the ones that have already gone', () async {
    final sent = await queue.enqueue(
      action: OfflineAction.checkOut,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
    );
    await queue.enqueue(
      action: OfflineAction.checkOut,
      siteId: 7,
      fix: fix,
      deviceReference: 'android-testphone',
    );

    await queue.markFailed(sent.clientEventId, 'Rejected');

    final pending = await queue.pending();
    expect(pending, hasLength(1));
    expect(pending.single.clientEventId, isNot(sent.clientEventId));
  });
}
