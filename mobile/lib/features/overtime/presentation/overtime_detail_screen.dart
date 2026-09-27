import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/data/approval_step.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_overtime_repository.dart';
import '../domain/overtime_repository.dart';
import '../domain/overtime_request.dart';
import 'overtime_controller.dart';
import 'overtime_list_screen.dart';

/// One overtime claim, everything the server knows about it, and the actions
/// this session may attempt.
///
/// The approved figure is the one thing on this screen with two possible
/// sources, and it is worth saying which is which: [OvertimeRequest]
/// `requestedMinutes` is what was asked, `approvedMinutes` is what the
/// approver granted — and when the approver granted the lot, the server
/// sends nothing for it, because "absent" and "zero" are different answers
/// to "how much was allowed". `payableMinutes` resolves that once, here.
class OvertimeDetailScreen extends ConsumerStatefulWidget {
  const OvertimeDetailScreen({super.key, required this.overtimeId});

  final int overtimeId;

  @override
  ConsumerState<OvertimeDetailScreen> createState() =>
      _OvertimeDetailScreenState();
}

class _OvertimeDetailScreenState extends ConsumerState<OvertimeDetailScreen> {
  OvertimeRequest? _claim;
  String? _error;
  bool _loading = true;
  bool _working = false;
  String? _banner;

  OvertimeRepository get _repository => ref.read(overtimeRepositoryProvider);

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
      final claim = await _repository.find(widget.overtimeId);
      if (!mounted) return;
      setState(() {
        _claim = claim;
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

  Future<void> _act(
    Future<OvertimeRequest> Function(OvertimeRepository repository) run, {
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

    if (answer == null || !mounted) return;

    await _run(() => run(_repository));
  }

  /// Runs one transition, then redraws from the server's answer.
  ///
  /// Always re-fetches rather than patching the local copy: the approved
  /// minutes, the payroll flag and the approval step all move together, and
  /// a model updated from what the request *sent* would be showing the
  /// intention instead of the outcome.
  Future<void> _run(Future<OvertimeRequest> Function() run) async {
    setState(() => _working = true);

    try {
      await run();
      await _load();
      ref.read(overtimeListProvider.notifier).reload();
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

  Future<void> _approveFlow(OvertimeRequest claim) async {
    final answer = await _ask(
      title: 'Approve this claim?',
      message:
          'Approving sets the minutes that reach payroll. Leaving it '
          'empty allows everything that was asked for.',
      confirmLabel: 'Approve',
      withMinutes: true,
      requested: claim.requestedMinutes,
    );

    if (answer == null || !mounted) return;

    await _run(
      () => _repository.approve(
        claim.id,
        remarks: answer.remarks,
        approvedMinutes: answer.minutes,
      ),
    );
  }

  Future<({String remarks, int? minutes})?> _ask({
    required String title,
    required String message,
    required String confirmLabel,
    bool remarksRequired = false,
    bool withMinutes = false,
    int requested = 0,
  }) {
    final remarks = TextEditingController();
    final minutes = TextEditingController();
    String? error;

    return showDialog<({String remarks, int? minutes})>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: Text(title),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(message),
              if (withMinutes) ...[
                const SizedBox(height: 16),
                TextField(
                  controller: minutes,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: 'Minutes to allow',
                    helperText: 'Leave empty to allow all $requested',
                    errorText: error,
                    border: const OutlineInputBorder(),
                    isDense: true,
                  ),
                ),
              ],
              const SizedBox(height: 16),
              TextField(
                controller: remarks,
                maxLines: 3,
                decoration: InputDecoration(
                  labelText: remarksRequired ? 'Remarks *' : 'Remarks',
                  errorText: withMinutes ? null : error,
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
                final remarkText = remarks.text.trim();
                final raw = minutes.text.trim();
                int? allowed;

                if (remarksRequired && remarkText.isEmpty) {
                  setDialogState(() => error = 'Say why before confirming.');
                  return;
                }

                if (raw.isNotEmpty) {
                  allowed = int.tryParse(raw);

                  if (allowed == null || allowed < 1) {
                    setDialogState(
                      () => error = 'Enter how many minutes to allow.',
                    );
                    return;
                  }
                }

                Navigator.of(dialogContext)
                    .pop((remarks: remarkText, minutes: allowed));
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
      appBar: AppBar(title: Text('Overtime #${widget.overtimeId}')),
      body: _body(scope),
    );
  }

  Widget _body(PermissionScope scope) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('overtime-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('overtime-error'),
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

    final claim = _claim!;
    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              key: const ValueKey('overtime-banner'),
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
                          claim.overtimeDate,
                          style: theme.textTheme.titleLarge,
                        ),
                      ),
                      StatusChip(
                        label: claim.statusLabel,
                        tone: toneForOvertimeStatus(claim.status),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _row(theme, 'Asked', claim.requestedLabel),
                  if (claim.payableMinutes != null)
                    _row(
                      theme,
                      'Allowed',
                      claim.approvedLabel ?? claim.requestedLabel,
                    ),
                  _row(theme, 'Person', claim.employeeName),
                  _row(theme, 'Site', claim.siteName),
                  _row(theme, 'Project', claim.projectName),
                  const SizedBox(height: 8),
                  Text(claim.reason, style: theme.textTheme.bodyMedium),
                  if (claim.remarks != null && claim.remarks!.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Text('Remarks', style: theme.textTheme.labelLarge),
                    Text(claim.remarks!, style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
            ),
          ),
          if (claim.payrollEligible)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Card(
                margin: EdgeInsets.zero,
                color: const Color(0xFFD6F5E1),
                child: const Padding(
                  padding: EdgeInsets.all(12),
                  child: Text(
                    'Approved and marked eligible for payroll.',
                    style: TextStyle(color: Color(0xFF136C39)),
                  ),
                ),
              ),
            ),
          if (claim.approvalChain != null &&
              claim.approvalChain!.isNotEmpty) ...[
            const SizedBox(height: 16),
            _chain(claim.approvalChain!, theme),
          ],
          const SizedBox(height: 16),
          _actions(claim, scope),
        ],
      ),
    );
  }

  Widget _chain(List<ApprovalStep> chain, ThemeData theme) {
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
                            '${step.sequence}. ${step.name.isEmpty ? 'Step ${step.sequence}' : step.name}',
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
                      tone: step.isApproved
                          ? StatusTone.positive
                          : step.isRejected
                          ? StatusTone.negative
                          : step.isCurrent
                          ? StatusTone.info
                          : StatusTone.neutral,
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
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

  Widget _actions(OvertimeRequest claim, PermissionScope scope) {
    final canApprove =
        scope.canApproveOvertime && claim.isAwaitingDecision && !_working;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (claim.isDraft) ...[
          FilledButton.icon(
            key: const ValueKey('submit-overtime'),
            onPressed: _working
                ? null
                : () => _act(
                    (repository) => repository.submit(claim.id),
                    title: 'Submit this claim?',
                    message:
                        'It goes to your approvers and can no longer '
                        'be edited.',
                    confirmLabel: 'Submit',
                  ),
            icon: const Icon(Icons.send_outlined),
            label: const Text('Submit'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('edit-overtime'),
            onPressed: _working
                ? null
                : () => context.push('/overtime/${claim.id}/edit'),
            icon: const Icon(Icons.edit_outlined),
            label: const Text('Edit'),
          ),
        ],
        if (canApprove) ...[
          FilledButton.icon(
            key: const ValueKey('approve-overtime'),
            onPressed: () => _approveFlow(claim),
            icon: const Icon(Icons.check_outlined),
            label: const Text('Approve'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('reject-overtime'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository) => repository.reject(claim.id),
              title: 'Reject this claim?',
              message: 'Nothing is paid, and the claimant sees your remarks.',
              confirmLabel: 'Reject',
              remarksRequired: true,
            ),
            icon: const Icon(Icons.close_outlined),
            label: const Text('Reject'),
          ),
        ],
        if ((claim.isDraft || claim.isAwaitingDecision) &&
            !_working &&
            !canApprove) ...[
          OutlinedButton.icon(
            key: const ValueKey('cancel-overtime'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => _act(
              (repository) => repository.cancel(claim.id),
              title: 'Cancel this claim?',
              message: claim.isDraft
                  ? 'The draft is discarded.'
                  : 'The claim leaves the approval chain.',
              confirmLabel: 'Cancel claim',
            ),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Cancel claim'),
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
