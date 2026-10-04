import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';

/// `GET /attendance/my-earnings?month=YYYY-MM` — what each day the
/// signed-in employee worked is worth, and the month so far.
///
/// Every figure is the server's: the day rate is the monthly salary divided
/// by the same divisor payroll uses. It is an estimate — the payslip adds
/// allowances, overtime, leave and deductions.
class MyEarnings {
  const MyEarnings({
    required this.month,
    required this.available,
    required this.currency,
    required this.dailyRate,
    required this.daysWorked,
    required this.total,
    required this.payByAttendance,
  });

  final String month;
  final bool available;
  final String currency;
  final num? dailyRate;
  final int daysWorked;
  final num? total;

  /// Attendance row id → that day's pay.
  final Map<int, num> payByAttendance;

  factory MyEarnings.fromJson(Map<String, dynamic> json) {
    final days = json['days'];
    return MyEarnings(
      month: json['month'] as String? ?? '',
      available: json['available'] == true,
      currency: json['currency'] as String? ?? '',
      dailyRate: json['daily_rate'] is num ? json['daily_rate'] as num : null,
      daysWorked: json['days_worked'] is int ? json['days_worked'] as int : 0,
      total: json['total'] is num ? json['total'] as num : null,
      payByAttendance: {
        if (days is List)
          for (final day in days)
            if (day is Map && day['attendance_id'] is int && day['pay'] is num)
              day['attendance_id'] as int: day['pay'] as num,
      },
    );
  }
}

abstract class MyEarningsRepository {
  Future<MyEarnings> forMonth(String month);
}

class ApiMyEarningsRepository implements MyEarningsRepository {
  ApiMyEarningsRepository(this._client);

  final ApiClient _client;

  @override
  Future<MyEarnings> forMonth(String month) async {
    final data = (await _client.get(
      '/attendance/my-earnings',
      query: <String, Object?>{'month': month},
    )).data;
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return MyEarnings.fromJson(data);
  }
}

final myEarningsRepositoryProvider = Provider<MyEarningsRepository>(
  (ref) => ApiMyEarningsRepository(ref.watch(apiClientProvider)),
);

final myEarningsProvider = FutureProvider.autoDispose
    .family<MyEarnings, String>(
      (ref, month) => ref.watch(myEarningsRepositoryProvider).forMonth(month),
    );

String formatMoney(String currency, num amount) {
  final fixed = amount.toStringAsFixed(2);
  final parts = fixed.split('.');
  final whole = parts[0].replaceAllMapped(
    RegExp(r'(\d)(?=(\d{3})+$)'),
    (m) => '${m[1]},',
  );
  return '${currency.isEmpty ? '' : '$currency '}$whole.${parts[1]}';
}
