import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/data/approval_step.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_leave_repository.dart';
import '../domain/leave_repository.dart';
import '../domain/leave_request.dart';
import 'certificate_capture_sheet.dart';
import 'leave_controller.dart';
import 'leave_list_screen.dart';

/// One leave request, everything the server knows about it, and the actions
/// this session is allowed to attempt.
///
/// The screen deliberately shows the whole record — dates, derived day count,
/// certificate state, approval chain, remarks — rather than a summary of it,
/// because every one of those is something an employee and an approver
/// disagree about in practice, and "the app said three days" is only a useful
/// sentence if it is the same three days the server reserved.
///
/// Actions are drawn from [PermissionScope] and enforced again by the API:
/// the buttons are a courtesy, and a request submitted twice or approved by
/// the wrong person gets the server's 403 or 409 rather than a half-applied
/// transition.
class LeaveDetailScreen extends ConsumerStatefulWidget {
  const LeaveDetailScreen({super.key, required this.leaveId});

  final int leaveId;

  @override
  ConsumerState<LeaveDetailScreen> createState() => _LeaveDetailScreenState();
}

class _LeaveDetailScreenState extends ConsumerState<LeaveDetailScreen> {
  LeaveRequest? _request;
  String? _error;
  bool _loading = true;
  bool _working = false;

  /// A refusal the *action* caused — shown above the record rather than under
  /// a field, because nothing the user typed caused it.
  String? _banner;

  LeaveRepository get _repository => ref.read(leaveRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final request = await _repository.find(widget.leaveId);
      if (!mounted) return;
      setState(() {
        _request = request;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _error = _messageFor(failure);
        _loading = false;
      });
    }
  }

  /// Runs one transition and redraws from the server's answer.
  ///
  /// Always re-fetches rather than patching the local copy: the day count,
  /// the reservation and the approval step all change together, and a model
  /// updated from what the request *sent* would be showing the intention
  /// instead of the outcome.
  Future<void> _act(
    Future<LeaveRequest> Function(LeaveRepository repository, String remarks)
    run, {
    required String title,
    required String message,
    String confirmLabel = 'Confirm',
    String remarksLabel = 'Remarks',
    bool remarksRequired = false,
  }) async {
    final remarks = await _ask(
      title: title,
      message: message,
      confirmLabel: confirmLabel,
      remarksLabel: remarksLabel,
      remarksRequired: remarksRequired,
    );

    if (remarks == null || !mounted) return;

    setState(() => _working = true);

    try {
      await run(_repository, remarks);
      await _load();
      // The list behind this screen is a separate piece of state; leaving it
      // stale would show "Awaiting approval" for a request that has been
      // decided the moment you step back to it.
      ref.read(leaveListProvider.notifier).reload();
      if (!mounted) return;
      setState(() => _working = false);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<String?> _ask({
    required String title,
    required String message,
    required String confirmLabel,
    required String remarksLabel,
    required bool remarksRequired,
  }) {
    final controller = TextEditingController();

    return showDialog<String>(
      context: context,
      builder: (dialogContext) {
        String? error;

        return StatefulBuilder(
          builder: (dialogContext, setDialogState) => AlertDialog(
            title: Text(title),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(message),
                const SizedBox(height: 16),
                TextField(
                  controller: controller,
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: remarksRequired
                        ? '$remarksLabel *'
                        : remarksLabel,
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
                  final text = controller.text.trim();

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
        );
      },
    );
  }

  Future<void> _fileCertificate() async {
    final bytes = await CertificateCaptureSheet.show(context);
    if (bytes == null || !mounted) return;

    setState(() => _working = true);

    try {
      await _repository.fileCertificate(widget.leaveId, bytes);
      await _load();
      if (!mounted) return;
      setState(() => _working = false);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _viewCertificate() async {
    setState(() => _working = true);

    try {
      final bytes = await _repository.certificate(widget.leaveId);
      if (!mounted) return;
      setState(() => _working = false);

      final mime = _request?.certificate.mime ?? '';
      final isImage = mime.startsWith('image/');

      await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(
            _request?.certificate.originalName ?? 'Medical certificate',
          ),
          content: SizedBox(
            width: double.maxFinite,
            child: isImage
                ? Image.memory(bytes, fit: BoxFit.contain)
                : const Text(
                    'This file is a document the app cannot display. '
                    'It is stored privately and available to whoever '
                    'reviews the request.',
                  ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      );
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _working = false;
        _banner = _messageFor(failure);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: Text('Leave #${widget.leaveId}')),
      body: _body(scope),
    );
  }

  Widget _body(PermissionScope scope) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('leave-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('leave-error'),
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

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              key: const ValueKey('leave-banner'),
              padding: const EdgeInsets.only(bottom: 16),
              child: Material(
                color: Theme.of(context).colorScheme.errorContainer,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _banner!,
                          style: TextStyle(
                            color: Theme.of(context)
                                .colorScheme
                                .onErrorContainer,
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
          _header(request),
          const SizedBox(height: 16),
          _certificate(request),
          const SizedBox(height: 16),
          if (request.lop != null) ...[
            _lop(request),
            const SizedBox(height: 16),
          ],
          if (request.approvalChain != null &&
              request.approvalChain!.isNotEmpty) ...[
            _chain(request.approvalChain!),
            const SizedBox(height: 16),
          ],
          _actions(request, scope),
        ],
      ),
    );
  }

  Widget _header(LeaveRequest request) {
    final theme = Theme.of(context);

    return Card(
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
                    request.leaveTypeName ?? 'Leave request',
                    style: theme.textTheme.titleLarge,
                  ),
                ),
                StatusChip(
                  label: request.statusLabel,
                  tone: toneForLeaveStatus(request.status),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(request.dateRange, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              // The server's own derived count. Nothing here recounts the
              // days: this is the number the reservation was made for.
              '${request.requestedDays.toStringAsFixed(1)} days'
              '${request.summary.isEmpty ? '' : ' · ${request.summary}'}',
              style: theme.textTheme.bodyMedium,
            ),
            if (request.siteName != null) ...[
              const SizedBox(height: 4),
              Text(
                'Site: ${request.siteName}',
                style: theme.textTheme.bodySmall,
              ),
            ],
            if (request.reason != null && request.reason!.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(request.reason!, style: theme.textTheme.bodyMedium),
            ],
            if (request.remarks != null && request.remarks!.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text('Remarks', style: theme.textTheme.labelLarge),
              Text(request.remarks!, style: theme.textTheme.bodySmall),
            ],
          ],
        ),
      ),
    );
  }

  Widget _certificate(LeaveRequest request) {
    final certificate = request.certificate;
    final theme = Theme.of(context);
    final mayFile = ref.watch(
      permissionScopeProvider.select((scope) => scope.canFileCertificates),
    );

    if (!certificate.required) return const SizedBox.shrink();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Medical certificate', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(child: Text(certificate.statusLabel)),
                StatusChip(
                  label: certificate.overdue ? 'Overdue' : 'Filed',
                  tone: certificate.hasFile
                      ? StatusTone.positive
                      : certificate.overdue
                      ? StatusTone.negative
                      : StatusTone.warning,
                ),
              ],
            ),
            if (certificate.uploadedAt != null) ...[
              const SizedBox(height: 6),
              Text(_filedLine(certificate), style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 12),
            Row(
              children: [
                if (certificate.hasFile)
                  OutlinedButton.icon(
                    key: const ValueKey('view-certificate'),
                    onPressed: _working ? null : _viewCertificate,
                    icon: const Icon(Icons.visibility_outlined),
                    label: const Text('View'),
                  ),
                if (certificate.hasFile) const SizedBox(width: 8),
                if (mayFile)
                  FilledButton.icon(
                    key: const ValueKey('file-certificate'),
                    onPressed: _working ? null : _fileCertificate,
                    icon: const Icon(Icons.photo_camera_outlined),
                    label: Text(
                      certificate.hasFile ? 'Replace' : 'Photograph and file',
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// "certificate.jpg · filed 2026-10-04", and just the name when the date
  /// is missing rather than a trailing "filed" with nothing after it.
  String _filedLine(LeaveCertificate certificate) {
    final uploaded = certificate.uploadedAt;
    final name = certificate.originalName ?? 'Certificate';

    if (uploaded == null || uploaded.length < 10) return name;

    return '$name · filed ${uploaded.substring(0, 10)}';
  }

  Widget _lop(LeaveRequest request) {
    final lop = request.lop!;
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      color: theme.colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Converted to loss of pay',
              style: theme.textTheme.titleMedium?.copyWith(
                color: theme.colorScheme.onErrorContainer,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              '${lop.days.toStringAsFixed(1)} days recorded as LOP'
              '${lop.appliedAt == null ? '' : ' on ${lop.appliedAt!.substring(0, 10)}'}',
              style: TextStyle(color: theme.colorScheme.onErrorContainer),
            ),
            if (lop.reason != null && lop.reason!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                lop.reason!,
                style: TextStyle(color: theme.colorScheme.onErrorContainer),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _chain(List<ApprovalStep> chain) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Approval', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final step in chain)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      step.isApproved
                          ? Icons.check_circle_outline
                          : step.isRejected
                          ? Icons.cancel_outlined
                          : step.isSkipped
                          ? Icons.remove_circle_outline
                          : step.isCurrent
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      size: 20,
                      color: step.isApproved
                          ? const Color(0xFF136C39)
                          : step.isRejected
                          ? theme.colorScheme.error
                          : theme.colorScheme.outline,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${step.sequence}. ${step.name.isEmpty ? 'Step '
                                      '${step.sequence}' : step.name}',
                            style: theme.textTheme.bodyMedium,
                          ),
                          Text(
                            step.approverLabel,
                            style: theme.textTheme.bodySmall,
                          ),
                          if (step.remarks != null && step.remarks!.isNotEmpty)
                            Text(
                              step.remarks!,
                              style: theme.textTheme.bodySmall?.copyWith(
                                fontStyle: FontStyle.italic,
                              ),
                            ),
                        ],
                      ),
                    ),
                    StatusChip(
                      label: step.statusLabel,
                      tone: _toneForStep(step),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  StatusTone _toneForStep(ApprovalStep step) {
    if (step.isApproved) return StatusTone.positive;
    if (step.isRejected) return StatusTone.negative;
    if (step.isSkipped) return StatusTone.neutral;
    if (step.isCurrent) return StatusTone.info;

    return StatusTone.neutral;
  }

  Widget _actions(LeaveRequest request, PermissionScope scope) {
    final canApprove =
        scope.canApproveLeave && request.isAwaitingDecision && !_working;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (request.isDraft) ...[
          FilledButton.icon(
            key: const ValueKey('submit-leave'),
            onPressed: _working
                ? null
                : () => _act(
                    (repository, remarks) =>
                        repository.submit(request.id, remarks: remarks),
                    title: 'Submit this request?',
                    message:
                        'It goes to your approvers and can no longer '
                        'be edited. You can still cancel it while it waits.',
                    confirmLabel: 'Submit',
                  ),
            icon: const Icon(Icons.send_outlined),
            label: const Text('Submit'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('edit-leave'),
            onPressed: _working
                ? null
                : () => context.push('/leave/${request.id}/edit'),
            icon: const Icon(Icons.edit_outlined),
            label: const Text('Edit'),
          ),
        ],
        if (canApprove) ...[
          FilledButton.icon(
            key: const ValueKey('approve-leave'),
            onPressed: () => _act(
              (repository, remarks) =>
                  repository.approve(request.id, remarks: remarks),
              title: 'Approve this request?',
              message:
                  'Approving moves it to the next step, or completes it '
                  'if this is the last one.',
              confirmLabel: 'Approve',
            ),
            icon: const Icon(Icons.check_outlined),
            label: const Text('Approve'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('reject-leave'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository, remarks) =>
                  repository.reject(request.id, remarks: remarks),
              title: 'Reject this request?',
              message:
                  'The days go back into the balance and the employee '
                  'sees your remarks.',
              confirmLabel: 'Reject',
              remarksRequired: true,
            ),
            icon: const Icon(Icons.close_outlined),
            label: const Text('Reject'),
          ),
        ],
        // Cancel is the *owner's* escape hatch, not an approver's: the
        // server allows it for the person who made the request or for
        // `leave.manage`, and an approver at the current step holds neither.
        // Drawing it here would be a button whose only possible answer is a
        // 403 from a screen that already knows better.
        if ((request.isDraft || request.isAwaitingDecision) &&
            !_working &&
            !canApprove) ...[
          OutlinedButton.icon(
            key: const ValueKey('cancel-leave'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository, remarks) =>
                  repository.cancel(request.id, remarks: remarks),
              title: 'Cancel this request?',
              message: request.isDraft
                  ? 'The draft is discarded. Nothing was ever reserved.'
                  : 'Any days already reserved go back into your balance.',
              confirmLabel: 'Cancel request',
            ),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Cancel request'),
          ),
        ],
      ],
    );
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) {
      final fieldErrors = failure.errors.values;

      if (fieldErrors.isNotEmpty) return fieldErrors.join('\n');

      return failure.message;
    }

    return 'Something went wrong. Please try again.';
  }
}
