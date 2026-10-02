import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'employee_training.dart';
import 'training_compliance.dart';
import 'training_program.dart';
import 'training_type.dart';

/// How the training feature reaches the server.
///
/// Three verbs are narrower than their siblings, and each for a reason the
/// API owns rather than a convenience this client wants:
///
///  - [complete] and [file] take **bytes**, not a path. The certificate
///    arrives from the gallery or a picker and is handed straight over; and
///    reading it back happens by id through [file], behind
///    `EmployeeTrainingPolicy::viewCertificate()` — your own card, or a
///    colleague's with `training.certificates.view`. There is no signed
///    URL in this feature and nothing for a browser to cache.
///
///  - [compliance] is not a page. It is one answer about the whole
///    workforce the reader may see, and giving it a page number would be
///    inviting a screen to draw "1 of 3" beside a number that is already a
///    total.
///
/// There is no `setStatus` anywhere. The lifecycle is written by two
/// endpoints — complete and cancel — and a client that could set `expired`
/// directly would be inventing a lapse the calendar had not reached.
abstract class TrainingRepository {
  /* --------------------------------------------------------- enrolments */

  Future<PageResult<EmployeeTraining>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// `GET /employee-training/expiring` — the cross-employee "whose
  /// certificate is about to lapse" view. Separate from [list] because it
  /// is a different permission (`training.expiry.view`) and a different
  /// question.
  Future<PageResult<EmployeeTraining>> expiring({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<TrainingCompliance> compliance();

  Future<EmployeeTraining> find(int id);

  Future<EmployeeTraining> enroll(Map<String, Object?> body);

  Future<EmployeeTraining> update(int id, Map<String, Object?> body);

  /// Records a pass. The server answers 409 naming the state if this one is
  /// already decided — not 403, because "already completed" and "you are
  /// not allowed" are different sentences and only one says what to do
  /// next.
  Future<EmployeeTraining> complete(
    int id,
    Map<String, Object?> body, {
    Uint8List? file,
    String? filename,
  });

  Future<EmployeeTraining> cancel(int id, {String? remarks});

  /// The certificate's bytes, fetched by id through the policy-checked
  /// route. Never a URL, never a path.
  Future<Uint8List> file(int id);

  /* ---------------------------------------------------------- catalogue */

  Future<List<TrainingType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<PageResult<TrainingProgram>> programs({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<TrainingProgram> findProgram(int id);

  Future<TrainingProgram> createProgram(Map<String, Object?> body);

  Future<TrainingProgram> updateProgram(int id, Map<String, Object?> body);
}
