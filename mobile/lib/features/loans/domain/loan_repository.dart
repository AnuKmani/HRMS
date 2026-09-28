import '../../../core/data/page_result.dart';
import 'loan.dart';

/// The loans and salary advances this session may see, and the decisions it
/// may make on them.
///
/// Four of these methods are `POST`s that move a row through its lifecycle
/// rather than four CRUD verbs, because the lifecycle is the contract:
/// `submit`, `approve`, `reject`, `cancel` each name the transition they
/// perform and each returns the row as it now stands. A generic `update`
/// carrying `status: 'approved'` would let a client ask for a transition no
/// endpoint provides, and the refusal would arrive as a validation error
/// about a field rather than a sentence about a state.
abstract class LoanRepository {
  Future<PageResult<Loan>> list({
    required int page,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Loan> find(int id);

  Future<Loan> create(Map<String, Object?> body);

  Future<Loan> update(int id, Map<String, Object?> body);

  Future<Loan> submit(int id);

  Future<Loan> approve(int id, {String? remarks});

  Future<Loan> reject(int id, {String? remarks});

  Future<Loan> cancel(int id);
}
