import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../domain/attendance_record.dart';

final attendanceRecordsRepositoryProvider =
    Provider<AttendanceRecordsRepository>(
      (ref) => ApiAttendanceRecordsRepository(ref.watch(apiClientProvider)),
    );

/// Reading attendance after the fact — `GET /attendance` and the selfie.
///
/// Kept apart from [AttendanceRepository], which is the caller's own day
/// and its writes. *Whose* rows come back is the server's answer
/// (`Visibility::attendanceFor`): HR and management read everyone, a
/// supervisor their sites, an employee only their own.
abstract class AttendanceRecordsRepository {
  Future<PageResult<AttendanceRecord>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// `GET /site-visits` — trips between sites during the day, scoped by the
  /// same row rule as attendance.
  Future<PageResult<SiteVisit>> visits({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// The check-in selfie as image bytes. Behind the same row-level rule as
  /// the record itself, so a 403 here means "not yours to see".
  Future<Uint8List> selfie(int attendanceId);
}

class ApiAttendanceRecordsRepository implements AttendanceRecordsRepository {
  ApiAttendanceRecordsRepository(this._client);

  final ApiClient _client;

  @override
  Future<PageResult<AttendanceRecord>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '/attendance',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<AttendanceRecord>.fromEnvelope(
      envelope,
      AttendanceRecord.fromJson,
    );
  }

  @override
  Future<PageResult<SiteVisit>> visits({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      '/site-visits',
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<SiteVisit>.fromEnvelope(envelope, SiteVisit.fromJson);
  }

  @override
  Future<Uint8List> selfie(int attendanceId) =>
      _client.bytes('/attendance/$attendanceId/selfie');
}
