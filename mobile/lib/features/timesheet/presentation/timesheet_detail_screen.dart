import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_timesheets_repository.dart';
import '../domain/timesheet.dart';
import '../domain/timesheets_repository.dart';

/// One derived day: where the minutes came from, and what they add up to.
///
/// Deliberately no actions. A timesheet has no write endpoint and no
/// approval state — it is a *snapshot* of attendance at the moment the
/// period was generated — and a screen that offered "edit" or "approve" on
/// it would be describing a workflow that does not exist.
class TimesheetDetailScreen extends ConsumerStatefulWidget {
  const TimesheetDetailScreen({super.key, required this.timesheetId});

  final int timesheetId;

  @override
  ConsumerState<TimesheetDetailScreen> createState() =>
      _TimesheetDetailScreenState();
}

class _TimesheetDetailScreenState extends ConsumerState<TimesheetDetailScreen> {
  Timesheet? _timesheet;
  String? _error;
  bool _loading = true;

  TimesheetsRepository get _repository =>
      ref.read(timesheetsRepositoryProvider);

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
      final timesheet = await _repository.find(widget.timesheetId);
      if (!mounted) return;
      setState(() {
        _timesheet = timesheet;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Timesheet #${widget.timesheetId}')),
      body: _body(),
    );
  }

  Widget _body() {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('timesheet-loading')),
      );
    }

    if (_error != null) {
      return Center(
        key: const ValueKey('timesheet-error'),
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

    final timesheet = _timesheet!;
    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
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
                          timesheet.dateLabel,
                          style: theme.textTheme.titleLarge,
                        ),
                      ),
                      StatusChip(
                        label: timesheet.statusLabel,
                        tone: timesheet.isComplete
                            ? StatusTone.positive
                            : timesheet.isIncomplete
                            ? StatusTone.warning
                            : StatusTone.neutral,
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  _line(theme, 'Person', timesheet.employeeName ?? 'You'),
                  _line(theme, 'Site', timesheet.siteName),
                  _line(theme, 'Project', timesheet.projectName),
                  _line(
                    theme,
                    'Attendance',
                    timesheet.attendanceId == null
                        ? 'No matching attendance row'
                        : '#${timesheet.attendanceId}',
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Working time', style: theme.textTheme.titleMedium),
                  const SizedBox(height: 12),
                  _stat(
                    theme,
                    'Worked',
                    '${timesheet.workingHours.toStringAsFixed(2)} h',
                    '${timesheet.workingMinutes} min',
                  ),
                  _stat(theme, 'Break', '', '${timesheet.breakMinutes} min'),
                  _stat(
                    theme,
                    'Extra',
                    '${timesheet.overtimeHours.toStringAsFixed(2)} h',
                    '${timesheet.overtimeMinutes} min',
                    highlight: timesheet.overtimeMinutes > 0,
                  ),
                ],
              ),
            ),
          ),
          if (timesheet.checkInAt != null || timesheet.checkOutAt != null) ...[
            const SizedBox(height: 16),
            Card(
              margin: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('From attendance', style: theme.textTheme.titleMedium),
                    const SizedBox(height: 8),
                    _line(theme, 'In', _at(timesheet.checkInAt)),
                    _line(theme, 'Out', _at(timesheet.checkOutAt)),
                    if (timesheet.notes != null &&
                        timesheet.notes!.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(timesheet.notes!, style: theme.textTheme.bodySmall),
                    ],
                  ],
                ),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Text(
            'A timesheet is derived from attendance when the period is '
            'generated — it is what was true then, not a record anybody can '
            'edit.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  static String _at(String? iso) {
    if (iso == null || iso.length < 16) return '—';
    return '${iso.substring(0, 10)} ${iso.substring(11, 16)}';
  }

  Widget _line(ThemeData theme, String label, String? value) {
    if (value == null || value.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }

  Widget _stat(
    ThemeData theme,
    String label,
    String hours,
    String minutes, {
    bool highlight = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodyMedium)),
          if (hours.isNotEmpty)
            Text(
              hours,
              style: theme.textTheme.titleSmall?.copyWith(
                color: highlight ? theme.colorScheme.primary : null,
              ),
            ),
          const SizedBox(width: 12),
          SizedBox(
            width: 72,
            child: Text(
              minutes,
              textAlign: TextAlign.end,
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
