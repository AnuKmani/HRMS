import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';

/// `GET /dashboards/employee` — the caller's own day at a glance.
///
/// The server decides which blocks exist (one permission per block) and
/// scopes every figure to the caller. A block that is absent here is drawn
/// as nothing: the client never infers a number the server did not send.
class MySummary {
  const MySummary({
    this.attendance,
    this.leavePending,
    this.leaveAvailable,
    this.payslip,
  });

  final TodayAttendance? attendance;

  /// Requests of yours still waiting on an approver.
  final int? leavePending;

  /// Days left this year across every leave type, after pending requests.
  final num? leaveAvailable;

  final LatestPayslip? payslip;

  bool get isEmpty =>
      attendance == null &&
      leavePending == null &&
      leaveAvailable == null &&
      payslip == null;

  factory MySummary.fromJson(Map<String, dynamic> json) {
    final blocks = json['blocks'];
    final map = blocks is Map<String, dynamic>
        ? blocks
        : const <String, dynamic>{};

    final attendance = map['attendance'];
    final leave = map['leave'];
    final balances = map['balances'];
    final payroll = map['payroll'];

    return MySummary(
      attendance: attendance is Map<String, dynamic>
          ? TodayAttendance.fromJson(attendance)
          : null,
      leavePending: leave is Map<String, dynamic> && leave['pending'] is int
          ? leave['pending'] as int
          : null,
      leaveAvailable:
          balances is Map<String, dynamic> && balances['available'] is num
          ? balances['available'] as num
          : null,
      payslip: payroll is Map<String, dynamic> && payroll['has_slip'] == true
          ? LatestPayslip.fromJson(payroll)
          : null,
    );
  }
}

class TodayAttendance {
  const TodayAttendance({this.checkedInAt, this.checkedOutAt});

  final DateTime? checkedInAt;
  final DateTime? checkedOutAt;

  factory TodayAttendance.fromJson(Map<String, dynamic> json) =>
      TodayAttendance(
        checkedInAt: DateTime.tryParse(json['checked_in_at'] as String? ?? ''),
        checkedOutAt: DateTime.tryParse(
          json['checked_out_at'] as String? ?? '',
        ),
      );
}

class LatestPayslip {
  const LatestPayslip({
    required this.period,
    required this.netSalary,
    required this.currency,
  });

  final String period;
  final num netSalary;
  final String currency;

  factory LatestPayslip.fromJson(Map<String, dynamic> json) => LatestPayslip(
    period: json['period'] as String? ?? '',
    netSalary: json['net_salary'] is num ? json['net_salary'] as num : 0,
    currency: json['currency'] as String? ?? '',
  );
}

abstract class MySummaryRepository {
  Future<MySummary> fetch();
}

class ApiMySummaryRepository implements MySummaryRepository {
  ApiMySummaryRepository(this._client);

  final ApiClient _client;

  @override
  Future<MySummary> fetch() async {
    final data = (await _client.get('/dashboards/employee')).data;
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return MySummary.fromJson(data);
  }
}

final mySummaryRepositoryProvider = Provider<MySummaryRepository>(
  (ref) => ApiMySummaryRepository(ref.watch(apiClientProvider)),
);

final mySummaryProvider = FutureProvider.autoDispose<MySummary>(
  (ref) => ref.watch(mySummaryRepositoryProvider).fetch(),
);
