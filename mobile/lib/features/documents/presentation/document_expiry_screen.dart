import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../domain/employee_document.dart';
import 'documents_controller.dart';

/// Everything that is about to lapse, or already has.
///
/// A separate screen rather than a filter on the directory, because it is a
/// separate question with a separate permission: `documents.expiry.view` asks
/// about *everybody*, and the seeders withhold it from roles that should only
/// ever see their own file. A filter chip on the main list would put the same
/// act behind `documents.view`.
///
/// The window is the report's own `within` (90 days, overridable from the
/// chips below) rather than a page-side comparison — the server answers it
/// with a single date arithmetic, and a client that re-derived the same
/// answer would drift from the one the scheduler notifies on.
class DocumentExpiryScreen extends ConsumerWidget {
  const DocumentExpiryScreen({super.key});

  static const _windows = <(String, String)>[
    ('0', 'Already expired'),
    ('30', 'Next 30 days'),
    ('90', 'Next 90 days'),
    ('180', 'Next 180 days'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(
      permissionScopeProvider.select((s) => s.canViewDocumentExpiry),
    )) {
      return Scaffold(
        appBar: AppBar(title: const Text('Expiring documents')),
        body: const NoPermission(module: 'document expiry'),
      );
    }

    final within = ref.watch(
      documentExpiryProvider.select((s) => '${s.query['within'] ?? 90}'),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Expiring documents')),
      body: PagedListView<EmployeeDocument>(
        provider: documentExpiryProvider,
        emptyMessage: 'Nothing is due to expire.',
        emptyHint:
            'Documents inside the selected window appear here, oldest first.',
        filter: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Window', style: Theme.of(context).textTheme.labelLarge),
            const SizedBox(height: 6),
            InputDecorator(
              decoration: const InputDecoration(
                border: OutlineInputBorder(),
                isDense: true,
                contentPadding: EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 12,
                ),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  key: const ValueKey('expiry-window-filter'),
                  isExpanded: true,
                  value: within,
                  items: [
                    for (final (code, label) in _windows)
                      DropdownMenuItem<String>(value: code, child: Text(label)),
                  ],
                  onChanged: (value) => ref
                      .read(documentExpiryProvider.notifier)
                      .setFilter('within', value),
                ),
              ),
            ),
          ],
        ),
        itemBuilder: (context, document, index) => ListTile(
          key: ValueKey('expiring-row-${document.id}'),
          leading: Icon(document.expiryIcon),
          title: Text(
            document.typeName.isNotEmpty
                ? document.typeName
                : 'Document ${document.id}',
          ),
          subtitle: Text(
            [
              document.employeeName ?? 'You',
              if ((document.expiryDate ?? '').isNotEmpty)
                'Expires ${document.expiryDate!}',
            ].join(' · '),
          ),
          isThreeLine: true,
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: document.statusText, tone: document.statusTone),
              const SizedBox(height: 6),
              Text(
                document.expiryText,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          onTap: () => context.push('/documents/${document.id}'),
        ),
      ),
    );
  }
}
