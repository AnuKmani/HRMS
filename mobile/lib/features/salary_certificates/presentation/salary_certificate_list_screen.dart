import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/form_controls.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/salary_certificate.dart';
import 'salary_certificate_controller.dart';

/// Every salary certificate request this session may read.
///
/// `salary_certificates.view` is enough to reach the screen and to ask for
/// one — an employee cannot be granted a permission to request a document
/// about their own salary and then be refused for exercising it — so there
/// is no second "can request" gate on the button. What decides *whose*
/// requests come back is `Visibility`, which fails closed to own-only: the
/// list is narrower for an employee than for an HR desk because the server
/// made it narrower, not because a widget is hiding rows.
class SalaryCertificateListScreen extends ConsumerWidget {
  const SalaryCertificateListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canView = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canViewSalaryCertificates,
      ),
    );

    if (!canView) {
      return Scaffold(
        appBar: AppBar(title: const Text('Salary certificates')),
        body: const NoPermission(module: 'salary certificates'),
      );
    }

    final status = ref.watch(
      salaryCertificateListProvider.select(
        (state) => state.query['status'] as String?,
      ),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Salary certificates')),
      floatingActionButton: FloatingActionButton(
        key: const ValueKey('new-certificate'),
        tooltip: 'Ask for a salary certificate',
        onPressed: () => context.push('/salary-certificates/new'),
        child: const Icon(Icons.add),
      ),
      body: PagedListView<SalaryCertificateRequest>(
        key: const ValueKey('certificate-list'),
        provider: salaryCertificateListProvider,
        searchHint: 'Search by purpose or employee',
        emptyMessage: 'No certificate requests yet.',
        emptyHint:
            'Ask for one when a bank, a landlord or an embassy needs proof '
            'of your salary.',
        filter: StatusFilter(
          value: status,
          options: const <StatusOption>[
            StatusOption(
              SalaryCertificateRequest.statusPending,
              'Awaiting approval',
            ),
            StatusOption(SalaryCertificateRequest.statusApproved, 'Approved'),
            StatusOption(SalaryCertificateRequest.statusGenerated, 'Issued'),
            StatusOption(SalaryCertificateRequest.statusRejected, 'Rejected'),
          ],
          onChanged: (next) => ref
              .read(salaryCertificateListProvider.notifier)
              .setFilter('status', next == '' ? null : next),
        ),
        itemBuilder: (context, request, index) => ListTile(
          key: ValueKey('certificate-row-${request.id}'),
          title: Text(
            [
              if (request.reference != null) request.reference!,
              request.purpose,
            ].join(' · '),
          ),
          subtitle: Text(
            [
              if (request.employeeName != null) request.employeeName!,
              if (request.requestDate != null) request.requestDate!,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: StatusChip(
            label: request.statusLabel,
            tone: toneForCertificateStatus(request.status),
          ),
          onTap: () => context.push('/salary-certificates/${request.id}'),
        ),
      ),
    );
  }
}

/// The five states, ordered by what happens next.
///
/// `generated` is the green — the document exists and can be held — while
/// `approved` is a warning, because an approved certificate nobody has
/// issued yet is a request sitting half-finished. That distinction is the
/// whole reason `approved` and `generated` are two states rather than one.
StatusTone toneForCertificateStatus(String status) => switch (status) {
  SalaryCertificateRequest.statusPending => StatusTone.warning,
  SalaryCertificateRequest.statusApproved => StatusTone.info,
  SalaryCertificateRequest.statusRejected => StatusTone.negative,
  SalaryCertificateRequest.statusGenerated => StatusTone.positive,
  SalaryCertificateRequest.statusCancelled => StatusTone.neutral,
  _ => StatusTone.neutral,
};
