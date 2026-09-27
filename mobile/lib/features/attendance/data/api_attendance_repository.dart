import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/attendance_record.dart';
import '../domain/attendance_repository.dart';
import '../domain/movement_event.dart';
import '../domain/today_status.dart';

final attendanceRepositoryProvider = Provider<AttendanceRepository>(
  (ref) => ApiAttendanceRepository(ref.watch(apiClientProvider)),
);

class ApiAttendanceRepository implements AttendanceRepository {
  ApiAttendanceRepository(this._client);

  static const _attendance = '/attendance';
  static const _visits = '/site-visits';
  static const _movement = '/movement';

  final ApiClient _client;

  @override
  Future<TodayStatus> today() async {
    final envelope = await _client.get('$_attendance/today');
    final data = envelope.data;

    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return TodayStatus.fromJson(data);
  }

  @override
  Future<AttendanceRecord> checkIn(CheckInSubmission submission) async {
    final fields = <String, Object?>{
      'site_id': submission.siteId,
      'latitude': submission.fix.latitude,
      'longitude': submission.fix.longitude,
      'accuracy': submission.fix.accuracy,
      'client_event_id': submission.clientEventId,
      'device_reference': submission.deviceReference,
      'source': submission.source,
    };

    final envelope = await _client.postMultipart(
      '$_attendance/check-in',
      fields: fields,
      files: {
        'selfie': MultipartFile.fromBytes(
          submission.selfieBytes,
          filename: 'selfie.jpg',
          contentType: DioMediaType('image', 'jpeg'),
        ),
      },
    );

    return _one(envelope.data, AttendanceRecord.fromJson);
  }

  @override
  Future<AttendanceRecord> checkOut(CheckOutSubmission submission) async {
    final envelope = await _client.post(
      '$_attendance/check-out',
      body: <String, Object?>{
        'site_id': submission.siteId,
        'latitude': submission.fix.latitude,
        'longitude': submission.fix.longitude,
        'accuracy': submission.fix.accuracy,
        'client_event_id': submission.clientEventId,
        'device_reference': submission.deviceReference,
        'source': submission.source,
      },
    );

    return _one(envelope.data, AttendanceRecord.fromJson);
  }

  @override
  Future<List<SiteVisit>> siteVisitsToday() async {
    final envelope = await _client.get('$_visits/today');

    return _list(envelope.data, SiteVisit.fromJson);
  }

  @override
  Future<SiteVisit> startSiteVisit(SiteVisitStartSubmission submission) async {
    final envelope = await _client.post(
      '$_visits/start',
      body: <String, Object?>{
        'site_id': submission.siteId,
        'latitude': submission.fix.latitude,
        'longitude': submission.fix.longitude,
        'accuracy': submission.fix.accuracy,
        'purpose': submission.purpose,
        if (submission.remarks != null) 'remarks': submission.remarks,
        'client_event_id': submission.clientEventId,
        'device_reference': submission.deviceReference,
      },
    );

    return _one(envelope.data, SiteVisit.fromJson);
  }

  @override
  Future<SiteVisit> endSiteVisit(SiteVisitEndSubmission submission) async {
    final envelope = await _client.post(
      '$_visits/${submission.siteVisitId}/end',
      body: <String, Object?>{
        'latitude': submission.fix.latitude,
        'longitude': submission.fix.longitude,
        'accuracy': submission.fix.accuracy,
        if (submission.remarks != null) 'remarks': submission.remarks,
        'client_event_id': submission.clientEventId,
      },
    );

    return _one(envelope.data, SiteVisit.fromJson);
  }

  @override
  Future<List<MovementEvent>> movementToday() async {
    final envelope = await _client.get('$_movement/today');

    return _list(envelope.data, MovementEvent.fromJson);
  }

  T _one<T>(Object? data, T Function(Map<String, dynamic>) parse) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return parse(data);
  }

  List<T> _list<T>(Object? data, T Function(Map<String, dynamic>) parse) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    final items = data['items'];
    if (items is! List) throw unexpectedShapeException;

    return [
      for (final row in items)
        if (row is Map<String, dynamic>) parse(row),
    ];
  }
}
