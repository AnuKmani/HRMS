import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_document_repository.dart';
import '../domain/document_repository.dart';
import '../domain/employee_document.dart';
import 'documents_controller.dart';

/// One employment document, everything the server knows about it, and the
/// actions this session may attempt.
///
/// Four things this screen is careful about:
///
///  - **the file is opened by id, never by link.** The payload carries a
///    `file_url` that points at an authenticated API route, and this screen
///    does not render it: it fetches the bytes through the repository and
///    hands them to the viewer. Nothing here could be copied into a browser
///    and left there, which is the whole point of the private store.
///
///  - **status and expiry are printed as two chips, in words.** "Verified"
///    and "Expires in 5 days" are different facts from different sources —
///    one is a decision somebody made, the other is date arithmetic the
///    server recomputes on every read — and neither is ever a colour alone.
///
///  - **the actions are gated by their own permissions, not by one.**
///    Reading is `documents.view`, signing off is `documents.verify`,
///    archiving is `documents.delete`. A supervisor who manages people is
///    not by that fact handed the ability to verify a passport.
///
///  - **a rejection is refused with a reason and a 409 means the state moved
///    under you.** The server answers `already verified` as 409 rather than
///    403, so that is what this screen shows — "you may not" and "that already
///    happened" are different sentences and only one says what to do next.
class DocumentDetailScreen extends ConsumerStatefulWidget {
  const DocumentDetailScreen({super.key, required this.documentId});

  final int documentId;

  @override
  ConsumerState<DocumentDetailScreen> createState() =>
      _DocumentDetailScreenState();
}

class _DocumentDetailScreenState extends ConsumerState<DocumentDetailScreen> {
  EmployeeDocument? _document;
  bool _loading = false;
  bool _busy = false;
  bool _forbidden = false;

  String? _banner;
  Uint8List? _imageBytes;

  DocumentRepository get _repository => ref.read(documentRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gated before the fetch, not just before the build: a URL typed by
    // hand would otherwise ask the API for a file the session was never
    // going to be shown, and the answer to that question is a 403 with
    // nothing to do about it.
    if (ref.read(permissionScopeProvider).canViewDocuments) _load();
  }

  @override
  void didUpdateWidget(covariant DocumentDetailScreen oldWidget) {
    super.didUpdateWidget(oldWidget);

    // The State survives a route that lands back on this screen with a
    // different id — an edit pushed and popped, or a list that navigated
    // sideways — and without this the screen would keep describing the row
    // it is no longer showing.
    if (oldWidget.documentId != widget.documentId) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final document = await _repository.find(widget.documentId);
      if (!mounted) return;

      setState(() {
        _document = document;
        _loading = false;
        _forbidden = false;
      });

      _previewIfImage(document);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = _messageFor(failure);
      });
    }
  }

  /// An image is drawn inline; anything else is opened on a tap.
  ///
  /// Started after the row resolves rather than in `initState`, because
  /// "is this an image?" is answered by the payload and a race here would
  /// mean fetching a PDF into a widget that was going to draw it as one.
  Future<void> _previewIfImage(EmployeeDocument document) async {
    if (!document.hasFile || !document.isImage) return;

    try {
      final bytes = await _repository.file(document.id);
      if (!mounted) return;
      setState(() => _imageBytes = bytes);
    } catch (_) {
      // A preview is a courtesy. The row — its status, dates and the
      // "Open" button below — is the answer to the question this screen is
      // asked, and one that failed to draw a thumbnail must not paint an
      // error over it.
      if (mounted) setState(() => _imageBytes = null);
    }
  }

  Future<void> _openFile() async {
    final document = _document;
    if (document == null || _busy) return;

    if (_imageBytes != null) {
      await _showImage(document, _imageBytes!);
      return;
    }

    setState(() => _busy = true);

    try {
      final bytes = await _repository.file(document.id);
      if (!mounted) return;

      if (document.isImage) {
        await _showImage(document, bytes);
      } else {
        // Bytes in, a viewer out. There is no URL to keep and no file to
        // cache: `PdfOpener` writes into the app's own sandbox and hands it
        // straight to the OS, so nothing outlives the request.
        await ref
            .read(pdfOpenerProvider)
            .openBytes(bytes, document.originalName ?? 'document.pdf');
      }
    } catch (failure) {
      if (mounted) {
        setState(() => _banner = _messageFor(failure));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showImage(EmployeeDocument document, Uint8List bytes) {
    return showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: InteractiveViewer(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  document.originalName ?? 'Document',
                  key: const ValueKey('document-image-name'),
                ),
              ),
              Flexible(child: Image.memory(bytes, fit: BoxFit.contain)),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _verify() async {
    final document = _document;
    if (document == null || _busy) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      final updated = await _repository.verify(document.id);
      if (!mounted) return;
      setState(() {
        _document = updated;
        _busy = false;
      });
      _refreshLists();
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _reject() async {
    final document = _document;
    if (document == null || _busy) return;

    final reason = await _askForReason();
    if (reason == null || !mounted) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      final updated = await _repository.reject(document.id, reason: reason);
      if (!mounted) return;
      setState(() {
        _document = updated;
        _busy = false;
      });
      _refreshLists();
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _archive() async {
    final document = _document;
    if (document == null || _busy) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Archive this document?'),
        content: const Text(
          'It leaves the active list but stays on the employment file. '
          'Nothing is deleted.',
        ),
        actions: [
          TextButton(
            key: const ValueKey('archive-cancel'),
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('archive-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Archive'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      final updated = await _repository.archive(document.id);
      if (!mounted) return;
      setState(() {
        _document = updated;
        _busy = false;
      });
      _refreshLists();
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<String?> _askForReason() {
    final controller = TextEditingController();

    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Why is this being rejected?'),
        content: TextField(
          key: const ValueKey('reject-reason'),
          controller: controller,
          autofocus: true,
          maxLines: 3,
          maxLength: 500,
          decoration: const InputDecoration(
            hintText: 'A reason the person can act on',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
            key: const ValueKey('reject-cancel'),
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('reject-confirm'),
            // A refusal with no reason attached is a decision nobody can
            // learn from, and the API answers 422 without one — so the rule
            // is enforced here too rather than discovered as a red field.
            onPressed: () {
              final reason = controller.text.trim();
              if (reason.isEmpty) return;
              Navigator.of(context).pop(reason);
            },
            child: const Text('Reject'),
          ),
        ],
      ),
    );
  }

  void _refreshLists() {
    ref.read(documentListProvider.notifier).reload();
    ref.read(documentExpiryProvider.notifier).reload();
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    if (!scope.canViewDocuments && !_forbidden) {
      return Scaffold(
        appBar: AppBar(title: const Text('Document')),
        body: const NoPermission(module: 'documents'),
      );
    }

    final document = _document;
    final theme = Theme.of(context);

    if (_loading && document == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Document')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    if (document == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Document')),
        body: _NotFound(
          message: _banner ?? 'This document could not be loaded.',
          forbidden: _forbidden,
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          document.typeName.isNotEmpty ? document.typeName : 'Document',
        ),
        actions: [
          if (scope.canUpdateDocuments && document.isEditable)
            IconButton(
              key: const ValueKey('edit-document'),
              tooltip: 'Edit details',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () => context.push('/documents/${document.id}/edit'),
            ),
          if (scope.canVerifyDocuments &&
              document.status == EmployeeDocument.statusPending)
            PopupMenuButton<String>(
              key: const ValueKey('document-actions'),
              tooltip: 'Actions',
              onSelected: (action) {
                if (action == 'verify') _verify();
                if (action == 'reject') _reject();
                if (action == 'archive') _archive();
              },
              itemBuilder: (context) => [
                const PopupMenuItem(
                  value: 'verify',
                  key: ValueKey('verify-document'),
                  child: Text('Verify'),
                ),
                const PopupMenuItem(
                  value: 'reject',
                  key: ValueKey('reject-document'),
                  child: Text('Reject'),
                ),
                if (scope.canDeleteDocuments)
                  const PopupMenuItem(
                    value: 'archive',
                    key: ValueKey('archive-document'),
                    child: Text('Archive'),
                  ),
              ],
            )
          else if (scope.canDeleteDocuments && !document.isArchived)
            IconButton(
              key: const ValueKey('archive-document'),
              tooltip: 'Archive',
              icon: const Icon(Icons.archive_outlined),
              onPressed: _archive,
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_banner != null)
              Padding(
                key: const ValueKey('document-detail-banner'),
                padding: const EdgeInsets.only(bottom: 16),
                child: Material(
                  color: theme.colorScheme.errorContainer,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Text(
                      _banner!,
                      style: TextStyle(
                        color: theme.colorScheme.onErrorContainer,
                      ),
                    ),
                  ),
                ),
              ),
            if (_busy) const LinearProgressIndicator(),
            Row(
              children: [
                Expanded(
                  child: StatusChip(
                    label: document.statusText,
                    tone: document.statusTone,
                  ),
                ),
                const SizedBox(width: 8),
                // Icon *and* words: the tint is a second signal, never the
                // only one (scope item U).
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(document.expiryIcon, size: 16),
                    const SizedBox(width: 4),
                    Text(document.expiryText, style: theme.textTheme.bodySmall),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 16),
            _row('Employee', document.employeeName ?? 'You'),
            if ((document.documentNumber ?? '').isNotEmpty)
              _row('Number', document.documentNumber!),
            if ((document.issueDate ?? '').isNotEmpty)
              _row('Issued', document.issueDate!),
            if ((document.expiryDate ?? '').isNotEmpty)
              _row('Expires', document.expiryDate!),
            if (document.documentType != null)
              _row(
                'Warning window',
                document.documentType!.fallbackWarningLabel,
              ),
            if ((document.notes ?? '').isNotEmpty)
              _row('Notes', document.notes!),
            if ((document.rejectionReason ?? '').isNotEmpty)
              _row('Rejected because', document.rejectionReason!),
            if (document.verifiedAt != null)
              _row('Signed off', document.verifiedAt!),
            if ((document.createdAt ?? '').isNotEmpty)
              _row('Uploaded', document.createdAt!),
            const SizedBox(height: 8),
            _fileCard(context, document),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelSmall),
        const SizedBox(height: 2),
        Text(value, style: Theme.of(context).textTheme.bodyMedium),
      ],
    ),
  );

  Widget _fileCard(BuildContext context, EmployeeDocument document) {
    final theme = Theme.of(context);

    if (!document.hasFile) {
      return Card(
        child: ListTile(
          key: const ValueKey('document-no-file'),
          leading: const Icon(Icons.folder_off_outlined),
          title: const Text('No file attached'),
          subtitle: const Text(
            'Only the details of this document are on record.',
          ),
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_imageBytes != null) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.memory(
                  _imageBytes!,
                  key: const ValueKey('document-image-preview'),
                  fit: BoxFit.contain,
                ),
              ),
              const SizedBox(height: 12),
            ],
            Row(
              children: [
                Icon(
                  document.isImage
                      ? Icons.image_outlined
                      : Icons.picture_as_pdf_outlined,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        document.originalName ?? 'File',
                        key: const ValueKey('document-file-name'),
                      ),
                      Text(
                        document.sizeLabel,
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            FilledButton.tonalIcon(
              key: const ValueKey('open-document-file'),
              onPressed: _busy ? null : _openFile,
              icon: _busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Icon(
                      document.isImage
                          ? Icons.zoom_in_outlined
                          : Icons.open_in_new,
                    ),
              label: Text(document.isImage ? 'Enlarge' : 'Open the file'),
            ),
          ],
        ),
      ),
    );
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}

class _NotFound extends StatelessWidget {
  const _NotFound({required this.message, required this.forbidden});

  final String message;
  final bool forbidden;

  @override
  Widget build(BuildContext context) {
    if (forbidden) return const NoPermission(module: 'documents');

    return Center(
      key: const ValueKey('document-not-found'),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Text(message, textAlign: TextAlign.center),
      ),
    );
  }
}
