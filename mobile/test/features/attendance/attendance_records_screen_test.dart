import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/attendance/data/attendance_records_repository.dart';
import 'package:mobile/features/attendance/domain/attendance_record.dart';
import 'package:mobile/features/attendance/presentation/attendance_records_screen.dart';

import '../../support/fakes.dart';

// A 1×1 transparent PNG: enough for Image.memory to decode.
final _png = Uint8List.fromList(<int>[
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D, //
  0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, 0x89, 0x00, 0x00, 0x00,
  0x0D, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9C, 0x63, 0x00, 0x01, 0x00, 0x00,
  0x05, 0x00, 0x01, 0x0D, 0x0A, 0x2D, 0xB4, 0x00, 0x00, 0x00, 0x00, 0x49,
  0x45, 0x4E, 0x44, 0xAE, 0x42, 0x60, 0x82,
]);

class FakeRecords implements AttendanceRecordsRepository {
  final List<Map<String, Object?>> queries = [];
  final List<int> selfies = [];

  @override
  Future<PageResult<AttendanceRecord>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    queries.add(query);
    final items = [
      AttendanceRecord.fromJson({
        'id': 41,
        'employee_id': 7,
        'employee': {'full_name': 'Asha Nair', 'employee_code': 'EMP-007'},
        'site_id': 3,
        'site': {'id': 3, 'name': 'Marina Tower'},
        'attendance_date': '2026-10-04',
        'check_in_at': '2026-10-04T08:58:00+00:00',
        'check_in_latitude': '25.0800000',
        'check_in_longitude': '55.1400000',
        'check_in_distance': '12.4',
        'has_selfie': true,
        'check_in_purpose': 'Chiller installation',
        'status': 'present',
      }),
    ];

    return PageResult<AttendanceRecord>(
      items: items,
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: items.length,
      hasNext: false,
    );
  }

  @override
  Future<PageResult<SiteVisit>> visits({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final items = [
      SiteVisit.fromJson({
        'id': 9,
        'employee_id': 7,
        'employee': {'full_name': 'Asha Nair', 'employee_code': 'EMP-007'},
        'site_id': 4,
        'site': {'id': 4, 'name': 'Jumeirah Villa'},
        'started_at': '2026-10-04T10:30:00+00:00',
        'ended_at': '2026-10-04T12:15:00+00:00',
        'duration_minutes': 105,
        'purpose': 'Snag inspection',
        'start_latitude': '25.1000000',
        'start_longitude': '55.2000000',
        'status': 'completed',
      }),
    ];

    return PageResult<SiteVisit>(
      items: items,
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: items.length,
      hasNext: false,
    );
  }

  @override
  Future<Uint8List> selfie(int attendanceId) async {
    selfies.add(attendanceId);
    return _png;
  }
}

void main() {
  testWidgets('HR sees who checked in, and the selfie behind it', (
    tester,
  ) async {
    final records = FakeRecords();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          attendanceRecordsRepositoryProvider.overrideWithValue(records),
          // HR: reads everyone's day, so the list opens on today.
          permissionScopeProvider.overrideWithValue(
            PermissionScope(
              buildUser(
                permissions: const ['attendance.view', 'employees.view'],
              ),
            ),
          ),
        ],
        child: const MaterialApp(home: AttendanceRecordsScreen()),
      ),
    );
    await tester.pumpAndSettle();

    // Opens on today.
    expect(records.queries.first['date'], isNotNull);
    expect(find.text('Asha Nair (EMP-007)'), findsOneWidget);
    expect(find.text('Present'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('attendance-record-41')));
    await tester.pumpAndSettle();

    expect(records.selfies, [41]);
    expect(find.byKey(const ValueKey('attendance-selfie')), findsOneWidget);
    expect(find.text('25.08000, 55.14000'), findsOneWidget);
    expect(find.text('12 m'), findsOneWidget);
    expect(find.text('Chiller installation'), findsOneWidget);
  });

  testWidgets('the site visits tab shows who went where, when and why', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          attendanceRecordsRepositoryProvider.overrideWithValue(FakeRecords()),
        ],
        child: const MaterialApp(home: AttendanceRecordsScreen()),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('tab-site-visits')));
    await tester.pumpAndSettle();

    expect(find.text('Asha Nair (EMP-007)'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('site-visit-9')));
    await tester.pumpAndSettle();

    expect(find.text('Snag inspection'), findsWidgets);
    expect(find.text('Jumeirah Villa'), findsWidgets);
    expect(find.text('1h 45m'), findsWidgets);
    expect(find.text('25.10000, 55.20000'), findsOneWidget);
  });
}
