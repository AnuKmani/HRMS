import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_salary_certificate_repository.dart';
import '../domain/salary_certificate.dart';
import '../domain/salary_certificate_repository.dart';
import 'salary_certificate_controller.dart';
import 'salary_certificate_list_screen.dart';

/// One certificate request: what was asked, what was decided, and the one
/// button that produces a document.
///
/// Two details worth stating because they are not obvious from the screen:
///
///  - **Issue renders, then records.** The PDF is generated from the row at
///    the moment it is pressed and never stored, so there is no file to go
///    stale and no URL to leak. If the render fails, the row stays
///    `approved` and the user may press again — which is why the failure
///    message says to try again rather than implying the document exists
///    somewhere.
///
///  - **`generated_at` moves once.** It answers "when was this issued?",
///    never "when did somebody last look?", so re-opening the document
///    afterwards changes nothing.
class SalaryCertificateDetailScreen extends ConsumerStatefulWidget {
  const SalaryCertificateDetailScreen({super.key, required this.requestId});

  final int requestId;

  @override
  ConsumerState<SalaryCertificateDetailScreen> createState() =>
      _SalaryCertificateDetailScreenState();
}

class _SalaryCertificateDetailScreenState
    extends ConsumerState<SalaryCertificateDetailScreen> {
  SalaryCertificateRequest? _request;
  String? _error;
  bool _loading = true;
  bool _working = false;
  bool _issuing = false;
  String? _banner;

  SalaryCertificateRepository get _repository =>
      ref.read(salaryCertificateRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // The route carries no permission; see the note in the loan detail
    // screen. A request that would have been refused anyway is not made.
    if (!ref.read(permissionScopeProvider).canViewSalaryCertificates) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final request = await _repository.find(widget.requestId);

      if (!mounted) return;

      setState(() {
        _request = request;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _error = failure is ApiException
            ? failure.message
            : 'Something went wrong. Please try again.';
        _loading = false;
      });
    }
  }

  Future<void> _run(
    Future<SalaryCertificateRequest> Function(String? remarks) run, {
    required String title,
    required String message,
    String confirmLabel = 'Confirm',
    bool remarksRequired = false,
  }) async {
    final answer = await _ask(
      title: title,
      message: message,
      confirmLabel: confirmLabel,
      remarksRequired: remarksRequired,
    );

    // `null` is Back. `''` is "confirmed without a note", which is not the
    // same thing and must not be sent as though a blank line had been typed.
    if (answer == null || !mounted) return;

    final typed = answer.trim();
    final remarks = typed.isEmpty ? null : typed;

    setState(() {
      _working = true;
      _banner = null;
    });

    try {
      await run(remarks);
      await _load();

      ref.read(salaryCertificateListProvider.notifier).reload();

      if (!mounted) return;

      setState(() => _working = false);
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });

      await _load();
    }
  }

  Future<void> _issue() async {
    if (_issuing) return;

    setState(() {
      _issuing = true;
      _banner = null;
    });

    try {
      final bytes = await _repository.pdf(widget.requestId);

      if (!mounted) return;

      await ref
          .read(pdfOpenerProvider)
          .openBytes(bytes, 'salary-certificate-${widget.requestId}.pdf');

      // Re-read: the first render moved the row to `generated`, and a
      // screen still showing `approved` would be claiming there is nothing
      // to download when the document is now in the viewer.
      await _load();
    } catch (failure) {
      if (!mounted) return;

      setState(() => _banner = _messageFor(failure));
    } finally {
      if (mounted) setState(() => _issuing = false);
    }
  }

  Future<String?> _ask({
    required String title,
    required String message,
    required String confirmLabel,
    bool remarksRequired = false,
  }) {
    final remarks = TextEditingController();
    String? error;

    return showDialog<String>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: Text(title),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(message),
              const SizedBox(height: 16),
              TextField(
                controller: remarks,
                maxLines: 3,
                decoration: InputDecoration(
                  labelText: remarksRequired ? 'Remarks *' : 'Remarks',
                  errorText: error,
                  border: const OutlineInputBorder(),
                  isDense: true,
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('Back'),
            ),
            FilledButton(
              onPressed: () {
                final text = remarks.text.trim();

                if (remarksRequired && text.isEmpty) {
                  setDialogState(() => error = 'Say why before confirming.');
                  return;
                }

                Navigator.of(dialogContext).pop(text);
              },
              child: Text(confirmLabel),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: Text('Certificate #${widget.requestId}')),
      body: _body(scope),
    );
  }

  Widget _body(PermissionScope scope) {
    // Drawn *before* the loading state, for the same reason as in the loan
    // detail screen: `_load` does not ask for a row it may not read.
    if (!scope.canViewSalaryCertificates) {
      return const NoPermission(module: 'salary certificates');
    }

    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('certificate-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('certificate-error'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                label: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    final request = _request!;
    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              key: const ValueKey('certificate-banner'),
              padding: const EdgeInsets.only(bottom: 16),
              child: Material(
                color: theme.colorScheme.errorContainer,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _banner!,
                          style: TextStyle(
                            color: theme.colorScheme.onErrorContainer,
                          ),
                        ),
                      ),
                      TextButton(
                        onPressed: () => setState(() => _banner = null),
                        child: const Text('Dismiss'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          request.reference ?? 'Request',
                          style: theme.textTheme.titleLarge,
                        ),
                      ),
                      StatusChip(
                        key: const ValueKey('certificate-status'),
                        label: request.statusLabel,
                        tone: toneForCertificateStatus(request.status),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _row(theme, 'Person', request.employeeName),
                  _row(theme, 'Asked on', request.requestDate),
                  if (request.approvedAt != null)
                    _row(theme, 'Decided', _dateOnly(request.approvedAt)),
                  if (request.generatedAt != null)
                    _row(theme, 'Issued', _dateOnly(request.generatedAt)),
                  const SizedBox(height: 8),
                  Text(request.purpose, style: theme.textTheme.bodyMedium),
                  if (request.remarks != null &&
                      request.remarks!.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Text('Remarks', style: theme.textTheme.labelLarge),
                    Text(request.remarks!, style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          _actions(request, scope),
        ],
      ),
    );
  }

  Widget _actions(SalaryCertificateRequest request, PermissionScope scope) {
    final canDecide =
        scope.canManageSalaryCertificates && request.canBeDecided && !_working;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (request.canIssue)
          FilledButton.icon(
            key: const ValueKey('issue-certificate'),
            // The permission gate is already inside `can_issue` — it
            // combines the grant with the row's state — so a second check
            // here would be answering a question the server already
            // answered.
            onPressed: _issuing ? null : _issue,
            icon: _issuing
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.picture_as_pdf_outlined),
            label: const Text('Issue document (PDF)'),
          ),
        if (canDecide) ...[
          const SizedBox(height: 8),
          FilledButton.icon(
            key: const ValueKey('approve-certificate'),
            onPressed: () => _run(
              (remarks) => _repository.approve(request.id, remarks: remarks),
              title: 'Approve this certificate?',
              message:
                  'The certificate will state this person’s salary and '
                  'service. Nobody may approve their own request.',
              confirmLabel: 'Approve',
            ),
            icon: const Icon(Icons.check_outlined),
            label: const Text('Approve'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('reject-certificate'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _run(
              (remarks) => _repository.reject(request.id, remarks: remarks),
              title: 'Refuse this request?',
              message: 'No document is issued and the reason is recorded.',
              confirmLabel: 'Refuse',
              remarksRequired: true,
            ),
            icon: const Icon(Icons.close_outlined),
            label: const Text('Refuse'),
          ),
        ],
        if (request.canBeCancelled && !canDecide) ...[
          if (!request.canIssue) const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('cancel-certificate'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _run(
              (remarks) => _repository.cancel(request.id),
              title: 'Withdraw this request?',
              message: 'It leaves the queue and no document is produced.',
              confirmLabel: 'Withdraw',
            ),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Withdraw'),
          ),
        ],
      ],
    );
  }

  Widget _row(ThemeData theme, String label, String? value) {
    if (value == null || value.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 90,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }

  static String _dateOnly(String? iso) =>
      iso == null || iso.length < 10 ? (iso ?? '') : iso.substring(0, 10);

  static String _messageFor(Object failure) {
    if (failure is ApiException) {
      final fieldErrors = failure.errors.values;

      if (fieldErrors.isNotEmpty) return fieldErrors.join('\n');

      return failure.message;
    }

    return 'Something went wrong. Please try again.';
  }
}
