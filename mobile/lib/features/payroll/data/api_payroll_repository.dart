import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/payroll.dart';
import '../domain/payroll_repository.dart';

final payrollRepositoryProvider = Provider<PayrollRepository>(
  (ref) => ApiPayrollRepository(ref.watch(apiClientProvider)),
);

/// `PayrollRepository` over the real HTTP client.
class ApiPayrollRepository implements PayrollRepository {
  ApiPayrollRepository(this._client);

  static const _path = '/payroll';

  final ApiClient _client;

  @override
  Future<PageResult<Payroll>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) => _page(_path, page, query);

  @override
  Future<PageResult<Payroll>> slips({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) => _page('/salary-slips', page, query);

  @override
  Future<Payroll> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<PayrollSummary> summary({
    required int year,
    int? month,
    int? departmentId,
  }) async {
    final envelope = await _client.get(
      '$_path/summary',
      query: <String, Object?>{
        'year': year,
        'month': ?month,
        'department_id': ?departmentId,
      },
    );

    return _object(envelope.data, PayrollSummary.fromJson);
  }

  @override
  Future<PayrollRunReport> process({
    required int year,
    required int month,
    List<int>? employeeIds,
  }) async {
    final envelope = await _client.post(
      '$_path/process',
      body: <String, Object?>{
        'year': year,
        'month': month,
        'employee_ids': ?employeeIds,
      },
    );

    return _object(envelope.data, PayrollRunReport.fromJson);
  }

  @override
  Future<Payroll> recalculate(int id) async => _act(id, 'recalculate');

  @override
  Future<Payroll> review(int id) async => _act(id, 'review');

  @override
  Future<Payroll> finalize(int id) async => _act(id, 'finalize');

  @override
  Future<Payroll> lock(int id) async => _act(id, 'lock');

  @override
  Future<Uint8List> slipPdf(int id) => _client.bytes('/salary-slips/$id/pdf');

  Future<Payroll> _act(int id, String action) async =>
      _one((await _client.post('$_path/$id/$action')).data);

  Future<PageResult<Payroll>> _page(
    String path,
    int page,
    Map<String, Object?> query,
  ) async {
    final envelope = await _client.get(
      path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Payroll>.fromEnvelope(envelope, Payroll.fromJson);
  }

  Payroll _one(Object? data) => _object(data, Payroll.fromJson);

  T _object<T>(Object? data, T Function(Map<String, dynamic>) parse) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return parse(data);
  }
}
