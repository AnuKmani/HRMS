import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/employee_document.dart';
import 'documents_controller.dart';

/// Every employment document this session may read.
///
/// Two things this list is careful about:
///
///  - **the expiry words are printed next to the status, never a colour
///    alone.** "Expires in 5 days" and "Expired 12 days ago" carry their
///    meaning in the sentence, so the chip's tint is a second signal rather
///    than the only one — the whole point of scope item U.
///
///  - **the filter is a query parameter.** `status`, `expired` and
///    `expiring_soon` travel to the API rather than being applied over the
///    loaded page, because the server owns both the row scope and the
///    totals: a Dart-side slice would still be showing the unfiltered `total`
///    underneath it.
class DocumentListScreen extends ConsumerWidget {
  const DocumentListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Before the upload button, and before the list: `GET /employee-documents`
    // is behind `permission:documents.view`, so a session without it would
    // only ever draw a 403 or an empty list that looks like a decision.
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewDocuments))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Documents')),
        body: const NoPermission(module: 'documents'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Documents'),
        actions: [
          if (scope.canViewDocumentExpiry)
            IconButton(
              key: const ValueKey('documents-expiring-door'),
              tooltip: 'Expiring soon',
              icon: const Icon(Icons.event_busy_outlined),
              onPressed: () => context.push('/documents/expiring'),
            ),
        ],
      ),
      floatingActionButton: scope.canCreateDocuments
          ? FloatingActionButton(
              key: const ValueKey('upload-document'),
              tooltip: 'Upload a document',
              onPressed: () => context.push('/documents/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: PagedListView<EmployeeDocument>(
        provider: documentListProvider,
        emptyMessage: 'No documents yet.',
        emptyHint:
            'Passports, Emirates IDs, visas and contracts you are allowed '
            'to see appear here.',
        searchHint: 'Search number, file or person',
        filter: _DocumentFilters(
          status: ref.watch(
            documentListProvider.select((s) => s.query['status'] as String?),
          ),
          expiry: ref.watch(
            documentListProvider.select((s) => s.query['expiry'] as String?),
          ),
          onStatus: (value) => ref
              .read(documentListProvider.notifier)
              .setFilter('status', value),
          // One key here, two on the wire — DocumentListController does the
          // translation, so the dropdown has a value to show when it comes
          // back and the request still carries the two booleans the API
          // actually reads.
          onExpiry: (value) => ref
              .read(documentListProvider.notifier)
              .setFilter('expiry', value),
        ),
        itemBuilder: (context, document, index) => ListTile(
          key: ValueKey('document-row-${document.id}'),
          leading: Icon(_iconFor(document)),
          title: Text(
            document.typeName.isNotEmpty
                ? document.typeName
                : (document.originalName ?? 'Document ${document.id}'),
          ),
          subtitle: Text(
            [
              document.employeeName ?? 'You',
              if ((document.documentNumber ?? '').isNotEmpty)
                document.documentNumber!,
              if ((document.originalName ?? '').isNotEmpty &&
                  document.typeName.isNotEmpty)
                document.originalName!,
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: document.statusText, tone: document.statusTone),
              const SizedBox(height: 6),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(document.expiryIcon, size: 14),
                  const SizedBox(width: 4),
                  Text(
                    document.expiryText,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ],
          ),
          onTap: () => context.push('/documents/${document.id}'),
        ),
      ),
    );
  }

  static IconData _iconFor(EmployeeDocument document) {
    final code = document.typeCode;

    return switch (code) {
      'PASSPORT' => Icons.book_outlined,
      'EMIRATES_ID' => Icons.badge_outlined,
      'VISA' => Icons.flight_takeoff_outlined,
      'EMPLOYMENT_CONTRACT' => Icons.description_outlined,
      'CERTIFICATE' => Icons.workspace_premium_outlined,
      'TRAINING_CERTIFICATE' => Icons.school_outlined,
      'MEDICAL_DOCUMENT' => Icons.medical_services_outlined,
      'LABOUR_DOCUMENTS' => Icons.work_outline,
      _ => document.isImage ? Icons.image_outlined : Icons.folder_outlined,
    };
  }
}

class _DocumentFilters extends StatelessWidget {
  const _DocumentFilters({
    required this.status,
    required this.expiry,
    required this.onStatus,
    required this.onExpiry,
  });

  final String? status;
  final String? expiry;
  final ValueChanged<String?> onStatus;
  final ValueChanged<String?> onExpiry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Status', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        _dropdown(
          key: const ValueKey('document-status-filter'),
          value: status ?? '',
          items: const [
            ('', 'All statuses'),
            (EmployeeDocument.statusPending, 'Awaiting verification'),
            (EmployeeDocument.statusValid, 'Verified'),
            (EmployeeDocument.statusExpired, 'Expired'),
            (EmployeeDocument.statusRejected, 'Rejected'),
            (EmployeeDocument.statusArchived, 'Archived'),
          ],
          onChanged: onStatus,
        ),
        const SizedBox(height: 12),
        Text('Expiry', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        _dropdown(
          key: const ValueKey('document-expiry-filter'),
          value: expiry ?? '',
          items: const [
            ('', 'Any expiry'),
            ('expired', 'Already expired'),
            ('expiring_soon', 'Expiring soon'),
          ],
          onChanged: onExpiry,
        ),
      ],
    );
  }

  Widget _dropdown({
    required Key key,
    required String value,
    required List<(String, String)> items,
    required ValueChanged<String?> onChanged,
  }) => InputDecorator(
    decoration: const InputDecoration(
      border: OutlineInputBorder(),
      isDense: true,
      contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
    ),
    child: DropdownButtonHideUnderline(
      child: DropdownButton<String>(
        key: key,
        isExpanded: true,
        value: value,
        items: [
          for (final (code, label) in items)
            DropdownMenuItem<String>(value: code, child: Text(label)),
        ],
        onChanged: onChanged,
      ),
    ),
  );
}
