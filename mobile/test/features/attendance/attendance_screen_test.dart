import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/attendance/data/device_location.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/features/attendance/presentation/attendance_screen.dart';

import '../../support/attendance.dart';

/// The screen itself, with every piece of hardware faked.
///
/// What is being exercised here is the part a person actually meets: a
/// spinner that means "wait", a button that refuses to light up until the
/// phone can see the sky, a photograph that has to be taken before
/// anything is sent, and a server's refusal arriving in words they can do
/// something about.
void main() {
  late AttendanceHarness harness;

  setUp(() {
    harness = AttendanceHarness();
  });

  Future<void> open(WidgetTester tester) async {
    await pumpAttendance(tester, harness, child: const AttendanceScreen());
    await settle(tester);
  }

  Future<void> tap(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.tap(finder);
    await tester.pump();
  }

  /// Opens the sheet, takes the photograph, and accepts it.
  Future<void> takeSelfie(WidgetTester tester) async {
    await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Check-in photo'), findsOneWidget);

    await tap(tester, find.byType(FloatingActionButton));
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Retake'), findsOneWidget);

    await tap(tester, find.text('Use photo'));
    await tester.pump(const Duration(milliseconds: 300));
    await settle(tester);
  }

  FilledButton checkInButton(WidgetTester tester) => tester
      .widget<FilledButton>(find.byKey(const ValueKey('attendance-check-in')));

  group('the first frame', () {
    testWidgets('shows nothing actionable until the day is known', (
      tester,
    ) async {
      harness.repository.todayHold = Completer<void>();

      await pumpAttendance(tester, harness, child: const AttendanceScreen());
      await tester.pump();

      expect(find.byKey(const ValueKey('attendance-loading')), findsOneWidget);
      expect(find.byKey(const ValueKey('attendance-check-in')), findsNothing);

      harness.repository.todayHold!.complete();
      await settle(tester);

      expect(find.byKey(const ValueKey('attendance-today')), findsOneWidget);
      expect(find.byKey(const ValueKey('attendance-check-in')), findsOneWidget);
    });

    testWidgets('a failure to load offers one way back', (tester) async {
      harness.repository.todayError = const ApiException(
        statusCode: 503,
        message: 'The service is temporarily unavailable.',
      );

      await open(tester);

      expect(find.byKey(const ValueKey('attendance-error')), findsOneWidget);
      expect(
        find.text('The service is temporarily unavailable.'),
        findsOneWidget,
      );

      // The error was consumed; the retry lands on a working screen.
      await tap(tester, find.text('Try again'));
      await settle(tester);

      expect(find.byKey(const ValueKey('attendance-today')), findsOneWidget);
    });
  });

  group('the ready screen', () {
    testWidgets('names the day, the site and the shift', (tester) async {
      await open(tester);

      expect(find.byKey(const ValueKey('attendance-today')), findsOneWidget);
      expect(find.text('2026-09-27'), findsOneWidget);
      expect(find.text('Whitefield Yard · Metro Line 3'), findsOneWidget);
      expect(find.textContaining('09:00–18:00'), findsOneWidget);
      expect(find.textContaining('grace 10 min'), findsOneWidget);
    });

    testWidgets('offers a check-in before one and a check-out after', (
      tester,
    ) async {
      await open(tester);

      expect(find.byKey(const ValueKey('attendance-check-in')), findsOneWidget);
      expect(find.byKey(const ValueKey('attendance-check-out')), findsNothing);

      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
      );

      await tap(tester, find.byTooltip('Refresh'));
      await settle(tester);

      expect(find.byKey(const ValueKey('attendance-check-in')), findsNothing);
      expect(
        find.byKey(const ValueKey('attendance-check-out')),
        findsOneWidget,
      );
    });

    testWidgets('a second site turns into a picker', (tester) async {
      harness.repository.todayResult = todayStatus(siteId: 1, siteIds: [1, 2]);

      await open(tester);

      expect(find.text('Current site'), findsOneWidget);
      expect(
        find.textContaining('Whitefield Yard · Metro Line 3'),
        findsOneWidget,
      );
    });

    testWidgets('an open visit is offered for closing, not for starting', (
      tester,
    ) async {
      await open(tester);

      expect(find.text('START SITE VISIT'), findsOneWidget);

      // A second read, now with an open episode on the server.
      harness.repository.todayVisits = [siteVisit()];
      await tap(tester, find.byTooltip('Refresh'));
      await settle(tester);

      expect(find.text('END SITE VISIT'), findsOneWidget);
    });
  });

  group('location', () {
    testWidgets('a refused permission disables the check-in and says why', (
      tester,
    ) async {
      harness.location.permission = LocationStatus.denied;

      await open(tester);

      expect(find.byKey(const ValueKey('attendance-location')), findsOneWidget);
      expect(checkInButton(tester).onPressed, isNull);
      expect(find.text('Allow location'), findsOneWidget);
      expect(
        find.textContaining('Location is needed before any of these'),
        findsOneWidget,
      );
    });

    testWidgets('a permanently refused permission points at Settings', (
      tester,
    ) async {
      harness.location.permission = LocationStatus.permanentlyDenied;
      harness.location.requestedAs = LocationStatus.permanentlyDenied;

      await open(tester);

      expect(find.text('Open Settings'), findsOneWidget);

      await tap(tester, find.text('Open Settings'));
      await settle(tester);

      expect(harness.location.appSettingsOpens, 1);
      expect(checkInButton(tester).onPressed, isNull);
    });

    testWidgets('a phone with GPS switched off is asked to turn it on', (
      tester,
    ) async {
      harness.location.serviceEnabled = false;

      await open(tester);

      expect(find.text('Turn on GPS'), findsOneWidget);
      expect(checkInButton(tester).onPressed, isNull);
    });

    testWidgets('granted and in range puts the advisory fence on screen', (
      tester,
    ) async {
      await open(tester);

      expect(checkInButton(tester).onPressed, isNotNull);
      expect(find.textContaining('Advisory'), findsOneWidget);
      expect(find.textContaining('server measures again'), findsOneWidget);
    });
  });

  group('checking in', () {
    testWidgets('walks the whole photograph flow and submits once', (
      tester,
    ) async {
      await open(tester);
      await takeSelfie(tester);

      expect(harness.repository.checkInCalls, 1);
      expect(harness.repository.checkIns.single.selfieBytes, isNotEmpty);
      expect(find.text('Checked in.'), findsOneWidget);
      expect(harness.camera.captures, 1);
    });

    testWidgets('the sheet can be backed out of without sending anything', (
      tester,
    ) async {
      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
      await tester.pump(const Duration(milliseconds: 300));

      await tap(tester, find.text('Cancel'));
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);

      expect(harness.repository.checkInCalls, 0);
      expect(harness.camera.captures, 0);
    });

    testWidgets('retake puts the person back in front of the camera', (
      tester,
    ) async {
      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
      await tester.pump(const Duration(milliseconds: 300));

      await tap(tester, find.byType(FloatingActionButton));
      await tester.pump(const Duration(milliseconds: 100));

      await tap(tester, find.text('Retake'));
      await tester.pump(const Duration(milliseconds: 100));

      // Back on the live preview, not on the review.
      expect(find.byType(FloatingActionButton), findsOneWidget);
      expect(find.text('Retake'), findsNothing);
      expect(find.text('Use photo'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Use photo'),
            )
            .onPressed,
        isNull,
        reason: 'there is nothing to use until a frame has been taken',
      );
    });

    testWidgets('while one check-in is in flight nothing else can be pressed', (
      tester,
    ) async {
      await open(tester);

      harness.repository.hold = Completer<void>();

      await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
      await tester.pump(const Duration(milliseconds: 300));

      await tap(tester, find.byType(FloatingActionButton));
      await tester.pump(const Duration(milliseconds: 100));
      await tap(tester, find.text('Use photo'));
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);

      expect(harness.repository.checkInCalls, 0);
      expect(checkInButton(tester).onPressed, isNull);

      // A second press while the first is pending does nothing at all.
      await tester.tap(
        find.byKey(const ValueKey('attendance-check-in')),
        warnIfMissed: false,
      );
      await settle(tester);

      expect(harness.repository.checkInCalls, 0);

      harness.repository.hold!.complete();
      await settle(tester);

      expect(harness.repository.checkInCalls, 1);
      expect(find.text('Checked in.'), findsOneWidget);
    });

    testWidgets('a camera that will not open explains itself instead', (
      tester,
    ) async {
      harness.camera.status = CameraStatus.permissionDenied;

      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.textContaining('camera is needed'), findsOneWidget);
      expect(find.text('Allow camera'), findsOneWidget);

      // Tapping it re-asks, and with the fake still refusing nothing is sent.
      await tap(tester, find.text('Allow camera'));
      await tester.pump(const Duration(milliseconds: 100));

      expect(harness.camera.opens, 2);
      expect(harness.repository.checkInCalls, 0);
    });

    testWidgets('a device with no camera cannot check in, and says so', (
      tester,
    ) async {
      harness.camera.status = CameraStatus.noCamera;

      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-check-in')));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.textContaining('no camera'), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsNothing);
      expect(find.text('Cancel'), findsOneWidget);
    });
  });

  group('refusals', () {
    testWidgets('a 403 is shown in the server’s words', (tester) async {
      await open(tester);

      harness.repository.checkInError = const ApiException(
        statusCode: 403,
        message: 'You are not assigned to this site.',
      );

      await takeSelfie(tester);

      expect(find.text('You are not assigned to this site.'), findsOneWidget);
      expect(harness.queue.events, isEmpty);
    });

    testWidgets('a 422 shows the reason, not the boilerplate', (tester) async {
      await open(tester);

      harness.repository.checkInError = const ApiException(
        statusCode: 422,
        message: 'The given data was invalid.',
        errors: {
          'location': 'You are 412 m from Whitefield Yard; the allowed radius is 100 m.',
        },
      );

      await takeSelfie(tester);

      expect(
        find.text(
          'You are 412 m from Whitefield Yard; the allowed radius is 100 m.',
        ),
        findsOneWidget,
      );
      expect(harness.queue.events, isEmpty);
    });

    testWidgets('a dead network queues the event and offers a sync', (
      tester,
    ) async {
      await open(tester);

      harness.repository.checkInError = const ApiException(
        statusCode: 0,
        message: 'No connection.',
      );

      await takeSelfie(tester);

      expect(find.byKey(const ValueKey('attendance-queue')), findsOneWidget);
      expect(find.text('1 event saved on this phone'), findsOneWidget);
      expect(find.text('Sync now'), findsOneWidget);
      expect(harness.queue.events, hasLength(1));
      expect(harness.queue.events.single.syncStatus, 'pending_sync');
      expect(harness.selfies.files, hasLength(1));

      // The network is back: one press, and the phone is empty again.
      await tap(tester, find.text('Sync now'));
      await settle(tester);

      expect(harness.repository.checkInCalls, 2);
      expect(harness.queue.events, isEmpty);
      expect(find.byKey(const ValueKey('attendance-queue')), findsNothing);
    });
  });

  group('site visits', () {
    testWidgets('asks why before starting, and sends it', (tester) async {
      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-site-visit')));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Why are you visiting?'), findsOneWidget);

      await tester.enterText(find.byType(TextField).first, 'Material delivery');
      await tester.tap(find.text('Start'));
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);

      expect(harness.repository.startVisitCalls, 1);
      expect(
        harness.repository.visitStarts.single.purpose,
        'Material delivery',
      );
      expect(find.text('Site visit started.'), findsOneWidget);
    });

    testWidgets('an empty purpose keeps the dialog open', (tester) async {
      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-site-visit')));
      await tester.pump(const Duration(milliseconds: 300));

      await tester.enterText(find.byType(TextField).first, '   ');
      await tester.tap(find.text('Start'));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Why are you visiting?'), findsOneWidget);
      expect(harness.repository.startVisitCalls, 0);
    });

    testWidgets('closes an open visit', (tester) async {
      harness.repository.todayVisits = [siteVisit()];

      await open(tester);

      await tap(tester, find.byKey(const ValueKey('attendance-site-visit')));
      await settle(tester);

      expect(harness.repository.endVisitCalls, 1);
      expect(find.text('Site visit ended.'), findsOneWidget);
    });
  });
}
