import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../documents/data/document_source.dart';
import '../data/project_delivery_api.dart';
import '../domain/project.dart';
import '../domain/project_delivery.dart';

/// Run [action]; on failure show the server's sentence. True when it worked.
Future<bool> runAction(
  BuildContext context,
  Future<void> Function() action, {
  String? done,
}) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await action();
    if (done != null) messenger.showSnackBar(SnackBar(content: Text(done)));
    return true;
  } on ApiException catch (failure) {
    messenger.showSnackBar(
      SnackBar(
        content: Text(
          failure.errors.values.isNotEmpty
              ? failure.errors.values.first
              : failure.message,
        ),
      ),
    );
  } on StateError catch (failure) {
    messenger.showSnackBar(SnackBar(content: Text(failure.message)));
  } catch (_) {
    messenger.showSnackBar(
      const SnackBar(content: Text('Something went wrong. Please try again.')),
    );
  }
  return false;
}

/// A titled, bordered block like the rest of the project page.
class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    required this.title,
    required this.children,
    this.action,
  });

  final String title;
  final List<Widget> children;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            ?action,
          ],
        ),
        const SizedBox(height: 8),
        DecoratedBox(
          decoration: BoxDecoration(
            border: Border.all(color: Theme.of(context).dividerColor),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children,
          ),
        ),
      ],
    ),
  );
}

/// Big percentage, bar, expected-by-today and the behind-schedule flag.
class ProgressHeadline extends StatelessWidget {
  const ProgressHeadline({
    super.key,
    required this.percent,
    this.expected,
    this.behind = false,
  });

  final double percent;
  final double? expected;
  final bool behind;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                pct(percent),
                key: const ValueKey('progress-percent'),
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(width: 8),
              const Text('complete'),
              const Spacer(),
              if (behind) const BehindChip(),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: (percent / 100).clamp(0, 1),
              minHeight: 10,
            ),
          ),
          if (expected != null) ...[
            const SizedBox(height: 6),
            Text(
              'Expected by today: ${pct(expected)}',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}

class BehindChip extends StatelessWidget {
  const BehindChip({super.key});

  @override
  Widget build(BuildContext context) => Container(
    key: const ValueKey('behind-schedule'),
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color: Colors.red.shade50,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Text(
      'Behind schedule',
      style: TextStyle(
        color: Colors.red.shade800,
        fontSize: 12,
        fontWeight: FontWeight.w600,
      ),
    ),
  );
}

/// One row per stage with its own bar. [onTap] lets the PM correct it.
class StageList extends StatelessWidget {
  const StageList({
    super.key,
    required this.stages,
    this.onTap,
    this.showMoney = false,
  });

  final List<ProjectStage> stages;
  final void Function(ProjectStage)? onTap;

  /// Each system's value and what has been billed.
  final bool showMoney;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      if (stages.isEmpty)
        const Padding(
          padding: EdgeInsets.all(12),
          child: Text('No stages yet.'),
        ),
      for (final stage in stages)
        ListTile(
          key: ValueKey('stage-${stage.id}'),
          dense: true,
          onTap: onTap == null ? null : () => onTap!(stage),
          title: Text('${stage.name}  ·  weight ${pct(stage.weight)}'),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: LinearProgressIndicator(
                  value: (stage.percentComplete / 100).clamp(0, 1),
                  minHeight: 6,
                ),
              ),
              if (showMoney && stage.contractValue != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    '${money(stage.contractValue)} · billed '
                    '${money(stage.billedAmount)} (${pct(stage.billedPercent)})',
                  ),
                ),
            ],
          ),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(pct(stage.percentComplete)),
              if (onTap != null) const Icon(Icons.edit_outlined, size: 18),
            ],
          ),
        ),
    ],
  );
}

/// Every change to progress, newest first.
Future<void> showProgressHistory(
  BuildContext context,
  WidgetRef ref,
  int projectId,
) async {
  final api = ref.read(projectDeliveryApiProvider);
  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (context) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.7,
      builder: (context, controller) => FutureBuilder<List<ProgressUpdate>>(
        future: api.history(projectId),
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            return const Center(child: Text('Could not load the history.'));
          }
          final rows = snap.data ?? const [];
          return ListView(
            controller: controller,
            children: [
              const ListTile(
                title: Text(
                  'Progress history',
                  style: TextStyle(fontWeight: FontWeight.bold),
                ),
              ),
              if (rows.isEmpty) const ListTile(title: Text('No updates yet.')),
              for (final u in rows)
                ListTile(
                  leading: Icon(
                    u.source == 'manual'
                        ? Icons.edit_note
                        : Icons.assignment_outlined,
                  ),
                  title: Text(
                    '${u.stage ?? 'Stage'} → ${pct(u.stagePercent)}  (project ${pct(u.projectPercent)})',
                  ),
                  subtitle: Text(
                    [
                      u.sourceLabel,
                      ?u.by,
                      if (u.at != null) u.at!.substring(0, 10),
                      ?u.note,
                    ].join(' · '),
                  ),
                ),
            ],
          );
        },
      ),
    ),
  );
}

/// A payment milestone; Sales gets the buttons that move it on.
class MilestoneTile extends ConsumerWidget {
  const MilestoneTile({
    super.key,
    required this.projectId,
    required this.milestone,
    required this.onChanged,
    this.canRemove = false,
  });

  final int projectId;
  final PaymentMilestone milestone;
  final VoidCallback onChanged;
  final bool canRemove;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final billing = ref
        .watch(permissionScopeProvider)
        .can('projects.billing.manage');
    final api = ref.read(projectDeliveryApiProvider);
    final m = milestone;
    final color = switch (m.status) {
      'reached' => Colors.orange.shade800,
      'requested' => Colors.blue.shade700,
      'paid' => Colors.green.shade700,
      _ => Colors.grey.shade600,
    };

    return ListTile(
      key: ValueKey('milestone-${m.id}'),
      leading: Icon(
        m.status == 'paid' ? Icons.check_circle : Icons.flag_outlined,
        color: color,
      ),
      title: Text(m.name),
      subtitle: Text(
        [
          'at ${pct(m.triggerPercent)}',
          if (m.amountPercent != null) '${pct(m.amountPercent)} of contract',
          if (m.amount != null) 'AED ${m.amount!.toStringAsFixed(0)}',
          m.statusLabel,
        ].join(' · '),
        style: TextStyle(color: color),
      ),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (billing && m.status == 'reached')
            TextButton(
              key: ValueKey('milestone-request-${m.id}'),
              onPressed: () async {
                if (await runAction(
                  context,
                  () => api.setMilestoneStatus(projectId, m.id, 'requested'),
                  done: 'Marked as requested.',
                )) {
                  onChanged();
                }
              },
              child: const Text('Mark requested'),
            ),
          if (billing && m.status == 'requested')
            TextButton(
              key: ValueKey('milestone-paid-${m.id}'),
              onPressed: () async {
                if (await runAction(
                  context,
                  () => api.setMilestoneStatus(projectId, m.id, 'paid'),
                  done: 'Marked as paid.',
                )) {
                  onChanged();
                }
              },
              child: const Text('Mark paid'),
            ),
          if (canRemove && (m.status == 'pending' || m.status == 'reached'))
            IconButton(
              tooltip: 'Remove milestone',
              icon: const Icon(Icons.close),
              onPressed: () async {
                if (await runAction(
                  context,
                  () => api.removeMilestone(projectId, m.id),
                )) {
                  onChanged();
                }
              },
            ),
        ],
      ),
    );
  }
}

/// Everything below the project's details: progress, subcontractors, team,
/// payment milestones and documents. Changes only for [canEdit].
class ProjectDeliverySections extends ConsumerWidget {
  const ProjectDeliverySections({
    super.key,
    required this.project,
    required this.canEdit,
    this.canCoordinate = false,
    this.canSeeMoney = false,
    this.canBill = false,
    required this.onChanged,
  });

  final Project project;
  final bool canEdit;

  /// Dates, documents and subcontractor contacts (Project Coordinator).
  final bool canCoordinate;

  final bool canSeeMoney;

  /// May record what the company billed (editor or Sales).
  final bool canBill;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final api = ref.read(projectDeliveryApiProvider);
    final p = project;
    final active = p.subcontractors.where((c) => !c.isRemoved).toList();
    final removed = p.subcontractors.where((c) => c.isRemoved).toList();
    final engineers = p.team.where((m) => m.role == 'site_engineer').toList();
    final supervisors = p.team
        .where((m) => m.role == 'site_supervisor')
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // ---- Progress
        SectionCard(
          title: 'Progress',
          action: TextButton.icon(
            key: const ValueKey('progress-pdf'),
            icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
            label: const Text('PDF'),
            onPressed: () =>
                runAction(context, () => api.openProgressPdf(p.id, p.code)),
          ),
          children: [
            ProgressHeadline(
              percent: p.progressPercent,
              expected: p.expectedPercent,
              behind: p.behindSchedule,
            ),
            const Divider(height: 1),
            StageList(
              stages: p.stages,
              showMoney: canSeeMoney,
              onTap: canEdit || canBill
                  ? (s) => _stageActions(context, ref, s)
                  : null,
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: Wrap(
                spacing: 8,
                children: [
                  TextButton.icon(
                    key: const ValueKey('progress-history'),
                    icon: const Icon(Icons.history, size: 18),
                    label: const Text('History'),
                    onPressed: () => showProgressHistory(context, ref, p.id),
                  ),
                  if (canEdit)
                    TextButton.icon(
                      key: const ValueKey('stages-edit'),
                      icon: const Icon(Icons.tune, size: 18),
                      label: const Text('Edit systems / stages'),
                      onPressed: () => _editStages(context, ref),
                    ),
                ],
              ),
            ),
            if (canEdit)
              const Padding(
                padding: EdgeInsets.fromLTRB(12, 0, 12, 12),
                child: Text(
                  'Progress updates when site engineers and supervisors submit their daily report. Tap a stage to correct it.',
                  style: TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ),
          ],
        ),

        // ---- Subcontractors
        SectionCard(
          title: 'Subcontractors',
          action: canEdit
              ? TextButton.icon(
                  key: const ValueKey('sub-add'),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add'),
                  onPressed: () => _editSubcontractor(context, ref, null),
                )
              : null,
          children: [
            ListTile(
              dense: true,
              leading: const Icon(Icons.apartment),
              title: Text('Main contractor: ${p.mainContractor ?? '—'}'),
            ),
            if (active.isEmpty)
              const Padding(
                padding: EdgeInsets.all(12),
                child: Text('No subcontractors.'),
              ),
            for (final c in active) _subTile(context, ref, c),
            if (removed.isNotEmpty)
              ExpansionTile(
                key: const ValueKey('sub-removed'),
                title: Text('Removed (${removed.length})'),
                children: [for (final c in removed) _subTile(context, ref, c)],
              ),
          ],
        ),

        // ---- Team
        SectionCard(
          title: 'Project team',
          action: canEdit
              ? TextButton.icon(
                  key: const ValueKey('team-edit'),
                  icon: const Icon(Icons.group_add_outlined, size: 18),
                  label: const Text('Edit'),
                  onPressed: () => _editTeam(context, ref),
                )
              : null,
          children: [
            if (p.team.isEmpty)
              const Padding(
                padding: EdgeInsets.all(12),
                child: Text('Nobody on the team yet.'),
              ),
            for (final m in [
              ...engineers,
              ...supervisors,
              ...p.team.where((m) => m.role == 'coordinator'),
            ])
              ListTile(
                dense: true,
                leading: const Icon(Icons.engineering_outlined),
                title: Text(m.name),
                subtitle: Text(m.roleLabel),
              ),
          ],
        ),

        // ---- Payment milestones
        SectionCard(
          title: 'Payment milestones',
          action: canEdit
              ? TextButton.icon(
                  key: const ValueKey('milestone-add'),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add'),
                  onPressed: () => _addMilestone(context, ref),
                )
              : null,
          children: [
            if (p.milestones.isEmpty)
              const Padding(
                padding: EdgeInsets.all(12),
                child: Text(
                  'None yet. Sales is told when progress reaches a milestone.',
                ),
              ),
            for (final m in p.milestones)
              MilestoneTile(
                projectId: p.id,
                milestone: m,
                onChanged: onChanged,
                canRemove: canEdit,
              ),
          ],
        ),

        // ---- Documents
        SectionCard(
          title: 'Documents',
          action: canCoordinate
              ? TextButton.icon(
                  key: const ValueKey('doc-add'),
                  icon: const Icon(Icons.upload_file, size: 18),
                  label: const Text('Upload'),
                  onPressed: () => _uploadDocument(context, ref),
                )
              : null,
          children: [
            if (p.documents.isEmpty)
              const Padding(
                padding: EdgeInsets.all(12),
                child: Text('No drawings, contracts or permits yet.'),
              ),
            for (final d in p.documents)
              ListTile(
                key: ValueKey('doc-${d.id}'),
                dense: true,
                leading: const Icon(Icons.description_outlined),
                title: Text(d.title),
                subtitle: Text([d.categoryLabel, ?d.originalName].join(' · ')),
                onTap: () =>
                    runAction(context, () => api.openDocument(p.id, d)),
                trailing: canCoordinate
                    ? IconButton(
                        tooltip: 'Remove document',
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () async {
                          final ok = await _confirm(
                            context,
                            'Remove "${d.title}"?',
                          );
                          if (ok &&
                              context.mounted &&
                              await runAction(
                                context,
                                () => api.removeDocument(p.id, d.id),
                              )) {
                            await onChanged();
                          }
                        },
                      )
                    : null,
              ),
          ],
        ),
      ],
    );
  }

  Widget _subTile(BuildContext context, WidgetRef ref, Subcontractor c) {
    final api = ref.read(projectDeliveryApiProvider);
    final lines = <String>[
      ?c.trade,
      if (c.contactName != null || c.contactPhone != null)
        [?c.contactName, ?c.contactPhone].join(' '),
      if (c.projectStageId != null)
        'System: ${project.stages.where((st) => st.id == c.projectStageId).map((st) => st.name).firstOrNull ?? '—'}',
      if (canSeeMoney && c.contractValue != null)
        '${money(c.contractValue)} · billed ${money(c.billedAmount)}'
            '${c.contractValue! > 0 ? ' (${pct((c.billedAmount ?? 0) / c.contractValue! * 100)})' : ''}',
      if (c.startDate != null || c.endDate != null)
        '${c.startDate ?? '?'} → ${c.endDate ?? '?'}',
      if (c.isRemoved) 'Removed ${c.removedOn ?? ''}: ${c.removedReason ?? ''}',
    ];

    return ListTile(
      key: ValueKey('sub-${c.id}'),
      leading: Icon(
        Icons.handyman_outlined,
        color: c.isRemoved ? Colors.grey : null,
      ),
      title: Text(
        c.name,
        style: TextStyle(color: c.isRemoved ? Colors.grey : null),
      ),
      subtitle: lines.isEmpty ? null : Text(lines.join('\n')),
      isThreeLine: lines.length > 1,
      trailing: !canEdit && !canCoordinate
          ? null
          : PopupMenuButton<String>(
              key: ValueKey('sub-menu-${c.id}'),
              onSelected: (choice) async {
                switch (choice) {
                  case 'edit':
                    await _editSubcontractor(context, ref, c);
                  case 'remove':
                    await _removeSubcontractor(context, ref, c);
                  case 'restore':
                    if (await runAction(
                      context,
                      () => api.restoreSubcontractor(project.id, c.id),
                      done: '${c.name} added back.',
                    )) {
                      await onChanged();
                    }
                  case 'attach':
                    final picked = await _pickFile(const [
                      'pdf',
                      'jpg',
                      'jpeg',
                      'png',
                      'doc',
                      'docx',
                      'xls',
                      'xlsx',
                    ]);
                    if (picked != null &&
                        context.mounted &&
                        await runAction(
                          context,
                          () => api.uploadSubcontractorDocument(
                            project.id,
                            c.id,
                            picked.bytes,
                            picked.filename,
                          ),
                          done: 'Document attached.',
                        )) {
                      await onChanged();
                    }
                  case 'open':
                    await runAction(
                      context,
                      () => api.openSubcontractorDocument(
                        project.id,
                        c.id,
                        c.documentName ?? 'contract.pdf',
                      ),
                    );
                }
              },
              itemBuilder: (_) => [
                if (!c.isRemoved)
                  const PopupMenuItem(value: 'edit', child: Text('Edit')),
                if (canEdit && !c.isRemoved)
                  const PopupMenuItem(
                    value: 'attach',
                    child: Text('Attach contract / LPO'),
                  ),
                if (canEdit && c.hasDocument)
                  PopupMenuItem(
                    value: 'open',
                    child: Text('Open ${c.documentName ?? 'document'}'),
                  ),
                if (canEdit && !c.isRemoved)
                  const PopupMenuItem(value: 'remove', child: Text('Remove…')),
                if (canEdit && c.isRemoved)
                  const PopupMenuItem(
                    value: 'restore',
                    child: Text('Add back'),
                  ),
              ],
            ),
    );
  }

  /// Correct progress (the project's editor) or record billing.
  Future<void> _stageActions(
    BuildContext context,
    WidgetRef ref,
    ProjectStage stage,
  ) async {
    if (canEdit && !canBill) return _correct(context, ref, stage);
    if (!canEdit) return _bill(context, ref, stage);
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(
                stage.name,
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
            ),
            ListTile(
              key: const ValueKey('stage-correct'),
              leading: const Icon(Icons.trending_up),
              title: const Text('Correct progress %'),
              onTap: () => Navigator.pop(context, 'correct'),
            ),
            ListTile(
              key: const ValueKey('stage-bill'),
              leading: const Icon(Icons.receipt_long_outlined),
              title: const Text('Record amount billed'),
              onTap: () => Navigator.pop(context, 'bill'),
            ),
          ],
        ),
      ),
    );
    if (!context.mounted) return;
    if (choice == 'correct') {
      await _correct(context, ref, stage);
    } else if (choice == 'bill') {
      await _bill(context, ref, stage);
    }
  }

  Future<void> _bill(
    BuildContext context,
    WidgetRef ref,
    ProjectStage stage,
  ) async {
    final amount = TextEditingController(
      text: (stage.billedAmount ?? 0).toStringAsFixed(0),
    );
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Billed for ${stage.name}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (stage.contractValue != null)
              Text('System value: ${money(stage.contractValue)}'),
            TextField(
              key: const ValueKey('bill-amount'),
              controller: amount,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Total billed to the client so far (AED)',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('bill-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    final value = double.tryParse(amount.text.trim());
    if (ok != true || value == null || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.billStage(project.id, stage.id, value),
      done: 'Billing saved.',
    )) {
      await onChanged();
    }
  }

  Future<void> _correct(
    BuildContext context,
    WidgetRef ref,
    ProjectStage stage,
  ) async {
    final percent = TextEditingController(
      text: stage.percentComplete.toStringAsFixed(0),
    );
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Correct ${stage.name}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const ValueKey('correct-percent'),
              controller: percent,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: const InputDecoration(
                labelText: '% complete (0–100)',
              ),
            ),
            TextField(
              controller: note,
              decoration: const InputDecoration(labelText: 'Note (optional)'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('correct-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    final value = double.tryParse(percent.text.trim());
    if (ok != true || value == null || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.correctStage(
        project.id,
        stage.id,
        value,
        note: note.text.trim().isEmpty ? null : note.text.trim(),
      ),
      done: 'Progress corrected.',
    )) {
      await onChanged();
    }
  }

  Future<void> _editStages(BuildContext context, WidgetRef ref) async {
    final rows = await Navigator.of(context).push<List<Map<String, Object?>>>(
      MaterialPageRoute(
        builder: (_) => StageEditorScreen(initial: project.stages),
      ),
    );
    if (rows == null || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.replaceStages(project.id, rows),
      done: 'Stages saved.',
    )) {
      await onChanged();
    }
  }

  Future<void> _editSubcontractor(
    BuildContext context,
    WidgetRef ref,
    Subcontractor? existing,
  ) async {
    final body = await showSubcontractorDialog(
      context,
      existing: existing,
      withContract: canEdit,
      systems: project.stages,
    );
    if (body == null || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    final ok = await runAction(
      context,
      () => existing == null
          ? api.addSubcontractor(project.id, body)
          : api.updateSubcontractor(project.id, existing.id, body),
      done: existing == null
          ? 'Subcontractor added.'
          : 'Subcontractor updated.',
    );
    if (ok) await onChanged();
  }

  Future<void> _removeSubcontractor(
    BuildContext context,
    WidgetRef ref,
    Subcontractor c,
  ) async {
    final reason = TextEditingController();
    DateTime date = DateTime.now();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: Text('Remove ${c.name}?'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'They stay in the project history and can be added back.',
              ),
              TextField(
                key: const ValueKey('remove-reason'),
                controller: reason,
                maxLength: 500,
                decoration: const InputDecoration(labelText: 'Reason *'),
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.calendar_today),
                title: Text('Removed on ${ymd(date)}'),
                onTap: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: date,
                    firstDate: DateTime(2000),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                  );
                  if (picked != null) setState(() => date = picked);
                },
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              key: const ValueKey('remove-save'),
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Remove'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    if (reason.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please give a reason for removing them.'),
        ),
      );
      return;
    }
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.removeSubcontractor(
        project.id,
        c.id,
        reason: reason.text.trim(),
        removedOn: ymd(date),
      ),
      done: '${c.name} removed.',
    )) {
      await onChanged();
    }
  }

  Future<void> _editTeam(BuildContext context, WidgetRef ref) async {
    final api = ref.read(projectDeliveryApiProvider);
    final result = await showTeamPicker(context, api, project.team);
    if (result == null || !context.mounted) return;
    if (await runAction(
      context,
      () => api.updateTeam(project.id, result.$1, result.$2, result.$3),
      done: 'Team updated.',
    )) {
      await onChanged();
    }
  }

  Future<void> _addMilestone(BuildContext context, WidgetRef ref) async {
    final name = TextEditingController();
    final at = TextEditingController();
    final share = TextEditingController();
    final amount = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Add payment milestone'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                key: const ValueKey('ms-name'),
                controller: name,
                decoration: const InputDecoration(
                  labelText: 'Name *',
                  hintText: 'Structure complete',
                ),
              ),
              TextField(
                key: const ValueKey('ms-at'),
                controller: at,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'When progress reaches (%) *',
                ),
              ),
              TextField(
                controller: share,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Payment, % of contract',
                ),
              ),
              TextField(
                controller: amount,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Amount (AED)'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('ms-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Add'),
          ),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.addMilestone(project.id, {
        'name': name.text.trim(),
        'trigger_percent': double.tryParse(at.text.trim()),
        'amount_percent': double.tryParse(share.text.trim()),
        'amount': double.tryParse(amount.text.trim()),
      }),
      done: 'Milestone added.',
    )) {
      await onChanged();
    }
  }

  Future<void> _uploadDocument(BuildContext context, WidgetRef ref) async {
    final picked = await _pickFile(const [
      'pdf',
      'jpg',
      'jpeg',
      'png',
      'dwg',
      'dxf',
      'doc',
      'docx',
      'xls',
      'xlsx',
      'zip',
    ]);
    if (picked == null || !context.mounted) return;
    final title = TextEditingController(
      text: picked.filename.replaceAll(RegExp(r'\.[^.]+$'), ''),
    );
    var category = 'drawing';
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: const Text('Upload document'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: title,
                decoration: const InputDecoration(labelText: 'Title *'),
              ),
              DropdownButtonFormField<String>(
                initialValue: category,
                decoration: const InputDecoration(labelText: 'Type'),
                items: [
                  for (final (v, l) in ProjectDocumentFile.categories)
                    DropdownMenuItem(value: v, child: Text(l)),
                ],
                onChanged: (v) => setState(() => category = v ?? category),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Upload'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    final api = ref.read(projectDeliveryApiProvider);
    if (await runAction(
      context,
      () => api.uploadDocument(
        project.id,
        title: title.text.trim(),
        category: category,
        bytes: picked.bytes,
        filename: picked.filename,
      ),
      done: 'Document uploaded.',
    )) {
      await onChanged();
    }
  }
}

Future<PickedDocument?> _pickFile(List<String> extensions) async {
  final files = await FilePicker.pickFiles(
    type: FileType.custom,
    allowedExtensions: extensions,
    dialogTitle: 'Choose a file',
  );
  if (files.isEmpty) return null;
  final file = files.first;
  final name = file.name.trim().isEmpty ? 'document' : file.name.trim();
  return PickedDocument(bytes: await file.readAsBytes(), filename: name);
}

Future<bool> _confirm(BuildContext context, String question) async =>
    await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(question),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Remove'),
          ),
        ],
      ),
    ) ==
    true;

String ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Name, trade, contact, and (for the PM) contract value and dates.
Future<Map<String, Object?>?> showSubcontractorDialog(
  BuildContext context, {
  Subcontractor? existing,
  bool withContract = true,
  List<ProjectStage> systems = const [],
}) async {
  final billed = TextEditingController(
    text: existing?.billedAmount?.toStringAsFixed(0),
  );
  int? systemId = existing?.projectStageId;
  final name = TextEditingController(text: existing?.name);
  final trade = TextEditingController(text: existing?.trade);
  final contact = TextEditingController(text: existing?.contactName);
  final phone = TextEditingController(text: existing?.contactPhone);
  final value = TextEditingController(
    text: existing?.contractValue?.toStringAsFixed(0),
  );
  final start = TextEditingController(text: existing?.startDate);
  final end = TextEditingController(text: existing?.endDate);

  Future<void> pick(BuildContext context, TextEditingController c) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime.tryParse(c.text) ?? DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked != null) c.text = ymd(picked);
  }

  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, setState) => AlertDialog(
        title: Text(
          existing == null ? 'Add subcontractor' : 'Edit subcontractor',
        ),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                key: const ValueKey('sub-name'),
                controller: name,
                decoration: const InputDecoration(labelText: 'Company name *'),
              ),
              TextField(
                key: const ValueKey('sub-trade'),
                controller: trade,
                decoration: const InputDecoration(
                  labelText: 'Trade',
                  hintText: 'HVAC, electrical, plumbing…',
                ),
              ),
              TextField(
                controller: contact,
                decoration: const InputDecoration(labelText: 'Contact person'),
              ),
              TextField(
                controller: phone,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(labelText: 'Phone'),
              ),
              if (withContract) ...[
                if (systems.isNotEmpty)
                  DropdownButtonFormField<int?>(
                    key: const ValueKey('sub-system'),
                    initialValue: systemId,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'System they install',
                    ),
                    items: [
                      const DropdownMenuItem<int?>(
                        value: null,
                        child: Text('—'),
                      ),
                      for (final s in systems)
                        DropdownMenuItem<int?>(
                          value: s.id,
                          child: Text(s.name),
                        ),
                    ],
                    onChanged: (v) => setState(() => systemId = v),
                  ),
                TextField(
                  controller: value,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Contract value (AED)',
                  ),
                ),
                TextField(
                  key: const ValueKey('sub-billed'),
                  controller: billed,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Billed so far (AED)',
                  ),
                ),
                TextField(
                  controller: start,
                  readOnly: true,
                  onTap: () => pick(context, start),
                  decoration: const InputDecoration(labelText: 'Start date'),
                ),
                TextField(
                  controller: end,
                  readOnly: true,
                  onTap: () => pick(context, end),
                  decoration: const InputDecoration(labelText: 'End date'),
                ),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('sub-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    ),
  );
  if (ok != true || name.text.trim().isEmpty) return null;
  String? blank(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();
  return {
    'name': name.text.trim(),
    'trade': blank(trade),
    'contact_name': blank(contact),
    'contact_phone': blank(phone),
    if (withContract) ...{
      'contract_value': double.tryParse(value.text.trim()),
      'billed_amount': double.tryParse(billed.text.trim()) ?? 0,
      'project_stage_id': systemId,
      'start_date': blank(start),
      'end_date': blank(end),
    },
  };
}

/// Tick engineers and supervisors. Returns (engineer ids, supervisor ids).
Future<(List<int>, List<int>, List<int>)?> showTeamPicker(
  BuildContext context,
  ProjectDeliveryApi api,
  List<TeamMember> current,
) async {
  final roles = <int, String>{for (final m in current) m.employeeId: m.role};
  final candidates = await api.teamCandidates().catchError(
    (_) => <TeamCandidate>[],
  );
  if (!context.mounted) return null;

  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, setState) => AlertDialog(
        title: const Text('Project team'),
        content: SizedBox(
          width: double.maxFinite,
          child: candidates.isEmpty
              ? const Text('No site engineers or supervisors found.')
              : ListView(
                  shrinkWrap: true,
                  children: [
                    for (final c in candidates)
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          CheckboxListTile(
                            key: ValueKey('team-${c.id}'),
                            value: roles.containsKey(c.id),
                            title: Text(c.name),
                            subtitle: c.role == null ? null : Text(c.role!),
                            onChanged: (on) => setState(() {
                              if (on == true) {
                                final role = (c.role ?? '').toLowerCase();
                                roles[c.id] = role.contains('coordinator')
                                    ? 'coordinator'
                                    : role.contains('supervisor')
                                    ? 'site_supervisor'
                                    : 'site_engineer';
                              } else {
                                roles.remove(c.id);
                              }
                            }),
                          ),
                          if (roles.containsKey(c.id))
                            Padding(
                              padding: const EdgeInsets.only(
                                left: 56,
                                bottom: 4,
                              ),
                              child: SegmentedButton<String>(
                                segments: const [
                                  ButtonSegment(
                                    value: 'site_engineer',
                                    label: Text('Engineer'),
                                  ),
                                  ButtonSegment(
                                    value: 'site_supervisor',
                                    label: Text('Supervisor'),
                                  ),
                                  ButtonSegment(
                                    value: 'coordinator',
                                    label: Text('Coordinator'),
                                  ),
                                ],
                                selected: {roles[c.id]!},
                                onSelectionChanged: (v) =>
                                    setState(() => roles[c.id] = v.first),
                              ),
                            ),
                        ],
                      ),
                  ],
                ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('team-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    ),
  );
  if (ok != true) return null;
  return (
    [
      for (final e in roles.entries)
        if (e.value == 'site_engineer') e.key,
    ],
    [
      for (final e in roles.entries)
        if (e.value == 'site_supervisor') e.key,
    ],
    [
      for (final e in roles.entries)
        if (e.value == 'coordinator') e.key,
    ],
  );
}

/// Stage names and weights; weights must add up to 100.
class StageEditorScreen extends StatefulWidget {
  const StageEditorScreen({super.key, required this.initial});

  final List<ProjectStage> initial;

  @override
  State<StageEditorScreen> createState() => _StageEditorScreenState();
}

class _StageRow {
  _StageRow(this.id, String name, double weight, double? value)
    : name = TextEditingController(text: name),
      weight = TextEditingController(text: _plain(weight)),
      value = TextEditingController(text: value == null ? '' : _plain(value));

  static String _plain(double v) =>
      v == v.roundToDouble() ? v.toStringAsFixed(0) : '$v';

  final int? id;
  final TextEditingController name;
  final TextEditingController weight;
  final TextEditingController value;

  double? get valueNumber => double.tryParse(value.text.trim());
}

class _StageEditorScreenState extends State<StageEditorScreen> {
  late final List<_StageRow> _rows = widget.initial.isEmpty
      ? [for (final (n, w) in stageTemplate) _StageRow(null, n, w, null)]
      : [
          for (final s in widget.initial)
            _StageRow(s.id, s.name, s.weight, s.contractValue),
        ];

  /// Every system has a value: weights come from the values.
  bool get _byValue => _rows.every((r) => (r.valueNumber ?? 0) > 0);

  double get _valueTotal =>
      _rows.fold(0, (sum, r) => sum + (r.valueNumber ?? 0));

  double get _weightTotal => _rows.fold(
    0,
    (sum, r) => sum + (double.tryParse(r.weight.text.trim()) ?? 0),
  );

  @override
  Widget build(BuildContext context) {
    final byValue = _byValue;
    final valueTotal = _valueTotal;
    final weightTotal = _weightTotal;
    final named = _rows.every((r) => r.name.text.trim().isNotEmpty);
    final valid = named && (byValue || (weightTotal - 100).abs() < 0.01);

    return Scaffold(
      appBar: AppBar(title: const Text('Systems / stages')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text(
            'List the project\'s systems (HVAC, Electrical, Plumbing, Fire '
            'fighting…) or stages. Give each system its contract value and '
            'the weights are worked out for you. Without values, weights '
            'must add up to 100.',
          ),
          const SizedBox(height: 12),
          for (var i = 0; i < _rows.length; i++)
            Padding(
              key: ValueKey('stage-row-$i'),
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    flex: 3,
                    child: TextField(
                      controller: _rows[i].name,
                      onChanged: (_) => setState(() {}),
                      decoration: const InputDecoration(
                        labelText: 'System / stage',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 3,
                    child: TextField(
                      key: ValueKey('stage-value-$i'),
                      controller: _rows[i].value,
                      keyboardType: TextInputType.number,
                      onChanged: (_) => setState(() {}),
                      decoration: const InputDecoration(
                        labelText: 'Value (AED)',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: byValue
                        ? Padding(
                            padding: const EdgeInsets.only(top: 16),
                            child: Text(
                              pct(
                                (_rows[i].valueNumber ?? 0) / valueTotal * 100,
                              ),
                              textAlign: TextAlign.center,
                            ),
                          )
                        : TextField(
                            controller: _rows[i].weight,
                            keyboardType: TextInputType.number,
                            onChanged: (_) => setState(() {}),
                            decoration: const InputDecoration(
                              labelText: 'Weight %',
                            ),
                          ),
                  ),
                  IconButton(
                    tooltip: 'Remove',
                    icon: const Icon(Icons.close),
                    onPressed: _rows.length == 1
                        ? null
                        : () => setState(() => _rows.removeAt(i)),
                  ),
                ],
              ),
            ),
          Row(
            children: [
              TextButton.icon(
                icon: const Icon(Icons.add),
                label: const Text('Add system / stage'),
                onPressed: () =>
                    setState(() => _rows.add(_StageRow(null, '', 0, null))),
              ),
              const Spacer(),
              Text(
                byValue
                    ? 'Project value ${money(valueTotal)}'
                    : 'Total ${pct(weightTotal)}',
                key: const ValueKey('stage-total'),
                style: TextStyle(
                  color: valid ? Colors.green.shade700 : Colors.red.shade700,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const ValueKey('stages-save'),
            onPressed: valid
                ? () => Navigator.pop(context, [
                    for (final r in _rows)
                      {
                        'id': ?r.id,
                        'name': r.name.text.trim(),
                        if (!byValue)
                          'weight': double.tryParse(r.weight.text.trim()) ?? 0,
                        'contract_value': r.valueNumber,
                      },
                  ])
                : null,
            child: const Text('Save'),
          ),
        ],
      ),
    );
  }
}
