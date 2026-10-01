import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'document_type.dart';
import 'employee_document.dart';

/// How the documents feature reaches the server.
///
/// Two verbs are narrower than their siblings, and both because the server
/// is:
///
///  - [create] and [update] take **bytes**, not a path. The file comes from
///    the camera, the gallery or a picker and is handed straight over;
///    nothing in this app writes it to disk first, because the only way to
///    read it back is by id through [file], behind the policy that already
///    governs the row.
///
///  - [file] returns bytes for the same reason: there is no URL to keep, no
///    link to share and nothing to cache. `EmployeeDocumentResource` ships a
///    `file_url` that points at an authenticated API route, and this client
///    never renders it — it fetches by id and hands the result to the
///    viewer.
///
/// There is no `setStatus` anywhere. The lifecycle is five transitions the
/// API owns (upload, verify, reject, expire, archive) and there is no
/// endpoint that sets `status` directly, so a client that could would end
/// up believing it had verified something.
abstract class DocumentRepository {
  Future<PageResult<EmployeeDocument>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// `GET /employee-documents/expiring` — the cross-employee "what is about
  /// to lapse" view. Separate from [list] because it is a different
  /// permission and a different question.
  Future<PageResult<EmployeeDocument>> expiring({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<EmployeeDocument> find(int id);

  Future<EmployeeDocument> create(
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  });

  Future<EmployeeDocument> update(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  });

  /// Accepts a document. The server answers 409 naming the state if this
  /// one is already decided — not 403, because "already verified" and "you
  /// are not allowed" are different sentences and only one says what to do
  /// next.
  Future<EmployeeDocument> verify(int id);

  Future<EmployeeDocument> reject(int id, {required String reason});

  /// Archives the row. **Nothing is deleted**: the record and its file stay,
  /// because an employment file is the record of a person who worked here.
  Future<EmployeeDocument> archive(int id);

  /// The bytes of one document, fetched by id through the policy-checked
  /// route. Never a URL, never a path.
  Future<Uint8List> file(int id);

  /// The document types — the whole active vocabulary in one response, which
  /// is why it has no page number to fetch.
  Future<List<DocumentType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  });
}
