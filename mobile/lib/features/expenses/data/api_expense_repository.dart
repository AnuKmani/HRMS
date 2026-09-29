import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/expense.dart';
import '../domain/expense_category.dart';
import '../domain/expense_repository.dart';

final expenseRepositoryProvider = Provider<ExpenseRepository>(
  (ref) => ApiExpenseRepository(ref.watch(apiClientProvider)),
);

/// `ExpenseRepository` over the real HTTP client.
///
/// Seven transitions, seven methods — a stringly-typed `act(action)` would
/// move a typo from the route into a parameter, where nothing catches it
/// until a 404 arrives from an endpoint that never existed.
class ApiExpenseRepository implements ExpenseRepository {
  ApiExpenseRepository(this._client);

  static const _path = '/expenses';

  final ApiClient _client;

  @override
  Future<PageResult<Expense>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Expense>.fromEnvelope(envelope, Expense.fromJson);
  }

  @override
  Future<Expense> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<Expense> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<Expense> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  @override
  Future<Expense> submit(int id, {String remarks = ''}) async =>
      _act(id, 'submit', _remarks(remarks));

  @override
  Future<Expense> approve(int id, {String remarks = ''}) async =>
      _act(id, 'approve', _remarks(remarks));

  @override
  Future<Expense> reject(int id, {required String remarks}) async =>
      _act(id, 'reject', <String, Object?>{'remarks': remarks});

  @override
  Future<Expense> cancel(int id, {String remarks = ''}) async =>
      _act(id, 'cancel', _remarks(remarks));

  @override
  Future<List<ExpenseCategory>> categories({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get('/expense-categories', query: query);
    final data = envelope.data;

    if (data is! List) throw unexpectedShapeException;

    return data
        .whereType<Map<String, dynamic>>()
        .map(ExpenseCategory.fromJson)
        .toList(growable: false);
  }

  @override
  Future<Expense> addReceipts(
    int id,
    List<Uint8List> files, {
    String filename = 'receipt',
  }) async {
    final envelope = await _client.postMultipart(
      '$_path/$id/receipts',
      fields: const <String, Object?>{},
      files: <String, Object>{
        'receipts': <MultipartFile>[
          for (var index = 0; index < files.length; index++)
            // Declared, not sniffed: the camera hands back JPEG, and a name
            // with no extension at all fails the `mimes` rule before the
            // byte-level content check is ever reached. The bytes are
            // validated by the server regardless, so this label is what the
            // rules read first rather than a way past them.
            MultipartFile.fromBytes(
              files[index],
              filename: '$filename-${index + 1}.jpg',
              contentType: DioMediaType('image', 'jpeg'),
            ),
        ],
      },
    );

    return _one(envelope.data);
  }

  @override
  Future<Expense> removeReceipt(int id, int receiptId) async =>
      _one((await _client.delete('$_path/$id/receipts/$receiptId')).data);

  @override
  Future<Uint8List> receipt(int expenseId, int receiptId) =>
      _client.bytes('$_path/$expenseId/receipts/$receiptId');

  /// An empty remark is sent as nothing at all rather than as `''`, so an
  /// optional field stays absent instead of arriving as an empty string the
  /// server has to treat as a value.
  Map<String, Object?> _remarks(String remarks) => remarks.isEmpty
      ? const <String, Object?>{}
      : <String, Object?>{'remarks': remarks};

  Future<Expense> _act(int id, String action, Map<String, Object?> body) async {
    final envelope = await _client.post('$_path/$id/$action', body: body);

    return _one(envelope.data);
  }

  Expense _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Expense.fromJson(data);
  }
}
