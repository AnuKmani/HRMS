import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_timesheets_repository.dart';
import '../domain/timesheet.dart';
import 'timesheets_controller.dart';

/// Derived working-time rows, for yourself or for the people you run.
///
/// The list is read-only and says so by having no floating action button
/// except *Generate* — which is not an edit either. Generating asks the
/// server to re-derive a period from attendance; it never writes a minute
/// anybody worked, and running it twice over the same days refreshes the
/// snapshots rather than making a second copy of them.
///
/// Scope is not something this screen decides. `GET /timesheets` returns
/// your own rows unless the server's visibility list says otherwise, so two
/// people pressing the same filter see different numbers — and neither is
/// wrong.
class TimesheetsListScreen extends ConsumerStatefulWidget {
  const TimesheetsListScreen({super.key});

  @override
  ConsumerState<TimesheetsListScreen> createState() =>
      _TimesheetsListScreenState();
}

class _TimesheetsListScreenState extends ConsumerState<TimesheetsListScreen> {
  late final TextEditingController _from;
  late final TextEditingController _to;

  bool _generating = false;
  String? _banner;

  @override
  void initState() {
    super.initState();
    _from = TextEditingController();
    _to = TextEditingController();
  }

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    super.dispose();
  }

  Future<void> _generate() async {
    if (_generating) return;

    setState(() {
      _generating = true;
      _banner = null;
    });

    try {
      await ref
          .read(timesheetsRepositoryProvider)
          .generate(from: _from.text.trim(), to: _to.text.trim());
      await ref.read(timesheetsListProvider.notifier).reload();
      if (!mounted) return;
      setState(() => _generating = false);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _generating = false;
        _banner = failure is ApiException
            ? failure.message
            : 'The timesheets could not be generated. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final canView = ref.watch(
      permissionScopeProvider.select((scope) => scope.canViewTimesheets),
    );

    // Before anything else: without `timesheets.view` the endpoint answers
    // 403, and building the list controller anyway would fire a request
    // whose only possible outcomes are that 403 and a list of rows this
    // session is not allowed to have.
    if (!canView) {
      return Scaffold(
        appBar: AppBar(title: const Text('Timesheets')),
        body: const NoPermission(module: 'timesheets'),
      );
    }

    final canGenerate = ref.watch(
      permissionScopeProvider.select((scope) => scope.canGenerateTimesheets),
    );

    return Scaffold(
      appBar: AppBar(
        title: const Text('Timesheets'),
        actions: [
          if (canGenerate)
            IconButton(
              key: const ValueKey('generate-timesheets'),
              tooltip: 'Generate from attendance',
              icon: const Icon(Icons.refresh),
              onPressed: _generating ? null : _generate,
            ),
        ],
      ),
      body: Column(
        children: [
          if (_banner != null)
            Material(
              color: Theme.of(context).colorScheme.errorContainer,
              child: SizedBox(
                width: double.infinity,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    _banner!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.onErrorContainer,
                    ),
                  ),
                ),
              ),
            ),
          Expanded(child: _list(canGenerate)),
        ],
      ),
    );
  }

  Widget _list(bool canGenerate) {
    return PagedListView<Timesheet>(
      provider: timesheetsListProvider,
      emptyMessage: 'No timesheets for this period.',
      emptyHint: canGenerate
          ? 'Tap the refresh icon to derive them from attendance.'
          : 'Timesheets appear once the period has been generated.',
      filter: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: DateField(
                  key: const ValueKey('timesheet-from'),
                  label: 'From',
                  controller: _from,
                  allowEmpty: true,
                  onChanged: (value) => ref
                      .read(timesheetsListProvider.notifier)
                      .setFilter('from', value),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DateField(
                  key: const ValueKey('timesheet-to'),
                  label: 'To',
                  controller: _to,
                  allowEmpty: true,
                  onChanged: (value) => ref
                      .read(timesheetsListProvider.notifier)
                      .setFilter('to', value),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _StatusFilter(
            value: ref.watch(
              timesheetsListProvider.select(
                (s) => s.query['status'] as String?,
              ),
            ),
            onSelected: (status) => ref
                .read(timesheetsListProvider.notifier)
                .setFilter('status', status),
          ),
        ],
      ),
      itemBuilder: (context, timesheet, index) => ListTile(
        key: ValueKey('timesheet-row-${timesheet.id}'),
        title: Text(timesheet.dateLabel),
        subtitle: Text(
          [
            timesheet.employeeName ?? 'You',
            if (timesheet.siteName != null) timesheet.siteName!,
            timesheet.summary,
          ].join(' · '),
        ),
        trailing: StatusChip(
          label: timesheet.statusLabel,
          tone: timesheet.isComplete
              ? StatusTone.positive
              : timesheet.isIncomplete
              ? StatusTone.warning
              : StatusTone.neutral,
        ),
        onTap: () => context.push('/timesheets/${timesheet.id}'),
      ),
    );
  }
}

class _StatusFilter extends StatelessWidget {
  const _StatusFilter({required this.value, required this.onSelected});

  final String? value;

  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Day status', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        InputDecorator(
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          ),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              key: const ValueKey('timesheet-status-filter'),
              isExpanded: true,
              value: value ?? '',
              items: const [
                DropdownMenuItem<String>(value: '', child: Text('All')),
                DropdownMenuItem<String>(
                  value: Timesheet.statusComplete,
                  child: Text('Complete'),
                ),
                DropdownMenuItem<String>(
                  value: Timesheet.statusIncomplete,
                  child: Text('Incomplete'),
                ),
                DropdownMenuItem<String>(
                  value: Timesheet.statusOpen,
                  child: Text('Open'),
                ),
              ],
              onChanged: onSelected,
            ),
          ),
        ),
      ],
    );
  }
}
