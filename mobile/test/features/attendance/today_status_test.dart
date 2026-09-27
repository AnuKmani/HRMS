import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/domain/attendance_record.dart';
import 'package:mobile/features/attendance/domain/movement_event.dart';
import 'package:mobile/features/attendance/domain/today_status.dart';

import '../../support/attendance.dart';

/// Parsing, and only parsing: the shapes `GET /attendance/today`,
/// `GET /attendance`, `GET /site-visits/today` and `GET /movement/today`
/// actually send, read into the models the screen trusts.
///
/// The uninteresting half of this file — decimal-as-string, missing nulls —
/// is the half that breaks first whenever a column type changes on the
/// server.
void main() {
  group('today', () {
    test('reads the whole contract, including the figures that decide', () {
      final status = TodayStatus.fromJson(todayJson());

      expect(status.date, '2026-09-27');
      expect(status.serverTime, '2026-09-27T10:00:00+00:00');
      expect(status.employeeId, 10);

      expect(status.checkedIn, isFalse);
      expect(status.checkedOut, isFalse);
      expect(status.canCheckIn, isTrue);
      expect(status.canCheckOut, isFalse);
      expect(status.canStartSiteVisit, isTrue);

      expect(status.sites, hasLength(1));
      expect(status.site?.name, 'Whitefield Yard');
      expect(status.site?.latitude, closeTo(12.9716, 0.0001));
      expect(status.site?.projectName, 'Metro Line 3');

      expect(status.shift?.startsAt, '09:00');
      expect(status.shift?.graceMinutes, 10);
      expect(status.shift?.minimumWorkingMinutes, 480);
      expect(status.shift?.crossesMidnight, isFalse);

      // Sent by the server so the phone's advisory fence uses the same
      // ceiling instead of a copy that would drift.
      expect(status.maxGpsAccuracyMetres, 100);
    });

    test('carries the open day when one is still running', () {
      final status = TodayStatus.fromJson(
        todayJson(
          checkedIn: true,
          canCheckIn: false,
          canCheckOut: true,
          attendance: attendanceJson(),
          openAttendance: attendanceJson(),
          workingMinutes: 175,
          lateMinutes: 5,
        ),
      );

      expect(status.checkedIn, isTrue);
      expect(status.canCheckIn, isFalse);
      expect(status.canCheckOut, isTrue);
      expect(status.attendance?.checkInAt, '2026-09-27T09:05:00+00:00');
      expect(status.attendance?.checkOutAt, isNull);
      expect(status.openAttendance, isNotNull);
      expect(status.workingMinutes, 175);
      expect(status.lateMinutes, 5);
    });

    test('the booleans are the server\'s, never inferred here', () {
      // The server can refuse a check-in the screen would happily offer —
      // an unclosed yesterday, say. Whatever it sends is what the buttons
      // obey.
      final status = TodayStatus.fromJson(
        todayJson(canCheckIn: false, canCheckOut: false),
      );

      expect(status.canCheckIn, isFalse);
      expect(status.canCheckOut, isFalse);
    });

    test('a day with no site yet is not a crash', () {
      final status = TodayStatus.fromJson(
        todayJson(siteId: null, sites: const []),
      );

      expect(status.site, isNull);
      expect(status.sites, isEmpty);
      expect(status.siteById(1), isNull);
    });

    test('the site lookup returns null for an id nobody offered', () {
      final status = TodayStatus.fromJson(todayJson(siteId: 1));

      expect(status.siteById(999), isNull);
      expect(status.siteById(1), isNotNull);
    });
  });

  group('attendance record', () {
    test('decimals arrive as strings and leave as numbers', () {
      final record = AttendanceRecord.fromJson(attendanceJson());

      expect(record.checkInLatitude, closeTo(12.9716, 0.0001));
      expect(record.checkInAccuracy, closeTo(9, 0.01));
      expect(record.checkInDistance, closeTo(4.2, 0.01));
      expect(record.workingMinutes, 0);
      expect(record.breakMinutes, 60);
      expect(record.isOpen, isTrue);
    });

    test('a closed day is not open', () {
      final record = AttendanceRecord.fromJson(
        attendanceJson(checkOutAt: '2026-09-27T18:00:00+00:00'),
      );

      expect(record.isOpen, isFalse);
    });

    test('the status vocabulary maps to the words the app shows', () {
      expect(attendanceRecord(status: 'present').statusLabel, 'Present');
      expect(attendanceRecord(status: 'late').statusLabel, 'Late');
      expect(attendanceRecord(status: 'incomplete').statusLabel, 'Left early');
      expect(
        attendanceRecord(status: 'missing_checkout').statusLabel,
        'No check-out recorded',
      );
      expect(
        attendanceRecord(status: 'manually_adjusted').statusLabel,
        'Adjusted by HR',
      );

      // An unknown status is shown, not swallowed: a word the app does not
      // recognise yet is still better than a blank cell.
      expect(
        attendanceRecord(status: 'something_new').statusLabel,
        'something_new',
      );
    });

    test('the photograph is a fact, not a file', () {
      final withPhoto = attendanceJson(hasSelfie: true);
      final withoutPhoto = attendanceJson(hasSelfie: false);

      // Whatever else the server adds later, this model only ever learns
      // that a selfie exists. There is no path to render, log or paste into
      // an error report — the endpoint that serves it is a different one.
      expect(withPhoto.keys, isNot(contains('check_in_selfie_path')));
      expect(AttendanceRecord.fromJson(withPhoto).hasSelfie, isTrue);
      expect(AttendanceRecord.fromJson(withoutPhoto).hasSelfie, isFalse);
    });
  });

  group('site visit', () {
    test('reads the bounded episode, open and closed', () {
      final open = SiteVisit.fromJson(visitJson());
      expect(open.isOpen, isTrue);
      expect(open.durationMinutes, isNull);
      expect(open.purpose, 'Material delivery');

      final closed = SiteVisit.fromJson(
        visitJson(endedAt: '2026-09-27T12:05:00+00:00', durationMinutes: 45),
      );
      expect(closed.isOpen, isFalse);
      expect(closed.durationMinutes, 45);
      expect(closed.siteName, 'Whitefield Yard');
      expect(closed.startDistance, closeTo(4.2, 0.01));
    });
  });

  group('movement timeline', () {
    test('reads the four kinds of act and nothing else', () {
      final event = MovementEvent.fromJson(const <String, dynamic>{
        'type': 'site_visit_end',
        'at': '2026-09-27T12:05:00+00:00',
        'record_id': 300,
        'site_id': 1,
        'site_name': 'Whitefield Yard',
        'project_id': 7,
        'project_name': 'Metro Line 3',
        'label': 'Ended visit to Whitefield Yard',
        'duration_minutes': 45,
        'purpose': 'Material delivery',
      });

      expect(event.type, 'site_visit_end');
      expect(event.at, '2026-09-27T12:05:00+00:00');
      expect(event.durationMinutes, 45);
      expect(event.isArrival, isFalse);
      expect(event.isDeparture, isFalse);
    });

    test('check in and check out identify themselves', () {
      const base = <String, dynamic>{
        'at': '2026-09-27T09:05:00+00:00',
        'record_id': 501,
        'label': 'Checked in',
      };

      expect(
        MovementEvent.fromJson({...base, 'type': 'check_in'}).isArrival,
        isTrue,
      );
      expect(
        MovementEvent.fromJson({...base, 'type': 'check_out'}).isDeparture,
        isTrue,
      );
    });

    test('a payload with no record yet does not throw', () {
      // The service only ever sends complete rows; this asserts the model
      // degrades rather than exploding if one arrives early.
      final event = MovementEvent.fromJson(const <String, dynamic>{
        'type': 'check_in',
        'at': '2026-09-27T09:05:00+00:00',
        'label': 'Checked in',
      });

      expect(event.recordId, 0);
      expect(event.siteId, isNull);
    });
  });
}
