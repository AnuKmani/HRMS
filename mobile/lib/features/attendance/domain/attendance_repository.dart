import 'dart:typed_data';

import 'attendance_record.dart';
import 'location_fix.dart';
import 'movement_event.dart';
import 'today_status.dart';

/// A check-in, assembled.
///
/// Everything the endpoint derives for itself — the employee, the date, the
/// project, the distances, the lateness, the status — is deliberately absent
/// from this type. What is here is what only the phone in that person's hand
/// could know: where they are, how good the reading is, the photograph, and
/// the key that makes a retry safe.
class CheckInSubmission {
  const CheckInSubmission({
    required this.siteId,
    required this.fix,
    required this.clientEventId,
    required this.deviceReference,
    required this.selfieBytes,
    this.source = 'online',
  });

  final int siteId;
  final LocationFix fix;
  final String clientEventId;
  final String deviceReference;
  final Uint8List selfieBytes;

  /// `online` when the request goes straight out, `offline` when it was
  /// queued on the device and is being replayed now. Never `manual` — the
  /// server refuses that from a client, and so does this type.
  final String source;
}

class CheckOutSubmission {
  const CheckOutSubmission({
    required this.siteId,
    required this.fix,
    required this.clientEventId,
    required this.deviceReference,
    this.source = 'online',
  });

  final int siteId;
  final LocationFix fix;
  final String clientEventId;
  final String deviceReference;
  final String source;
}

class SiteVisitStartSubmission {
  const SiteVisitStartSubmission({
    required this.siteId,
    required this.fix,
    required this.purpose,
    required this.clientEventId,
    required this.deviceReference,
    this.remarks,
  });

  final int siteId;
  final LocationFix fix;
  final String purpose;
  final String? remarks;
  final String clientEventId;
  final String deviceReference;
}

class SiteVisitEndSubmission {
  const SiteVisitEndSubmission({
    required this.siteVisitId,
    required this.fix,
    required this.clientEventId,
    this.remarks,
  });

  final int siteVisitId;
  final LocationFix fix;
  final String? remarks;

  /// The *end* of a visit is its own queued event with its own key — the
  /// server keeps `end_client_event_id` separately from `client_event_id`,
  /// so starting and closing can be retried independently without either
  /// one pretending to be the other.
  final String clientEventId;
}

/// How the attendance feature reaches the server.
///
/// One interface for all six calls so a test can script the whole day
/// without a socket, and so the offline queue can be given the same
/// repository it replays through.
abstract class AttendanceRepository {
  Future<TodayStatus> today();

  Future<AttendanceRecord> checkIn(CheckInSubmission submission);

  Future<AttendanceRecord> checkOut(CheckOutSubmission submission);

  /// The caller's own visits today, in order.
  Future<List<SiteVisit>> siteVisitsToday();

  Future<SiteVisit> startSiteVisit(SiteVisitStartSubmission submission);

  Future<SiteVisit> endSiteVisit(SiteVisitEndSubmission submission);

  /// The caller's own day as `check_in` / `site_visit_start` /
  /// `site_visit_end` / `check_out`, oldest first.
  Future<List<MovementEvent>> movementToday();
}
