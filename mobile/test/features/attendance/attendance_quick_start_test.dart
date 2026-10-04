import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/presentation/attendance_quick_card.dart';
import 'package:mobile/features/attendance/presentation/attendance_screen.dart';

import '../../support/attendance.dart';

/// The dashboard's one-tap button, and the screen it hands over to.
///
/// The button records nothing itself; what is under test is that it says
/// what the server allows, and that the screen it opens starts that one
/// action through the ordinary path — selfie and all — and only when the
/// ordinary path would allow it.
void main() {
  late AttendanceHarness harness;

  setUp(() {
    harness = AttendanceHarness();
  });

  Future<void> tap(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.tap(finder);
    await tester.pump();
  }

  group('opened with a start action', () {
    testWidgets('check-in opens the selfie sheet by itself', (tester) async {
      await pumpAttendance(
        tester,
        harness,
        child: const AttendanceScreen(startAction: AttendanceScreen.checkIn),
      );
      await settle(tester);
      await tester.pump(const Duration(milliseconds: 300));

      // Where and why first — the GPS location shown, the purpose asked.
      expect(find.byKey(const ValueKey('check-in-site')), findsOneWidget);
      expect(find.textContaining('Your current location'), findsOneWidget);
      await tester.tap(find.text('Meeting'));
      await tester.pump();
      await tap(tester, find.byKey(const ValueKey('check-in-continue')));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Check-in photo'), findsOneWidget);

      await tap(tester, find.byType(FloatingActionButton));
      await tester.pump(const Duration(milliseconds: 100));
      await tap(tester, find.text('Use photo'));
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);

      expect(harness.repository.checkInCalls, 1);
      expect(harness.camera.captures, 1);
      expect(harness.repository.checkIns.last.purpose, 'Meeting');
      expect(harness.repository.checkIns.last.siteId, isNull);

      // Then the reminder to check out in the evening.
      expect(find.byKey(const ValueKey('checked-in-dialog')), findsOneWidget);
      expect(find.textContaining('CHECK OUT in the evening'), findsOneWidget);
      await tap(tester, find.byKey(const ValueKey('checked-in-ok')));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.byKey(const ValueKey('checked-in-dialog')), findsNothing);
    });

    testWidgets('check-out submits by itself, once', (tester) async {
      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
      );

      await pumpAttendance(
        tester,
        harness,
        child: const AttendanceScreen(startAction: AttendanceScreen.checkOut),
      );
      await settle(tester);
      await settle(tester);

      expect(harness.repository.checkOutCalls, 1);
    });

    testWidgets('visit asks why, then starts the visit', (tester) async {
      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
      );

      await pumpAttendance(
        tester,
        harness,
        child: const AttendanceScreen(startAction: AttendanceScreen.visit),
      );
      await settle(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Start site visit'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('visit-purpose')),
        'Snag inspection',
      );
      await tester.pump();
      await tap(tester, find.text('Start'));
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);

      expect(harness.repository.startVisitCalls, 1);
    });

    testWidgets('check-in starts even with no site at all', (tester) async {
      harness.repository.todayResult = todayStatus(
        siteId: null,
        canStartSiteVisit: false,
      );

      await pumpAttendance(
        tester,
        harness,
        child: const AttendanceScreen(startAction: AttendanceScreen.checkIn),
      );
      await settle(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byKey(const ValueKey('check-in-purpose')), findsOneWidget);
    });

    testWidgets('a visit never starts for a role without the option', (
      tester,
    ) async {
      harness.repository.todayResult = todayStatus(canStartSiteVisit: false);

      await pumpAttendance(
        tester,
        harness,
        child: const AttendanceScreen(startAction: AttendanceScreen.visit),
      );
      await settle(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Start site visit'), findsNothing);
    });

    testWidgets('a plain visit starts nothing', (tester) async {
      await pumpAttendance(tester, harness, child: const AttendanceScreen());
      await settle(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Check-in photo'), findsNothing);
    });
  });

  group('the dashboard card', () {
    Future<List<String>> pumpCard(WidgetTester tester) async {
      final opened = <String>[];
      await pumpAttendance(
        tester,
        harness,
        child: Scaffold(body: AttendanceQuickCard(onOpen: opened.add)),
      );
      await settle(tester);
      return opened;
    }

    testWidgets('offers CHECK IN before the day starts', (tester) async {
      final opened = await pumpCard(tester);

      expect(find.text('You have not checked in today'), findsOneWidget);
      await tap(tester, find.byKey(const ValueKey('quick-check-in')));

      expect(opened, ['/attendance?start=check-in']);
    });

    testWidgets('offers CHECK OUT once checked in', (tester) async {
      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
      );
      final opened = await pumpCard(tester);

      expect(find.text('You are checked in'), findsOneWidget);
      expect(find.byKey(const ValueKey('quick-check-in')), findsNothing);
      expect(find.byKey(const ValueKey('quick-site-visit')), findsOneWidget);
      await tap(tester, find.byKey(const ValueKey('quick-check-out')));

      expect(opened, ['/attendance?start=check-out']);
    });

    testWidgets('offers CHECK IN with no site at all', (tester) async {
      harness.repository.todayResult = todayStatus(siteId: null);
      await pumpCard(tester);

      expect(find.byKey(const ValueKey('quick-check-in')), findsOneWidget);
    });

    testWidgets('no SITE VISIT for a role without the option', (tester) async {
      harness.repository.todayResult = todayStatus(
        checkedIn: true,
        canCheckIn: false,
        canCheckOut: true,
        canStartSiteVisit: false,
      );
      await pumpCard(tester);

      expect(find.byKey(const ValueKey('quick-site-visit')), findsNothing);
      expect(find.byKey(const ValueKey('quick-check-out')), findsOneWidget);
    });
  });
}
