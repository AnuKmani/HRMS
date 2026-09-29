import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'expense.dart';
import 'expense_category.dart';

/// How the expenses feature reaches the server.
///
/// The same shape as leave and overtime on purpose: expenses run through the
/// *same* approval engine, walk the same `draft → pending → decided` machine,
/// and refuse the same self-approval. Splitting them into differently shaped
/// repositories would suggest the rules differ when they do not.
///
/// Two verbs are narrower than their siblings, and both because the server
/// is:
///
///  - [reject] takes `remarks` as a **required** argument. A refusal with no
///    reason attached is a decision nobody can learn from, and the API
///    answers 422 without one — so the rule lives in the type here rather
///    than being rediscovered as a red field on every screen that rejects.
///
///  - [addReceipts] takes bytes, not a path or a URL. The file is captured
///    by the camera and handed straight over; nothing in this app writes it
///    to disk or builds a link to it, because the only way to read a receipt
///    back is by id through [receipt], behind the policy that already governs
///    the claim.
///
/// There is no status field in `create`/`update` — a new claim is a draft,
/// always, and [submit] is its own endpoint.
abstract class ExpenseRepository {
  Future<PageResult<Expense>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Expense> find(int id);

  Future<Expense> create(Map<String, Object?> body);

  Future<Expense> update(int id, Map<String, Object?> body);

  Future<Expense> submit(int id, {String remarks = ''});

  Future<Expense> approve(int id, {String remarks = ''});

  Future<Expense> reject(int id, {required String remarks});

  Future<Expense> cancel(int id, {String remarks = ''});

  /// The categories a claim may be booked under — the *active* ones unless
  /// `query` asks for `all`. History still renders a category that has since
  /// been retired because the claim carries its own copy of the rules.
  ///
  /// No `page`: the endpoint answers with the whole pickable set in one
  /// response, which is six rows and a fixed vocabulary rather than a
  /// collection somebody could add a thousand rows to.
  Future<List<ExpenseCategory>> categories({
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// Attaches captured frames to a draft.
  ///
  /// `filename` names the *set*, not each frame: the server mints the stored
  /// name, and a name the phone chose is only ever read for its extension.
  Future<Expense> addReceipts(
    int id,
    List<Uint8List> files, {
    String filename = 'receipt',
  });

  Future<Expense> removeReceipt(int id, int receiptId);

  /// The bytes of one receipt, fetched by id through the policy-checked
  /// route. Never a URL, never a path.
  Future<Uint8List> receipt(int expenseId, int receiptId);
}
