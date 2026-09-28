import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'salary_certificate.dart';

/// `/api/v1/salary-certificate-requests`.
///
/// `pdf` is a separate verb returning bytes rather than a field on the model
/// because there is nothing stored to point at: the document is rendered
/// from the row at the moment it is asked for and handed straight over.
abstract class SalaryCertificateRepository {
  Future<PageResult<SalaryCertificateRequest>> list({
    required int page,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<SalaryCertificateRequest> find(int id);

  Future<SalaryCertificateRequest> create(Map<String, Object?> body);

  Future<SalaryCertificateRequest> approve(int id, {String? remarks});

  Future<SalaryCertificateRequest> reject(int id, {String? remarks});

  Future<SalaryCertificateRequest> cancel(int id);

  Future<Uint8List> pdf(int id);
}
