import '../../../core/data/page_result.dart';
import 'onboarding.dart';

/// How the onboarding feature reaches the server.
///
/// Four verbs, and the split between the first two is the shape of the
/// module: [list] answers *"where does everybody stand?"* over a directory
/// of employees, while [find] answers *"what exactly is outstanding?"* for
/// one of them — the only call that pays for a checklist.
///
/// [update] and [complete] exist separately for a reason the API encodes
/// itself: moving a record's stage is a status change, but completing it has
/// a precondition the server refuses with **409 naming what is still
/// outstanding** rather than 403. "You may not" and "the file is short of
/// three requirements" are different sentences, and only the second tells
/// anybody what to do next — so this interface has a method for each rather
/// than one `setStatus` that would have to swallow both.
///
/// There is no `create`. The record is materialised by the server the first
/// time anybody reads or writes a person's onboarding, because a directory
/// row with no record is a real state (`draft`, `exists = false`) rather
/// than a missing object a client is expected to bring into being.
abstract class OnboardingRepository {
  Future<PageResult<Onboarding>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// `GET /onboarding/{employee}` — the checklist, totals and readiness.
  Future<Onboarding> find(int employeeId);

  /// `PUT /onboarding/{employee}` — the stage and the notes.
  Future<Onboarding> update(int employeeId, Map<String, Object?> body);

  /// `POST /onboarding/{employee}/complete`.
  ///
  /// Throws an [ApiException] with `statusCode` 409 when requirements are
  /// outstanding; its `message` names them, so a caller that only renders the
  /// message still tells the reader what is missing.
  Future<Onboarding> complete(int employeeId);
}
