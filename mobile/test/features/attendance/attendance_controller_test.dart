import 'dart:async';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/attendance/data/device_location.dart';
import 'package:mobile/features/attendance/data/offline_queue.dart';
import 'package:mobile/features/attendance/presentation/attendance_controller.dart';

import '../../support/attendance.dart';

/// The check-in screen's brain, with every dependency replaced.
///
/// The cases here are the ones where being wrong is expensive: sending
/// twice, sending when the phone has no sky view, showing a 403 as though
/// it were a 200, or losing the event someone recorded at the gate because
/// the network blinked on the way home.
void main() {
  late AttendanceHarness harness;
  late ProviderContainer container;

  setUp(() {
    harness = AttendanceHarness();
    container = harness.createContainer();
  });

  tearDown(() async {
    // `load()` starts a location refresh it deliberately does not await, so
    // the chain can still be in flight when the test ends. Letting it settle
    // keeps the disposal off the middle of a callback — the real app never
    // disposes this provider at all.
    for (var i = 0; i < 5; i++) {
      await Future<void>.delayed(Duration.zero);
    }

    container.dispose();
  });

  AttendanceController controller() =>
      container.read(attendanceControllerProvider.notifier);

  AttendanceState state() => container.read(attendanceControllerProvider);

  /// The screen's `initState`: load, then get a reading.
  Future<AttendanceState> ready() async {
    await controller().load();
    await controller().refreshLocation();
    return state();
  }

  /// Room for the futures each call starts to chain through.
  Future<void> breathe([int rounds = 4]) async {
    for (var i = 0; i < rounds; i++) {
      await Future<void>.delayed(Duration.zero);
    }
  }

  final selfie = Uint8List.fromList([1, 2, 3, 4, 5]);

  group('loading', () {
    test('fills the day, the visits and the queue in one pass', () async {
      final loaded = await ready();

      expect(loaded.phase, AttendancePhase.ready);
      expect(loaded.today, isNotNull);
      expect(loaded.today!.date, '2026-09-27');
      expect(loaded.visits, isEmpty);
      expect(loaded.pending, isEmpty);
      expect(harness.repository.todayCalls, 1);
    });

    test('a failure to load is a screen that can be retried', () async {
      harness.repository.todayError = const ApiException(
        statusCode: 503,
        message: 'The service is temporarily unavailable.',
      );

      await controller().load();

      expect(state().phase, AttendancePhase.failed);
      expect(state().error, 'The service is temporarily unavailable.');

      // And the retry works, because the error was consumed, not sticky.
      await controller().load();
      expect(state().phase, AttendancePhase.ready);
    });

    test('the preselected site is one the picker actually offers', () async {
      final loaded = await ready();
      final offered = loaded.today!.sites.map((site) => site.id).toList();

      expect(loaded.selectedSiteId, isNotNull);
      expect(offered, contains(loaded.selectedSiteId));
      expect(loaded.selectedSite, isNotNull);
    });

    test('selecting a site changes what would be submitted', () async {
      harness.repository.todayResult = todayStatus(siteId: 1, siteIds: [1, 2]);
      await ready();

      controller().selectSite(2);
      await breathe();

      expect(state().selectedSiteId, 2);
      expect(state().selectedSite?.id, 2);
    });
  });

  group('location', () {
    test('the screen starts without having asked', () {
      expect(
        const AttendanceState().location.status,
        LocationStatus.notRequested,
      );
      expect(const AttendanceState().fix, isNull);
    });

    test('each permission answer gets a distinct, readable message', () async {
      final answers = <LocationStatus>{};
      final messages = <String>{};

      for (final permission in <LocationStatus>[
        LocationStatus.granted,
        LocationStatus.denied,
        LocationStatus.permanentlyDenied,
      ]) {
        harness.location.permission = permission;
        harness.location.serviceEnabled = true;

        await controller().refreshLocation();
        answers.add(state().location.status);
        messages.add(state().location.message);
      }

      // Three different refusals, three different messages: a person cannot
      // act on "location unavailable" when the truth is "you said no, and
      // asking again will not help".
      expect(answers, hasLength(3));
      expect(messages, hasLength(3));
      expect(messages.every((message) => message.trim().isNotEmpty), isTrue);
    });

    test(
      'a phone with its GPS switched off is not the same as a refusal',
      () async {
        harness.location.serviceEnabled = false;

        await controller().refreshLocation();

        expect(state().location.status, LocationStatus.serviceDisabled);
        expect(state().location.message.toLowerCase(), contains('gps'));
      },
    );

    test('granted but with no reading yet reads as unavailable', () async {
      harness.location.fixError = const LocationAcquisitionFailed(
        'No satellites in view.',
      );

      await controller().refreshLocation();

      expect(state().fix, isNull);
      expect(state().location.status, LocationStatus.unavailable);
    });

    test('requesting asks the platform when the answer was no', () async {
      harness.location.permission = LocationStatus.denied;

      await controller().refreshLocation();
      expect(harness.location.requests, 0);

      await controller().requestLocation();

      expect(harness.location.requests, 1);
    });

    test('a permanent refusal opens the settings, and asks no more', () async {
      harness.location.permission = LocationStatus.permanentlyDenied;
      harness.location.requestedAs = LocationStatus.permanentlyDenied;

      await controller().refreshLocation();
      await controller().requestLocation();

      expect(harness.location.appSettingsOpens, 1);
      expect(state().location.status, LocationStatus.permanentlyDenied);
    });
  });

  group('checking in', () {
    test('two taps while the first is in flight are one request', () async {
      await ready();

      harness.repository.hold = Completer<void>();

      final first = controller().checkIn(selfie);
      final second = controller().checkIn(selfie);
      await second;
      await breathe();

      // The gate is down: nobody has been told yet.
      expect(harness.repository.checkIns, isEmpty);
      expect(state().submitting, isTrue);

      harness.repository.hold!.complete();
      await first;
      await breathe();

      expect(harness.repository.checkInCalls, 1);
      expect(harness.repository.checkIns, hasLength(1));
      expect(state().submitting, isFalse);
      expect(state().notice, 'Checked in.');
    });

    test('a day already in progress no longer offers a check-in', () async {
      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
      );
      await ready();

      // The screen now offers a check-out instead — and no check-in at all,
      // because the server said so, not because the app counted taps.
      expect(state().today!.canCheckIn, isFalse);
      expect(state().today!.canCheckOut, isTrue);
    });

    test('a 403 says what the server said', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 403,
        message: 'You are not assigned to this site.',
      );

      await controller().checkIn(selfie);

      expect(state().submitError, 'You are not assigned to this site.');
      expect(state().submitting, isFalse);
      expect(state().pending, isEmpty);
      expect(state().notice, isNull);
    });

    test('a 422 shows the field that failed, not the boilerplate', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 422,
        message: 'The given data was invalid.',
        errors: {
          'location': 'You are 412 m from Whitefield Yard; the allowed radius is 100 m.',
        },
      );

      await controller().checkIn(selfie);

      // "The given data was invalid" would tell a person nothing they could
      // act on. The reason belongs to the `location` field and is what gets
      // shown.
      expect(
        state().submitError,
        'You are 412 m from Whitefield Yard; the allowed radius is 100 m.',
      );
      expect(state().submitting, isFalse);
      expect(state().pending, isEmpty);
    });

    test('with no usable reading nothing is sent at all', () async {
      harness.location.fixError = const LocationAcquisitionFailed(
        'No satellites in view.',
      );

      await ready();

      harness.location.fixError = const LocationAcquisitionFailed(
        'No satellites in view.',
      );

      await controller().checkIn(selfie);
      await breathe();

      expect(harness.repository.checkInCalls, 0);
      expect(state().submitting, isFalse);
      expect(state().submitError, isNotNull);
    });

    test('the request carries only what the phone could know', () async {
      await ready();

      await controller().checkIn(selfie);
      await breathe();

      final sent = harness.repository.checkIns.single;

      expect(sent.siteId, 1);
      expect(sent.selfieBytes, selfie);
      expect(sent.deviceReference, 'android-testphone');
      expect(sent.source, 'online');
      expect(sent.clientEventId, isNotEmpty);
      expect(
        RegExp(
          r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
        ).hasMatch(sent.clientEventId),
        isTrue,
        reason: 'a v4 uuid, so two phones cannot produce the same key',
      );
    });
  });

  group('checking out', () {
    test('succeeds with the same site the day started at', () async {
      await ready();

      await controller().checkOut();
      await breathe();

      expect(harness.repository.checkOutCalls, 1);
      expect(harness.repository.checkOuts.single.siteId, 1);
      expect(state().notice, 'Checked out.');
    });

    test('a rejection is shown, not swallowed', () async {
      await ready();

      harness.repository.checkOutError = const ApiException(
        statusCode: 422,
        message: 'You are not at the site you checked in at.',
      );

      await controller().checkOut();

      expect(state().submitError, 'You are not at the site you checked in at.');
      expect(harness.repository.checkOutCalls, 1);
    });
  });

  group('offline', () {
    test('a dead network saves the event instead of losing it', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await controller().checkIn(selfie);
      await breathe();

      expect(state().pending, hasLength(1));
      expect(state().pending.single.syncStatus, OfflineEvent.syncPending);
      expect(state().submitError, isNull);
      expect(state().notice, contains('saved here'));
      expect(state().submitting, isFalse);
      expect(
        harness.selfies.files,
        hasLength(1),
        reason: 'the photograph travels with the event',
      );
    });

    test('the replay reuses the key of the attempt it replaces', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await controller().checkIn(selfie);
      await breathe();

      // The network is back.
      await controller().syncPending();
      await breathe();

      expect(harness.repository.checkInCalls, 2);

      final first = harness.repository.checkIns.first;
      final replay = harness.repository.checkIns.last;

      // This is the whole idempotency contract: if that first request had
      // actually landed and only its reply was lost, the retry would be the
      // *same* event as far as the server's unique index is concerned.
      expect(replay.clientEventId, first.clientEventId);
      expect(first.source, 'online');
      expect(replay.source, 'offline');

      expect(state().pending, isEmpty);
      expect(state().notice, '1 event synced.');
      expect(harness.selfies.files, isEmpty);
    });

    test('syncing while still offline leaves everything queued', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await controller().checkIn(selfie);
      await breathe();

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await controller().syncPending();
      await breathe();

      expect(state().pending, hasLength(1));
      expect(state().notice, contains('Still offline'));
      expect(state().syncing, isFalse);
    });

    test('a rejected replay is kept and explained, not dropped', () async {
      await ready();

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await controller().checkIn(selfie);
      await breathe();

      harness.repository.checkInError = const ApiException(
        statusCode: 409,
        message: 'You have already checked in today.',
      );

      await controller().syncPending();
      await breathe();

      expect(state().pending.single.syncStatus, OfflineEvent.syncFailed);
      expect(
        state().pending.single.lastError,
        'You have already checked in today.',
      );
      expect(state().submitError, contains('could not be accepted'));
      expect(harness.repository.checkInCalls, 2);
    });
  });

  group('site visits', () {
    test('a visit starts with its purpose', () async {
      await ready();

      await controller().startVisit(
        purpose: 'Material delivery',
        remarks: 'North gate',
      );
      await breathe();

      expect(harness.repository.startVisitCalls, 1);

      final sent = harness.repository.visitStarts.single;
      expect(sent.siteId, 1);
      expect(sent.purpose, 'Material delivery');
      expect(sent.remarks, 'North gate');
      expect(state().notice, 'Site visit started.');
    });

    test('an empty purpose never leaves the phone', () async {
      await ready();

      await controller().startVisit(purpose: '   ');
      await breathe();

      expect(harness.repository.startVisitCalls, 0);
      expect(state().submitError, 'A purpose is required.');
    });

    test('ending needs an open visit', () async {
      await ready();

      await controller().endVisit();

      expect(harness.repository.endVisitCalls, 0);
      expect(state().submitError, 'There is no open site visit.');
    });

    test('an open visit is offered and can be closed', () async {
      harness.repository.todayVisits = [siteVisit()];
      await ready();

      expect(state().openVisit, isNotNull);

      await controller().endVisit();
      await breathe();

      expect(harness.repository.endVisitCalls, 1);
      expect(harness.repository.visitEnds.single.siteVisitId, 300);
      expect(state().notice, 'Site visit ended.');
    });

    test('a visit refused by the server is shown', () async {
      await ready();

      harness.repository.startVisitError = const ApiException(
        statusCode: 422,
        message: 'You are too far from this site to start a visit.',
      );

      await controller().startVisit(purpose: 'Snag list');

      expect(
        state().submitError,
        'You are too far from this site to start a visit.',
      );
    });
  });
}
