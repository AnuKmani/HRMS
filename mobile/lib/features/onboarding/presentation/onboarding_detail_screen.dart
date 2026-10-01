import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_onboarding_repository.dart';
import '../domain/onboarding.dart';
import '../domain/onboarding_repository.dart';
import 'onboarding_controller.dart';

/// One person's onboarding: where it stands, what is outstanding, and the
/// moves HR may make.
///
/// Four things this screen is careful about:
///
///  - **the checklist is the point, and it is recomputed server-side.** Every
///    row here answers *both* a requirement and its evidence, so a document
///    uploaded a minute ago turns `missing` into `pending_verification`
///    without a page reload trick — the client never guesses a state.
///
///  - **the five states stay five states.** `missing`, `pending_verification`,
///    `rejected`, `expired` and `satisfied` are five different people's
///    problem, and collapsing them into a tick would tell none of them what to
///    do.
///
///  - **completion refuses with a 409 that names what is outstanding.** The
///    API's answer *is* the message, so this screen shows it verbatim rather
///    than substituting a generic "cannot complete".
///
///  - **an employee may read their own record and nothing else.** Moving the
///    stage is `onboarding.manage` with no self-service exception: an employee
///    marking their own requirements complete is the same self-verification
///    `documents.verify` refuses.
class OnboardingDetailScreen extends ConsumerStatefulWidget {
  const OnboardingDetailScreen({super.key, required this.employeeId});

  final int employeeId;

  @override
  ConsumerState<OnboardingDetailScreen> createState() =>
      _OnboardingDetailScreenState();
}

class _OnboardingDetailScreenState
    extends ConsumerState<OnboardingDetailScreen> {
  Onboarding? _record;
  late final TextEditingController _notes;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;

  String? _banner;

  OnboardingRepository get _repository =>
      ref.read(onboardingRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _notes = TextEditingController();
    if (ref.read(permissionScopeProvider).canViewOnboarding) _load();
  }

  @override
  void didUpdateWidget(covariant OnboardingDetailScreen oldWidget) {
    super.didUpdateWidget(oldWidget);

    // Same reason as the document detail screen: the State outlives a route
    // that comes back pointing at a different joiner, and the checklist it
    // would otherwise keep is that person's.
    if (oldWidget.employeeId != widget.employeeId) _load();
  }

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final record = await _repository.find(widget.employeeId);
      if (!mounted) return;

      _notes.text = record.notes ?? '';

      setState(() {
        _record = record;
        _loading = false;
        _forbidden = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _changeStatus(String? status) async {
    if (status == null || _saving) return;

    await _save(<String, Object?>{'status': status});
  }

  Future<void> _saveNotes() async {
    if (_saving) return;

    await _save(<String, Object?>{'notes': _notes.text.trim()});
  }

  Future<void> _save(Map<String, Object?> body) async {
    setState(() {
      _saving = true;
      _banner = null;
    });

    try {
      final record = await _repository.update(widget.employeeId, body);
      if (!mounted) return;

      setState(() {
        _record = record;
        _saving = false;
      });

      _notes.text = record.notes ?? '';
      ref.read(onboardingListProvider.notifier).reload();
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _banner = _messageFor(failure);
      });
    }
  }

  Future<void> _complete() async {
    if (_saving) return;

    setState(() {
      _saving = true;
      _banner = null;
    });

    try {
      final record = await _repository.complete(widget.employeeId);
      if (!mounted) return;

      setState(() {
        _record = record;
        _saving = false;
      });

      ref.read(onboardingListProvider.notifier).reload();
    } catch (failure) {
      if (!mounted) return;
      // A 409 here names the requirements still outstanding. Showing that
      // sentence verbatim is the whole reason the server chose 409 over 403:
      // it is the answer, not an error code.
      setState(() {
        _saving = false;
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = _messageFor(failure);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    if (!scope.canViewOnboarding && !_forbidden) {
      return Scaffold(
        appBar: AppBar(title: const Text('Onboarding')),
        body: const NoPermission(module: 'onboarding'),
      );
    }

    final record = _record;
    final theme = Theme.of(context);

    if (_loading && record == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Onboarding')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    if (record == null) {
      // A 403 on a row the session *could* list is a refusal about that
      // record, not about the module — but it is still a refusal, and
      // drawing it as "record not found" would send somebody hunting for a
      // person who is right there.
      if (_forbidden) {
        return Scaffold(
          appBar: AppBar(title: const Text('Onboarding')),
          body: const NoPermission(module: 'onboarding'),
        );
      }

      return Scaffold(
        appBar: AppBar(title: const Text('Onboarding')),
        body: Center(
          key: const ValueKey('onboarding-not-found'),
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Text(
              _banner ?? 'This record could not be loaded.',
              textAlign: TextAlign.center,
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(record.employeeName ?? 'Onboarding'),
        actions: [
          IconButton(
            key: const ValueKey('reload-onboarding'),
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh),
            onPressed: _load,
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
                key: const ValueKey('onboarding-banner'),
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
            if (_saving) const LinearProgressIndicator(),
            _StatusCard(record: record),
            if (scope.canManageOnboarding) ...[
              const SizedBox(height: 16),
              _statusField(context, record),
            ],
            const SizedBox(height: 8),
            Text('Requirements', style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            if (!record.hasChecklist)
              Padding(
                key: const ValueKey('onboarding-no-checklist'),
                padding: const EdgeInsets.symmetric(vertical: 24),
                child: Text(
                  'The checklist is shown for one person at a time. Pull to '
                  'refresh.',
                  style: theme.textTheme.bodySmall,
                ),
              )
            else
              for (final item in record.requirements)
                _RequirementTile(
                  item: item,
                  canAttach:
                      scope.canCreateDocuments &&
                      item.kind == OnboardingChecklistItem.kindDocument &&
                      !item.isSatisfied,
                  onAttach: () => context.push(
                    '/documents/new',
                    extra: <String, Object?>{
                      'typeCode': item.code,
                      'employeeId': record.employeeId,
                    },
                  ),
                ),
            if (record.requirements.isNotEmpty &&
                record.missingRequirements.isNotEmpty)
              Padding(
                key: const ValueKey('onboarding-missing'),
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'Outstanding: ${record.missingRequirements.join(', ')}',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            if (scope.canManageOnboarding) ...[
              const SizedBox(height: 16),
              _notesField(context),
              const SizedBox(height: 8),
              FilledButton(
                key: const ValueKey('complete-onboarding'),
                onPressed: record.canComplete && !_saving ? _complete : null,
                child: const Text('Complete onboarding'),
              ),
              if (!record.canComplete && record.hasChecklist)
                Padding(
                  key: const ValueKey('onboarding-cannot-complete'),
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    'Completing unlocks once every mandatory requirement is '
                    'met.',
                    style: theme.textTheme.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                ),
            ],
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _statusField(BuildContext context, Onboarding record) =>
      InputDecorator(
        decoration: const InputDecoration(
          labelText: 'Stage',
          border: OutlineInputBorder(),
          isDense: true,
        ),
        child: DropdownButtonHideUnderline(
          child: DropdownButton<String>(
            key: const ValueKey('onboarding-status'),
            isExpanded: true,
            value: record.status,
            items: [
              for (final status in Onboarding.statuses)
                DropdownMenuItem<String>(
                  value: status,
                  child: Text(_labelFor(status)),
                ),
            ],
            onChanged: _saving ? null : _changeStatus,
          ),
        ),
      );

  Widget _notesField(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      TextField(
        key: const ValueKey('onboarding-notes'),
        controller: _notes,
        maxLines: 3,
        maxLength: 1000,
        decoration: const InputDecoration(
          labelText: 'Notes',
          hintText: 'Anything HR should know about this joiner',
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 8),
      OutlinedButton(
        key: const ValueKey('save-onboarding-notes'),
        onPressed: _saving ? null : _saveNotes,
        child: const Text('Save notes'),
      ),
    ],
  );

  static String _labelFor(String status) => switch (status) {
    Onboarding.statusDraft => 'Not started',
    Onboarding.statusPendingDocuments => 'Waiting on documents',
    Onboarding.statusHrReview => 'HR review',
    Onboarding.statusCompleted => 'Completed',
    _ => status,
  };

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}

class _StatusCard extends StatelessWidget {
  const _StatusCard({required this.record});

  final Onboarding record;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final progress = record.progress;

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
                    record.employeeCode?.isNotEmpty == true
                        ? '${record.employeeName ?? ''} (${record.employeeCode!})'
                        : record.employeeName ?? '',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                StatusChip(label: record.statusLabel, tone: _toneFor(record)),
              ],
            ),
            if (record.progressLabel.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(record.progressLabel, style: theme.textTheme.bodySmall),
              const SizedBox(height: 8),
              // A determinate bar beside the sentence: the words carry the
              // meaning for anyone who cannot read the fill, and the bar is
              // never the only copy of the number.
              LinearProgressIndicator(value: progress),
            ],
            if (record.completedAt != null) ...[
              const SizedBox(height: 12),
              Text(
                'Completed ${record.completedAt}',
                style: theme.textTheme.bodySmall,
              ),
            ],
            if (record.startedAt != null) ...[
              const SizedBox(height: 4),
              Text(
                'Started ${record.startedAt}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  static StatusTone _toneFor(Onboarding record) => switch (record.status) {
    Onboarding.statusCompleted => StatusTone.positive,
    Onboarding.statusHrReview => StatusTone.info,
    Onboarding.statusPendingDocuments => StatusTone.warning,
    _ => StatusTone.neutral,
  };
}

class _RequirementTile extends StatelessWidget {
  const _RequirementTile({
    required this.item,
    required this.canAttach,
    required this.onAttach,
  });

  final OnboardingChecklistItem item;
  final bool canAttach;
  final VoidCallback onAttach;

  @override
  Widget build(BuildContext context) {
    final detail = item.stateDetail;

    return ListTile(
      key: ValueKey('requirement-${item.code}'),
      dense: true,
      contentPadding: EdgeInsets.zero,
      title: Text(item.name),
      subtitle: detail.isEmpty
          ? (item.description == null ? null : Text(item.description!))
          : Text(detail, key: const ValueKey('requirement-detail')),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          StatusChip(label: item.stateLabel, tone: _toneFor(item.state)),
          if (canAttach)
            IconButton(
              key: ValueKey('attach-${item.code}'),
              tooltip: 'Attach this document',
              icon: const Icon(Icons.attach_file),
              onPressed: onAttach,
            ),
        ],
      ),
      onTap: canAttach ? onAttach : null,
    );
  }

  static StatusTone _toneFor(String state) => switch (state) {
    OnboardingChecklistItem.stateSatisfied => StatusTone.positive,
    OnboardingChecklistItem.stateRejected => StatusTone.negative,
    OnboardingChecklistItem.stateExpired => StatusTone.negative,
    OnboardingChecklistItem.statePendingVerification => StatusTone.info,
    OnboardingChecklistItem.stateMissing => StatusTone.warning,
    _ => StatusTone.neutral,
  };
}
