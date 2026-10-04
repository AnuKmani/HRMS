import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/payroll.dart';

/// Asking for a payslip, and HR answering. Each call returns the payroll
/// row as the server now sees it, `can_download` included.
abstract class SlipRequestsRepository {
  Future<Payroll> request(int payrollId);
  Future<Payroll> approve(int payrollId);
  Future<Payroll> reject(int payrollId);
}

class ApiSlipRequestsRepository implements SlipRequestsRepository {
  ApiSlipRequestsRepository(this._client);

  final ApiClient _client;

  @override
  Future<Payroll> request(int payrollId) => _post(payrollId, 'request');

  @override
  Future<Payroll> approve(int payrollId) => _post(payrollId, 'approve');

  @override
  Future<Payroll> reject(int payrollId) => _post(payrollId, 'reject');

  Future<Payroll> _post(int payrollId, String action) async {
    final data = (await _client.post('/salary-slips/$payrollId/$action')).data;
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return Payroll.fromJson(data);
  }
}

final slipRequestsRepositoryProvider = Provider<SlipRequestsRepository>(
  (ref) => ApiSlipRequestsRepository(ref.watch(apiClientProvider)),
);
