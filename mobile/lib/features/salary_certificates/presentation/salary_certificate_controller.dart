import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_salary_certificate_repository.dart';
import '../domain/salary_certificate.dart';

/// The rows behind `GET /salary-certificate-requests`.
///
/// No default status filter, for a reason that differs from the other lists
/// here: an employee sees exactly their own two or three requests and wants
/// all of them, while an HR desk's queue is short enough that "pending" as a
/// default would hide the issued one somebody is on the phone asking about.
final salaryCertificateListProvider =
    NotifierProvider<
      SalaryCertificateListController,
      ListState<SalaryCertificateRequest>
    >(SalaryCertificateListController.new);

class SalaryCertificateListController
    extends PagedListController<SalaryCertificateRequest> {
  @override
  Future<PageResult<SalaryCertificateRequest>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(salaryCertificateRepositoryProvider)
      .list(page: page, query: query);
}
