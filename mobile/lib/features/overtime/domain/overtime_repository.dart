import '../../../core/data/page_result.dart';
import 'overtime_request.dart';

/// How the overtime feature reaches the server.
///
/// The same shape as leave on purpose: overtime runs through the *same*
/// approval engine, walks the same `draft → pending → decided` machine, and
/// refuses the same self-approval. Splitting the two into differently
/// shaped repositories would suggest the rules differ when they do not.
///
/// As with leave, there is no status field in `create`/`update` — a new
/// claim is a draft, always, and `submit` is its own endpoint.
abstract class OvertimeRepository {
  Future<PageResult<OvertimeRequest>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<OvertimeRequest> find(int id);

  Future<OvertimeRequest> create(Map<String, Object?> body);

  Future<OvertimeRequest> update(int id, Map<String, Object?> body);

  Future<OvertimeRequest> submit(int id, {String remarks = ''});

  Future<OvertimeRequest> approve(
    int id, {
    String remarks = '',
    int? approvedMinutes,
  });

  Future<OvertimeRequest> reject(int id, {String remarks = ''});

  Future<OvertimeRequest> cancel(int id, {String remarks = ''});
}
