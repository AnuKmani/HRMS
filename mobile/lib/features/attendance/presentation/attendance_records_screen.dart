import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/presentation/list_state.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../data/attendance_records_repository.dart';
import '../domain/attendance_record.dart';

final attendanceRecordsProvider =
    NotifierProvider<AttendanceRecordsController, ListState<AttendanceRecord>>(
      AttendanceRecordsController.new,
    );

class AttendanceRecordsController
    extends PagedListController<AttendanceRecord> {
  /// Opens on today: "who is in right now?" is the question HR asks first.
  @override
  Map<String, Object?> get initialQuery => <String, Object?>{
    'date': _isoDate(DateTime.now()),
  };

  @override
  Future<PageResult<AttendanceRecord>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(attendanceRecordsRepositoryProvider)
      .list(page: page, query: query);
}

final _selfieProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(attendanceRecordsRepositoryProvider).selfie(id),
);

/// Attendance as recorded: who checked in, where, when, and the selfie.
///
/// One screen for two readers. HR (and anyone the server lets read other
/// people's rows) sees the whole workforce; an employee sees their own
/// history. The difference is entirely the server's row scope — this screen
/// asks the same question either way.
class AttendanceRecordsScreen extends ConsumerWidget {
  const AttendanceRecordsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(attendanceRecordsProvider);
    final controller = ref.read(attendanceRecordsProvider.notifier);
    final date = state.query['date'] as String?;
    final status = state.query['status'] as String? ?? '';

    return Scaffold(
      appBar: AppBar(title: const Text('Attendance records')),
      body: PagedListView<AttendanceRecord>(
        provider: attendanceRecordsProvider,
        emptyMessage: date == null
            ? 'No attendance recorded yet.'
            : 'Nobody has checked in on $date.',
        emptyHint: 'Records appear here as people check in.',
        filter: Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                key: const ValueKey('records-date'),
                icon: const Icon(Icons.calendar_today, size: 18),
                label: Text(date ?? 'All dates'),
                onPressed: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate:
                        DateTime.tryParse(date ?? '') ?? DateTime.now(),
                    firstDate: DateTime(2020),
                    lastDate: DateTime.now(),
                  );
                  if (picked != null) {
                    controller.setFilter('date', _isoDate(picked));
                  }
                },
              ),
            ),
            if (date != null)
              IconButton(
                key: const ValueKey('records-all-dates'),
                tooltip: 'All dates',
                icon: const Icon(Icons.clear),
                onPressed: () => controller.setFilter('date', null),
              ),
            const SizedBox(width: 8),
            DropdownButton<String>(
              key: const ValueKey('records-status'),
              value: status,
              items: const [
                DropdownMenuItem(value: '', child: Text('All')),
                DropdownMenuItem(value: 'present', child: Text('Present')),
                DropdownMenuItem(value: 'late', child: Text('Late')),
                DropdownMenuItem(
                  value: 'incomplete',
                  child: Text('Left early'),
                ),
                DropdownMenuItem(
                  value: 'missing_checkout',
                  child: Text('No check-out'),
                ),
              ],
              onChanged: (value) => controller.setFilter('status', value),
            ),
          ],
        ),
        itemBuilder: (context, record, index) => ListTile(
          key: ValueKey('attendance-record-${record.id}'),
          leading: Icon(
            record.isOpen ? Icons.login : Icons.check_circle_outline,
            color: record.isOpen ? Theme.of(context).colorScheme.primary : null,
          ),
          title: Text(_who(record)),
          subtitle: Text(
            [
              '${record.attendanceDate} · ${record.siteName ?? 'Site ${record.siteId}'}',
              'In ${_hhmm(record.checkInAt)} · Out ${_hhmm(record.checkOutAt)}',
            ].join('\n'),
          ),
          isThreeLine: true,
          trailing: Chip(
            label: Text(record.statusLabel),
            visualDensity: VisualDensity.compact,
          ),
          onTap: () => showModalBottomSheet<void>(
            context: context,
            isScrollControlled: true,
            showDragHandle: true,
            builder: (_) => _RecordSheet(record: record),
          ),
        ),
      ),
    );
  }
}

class _RecordSheet extends ConsumerWidget {
  const _RecordSheet({required this.record});

  final AttendanceRecord record;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_who(record), style: theme.textTheme.titleLarge),
            Text(
              '${record.attendanceDate} · ${record.statusLabel}',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            if (record.hasSelfie)
              Center(child: _Selfie(id: record.id))
            else
              const Text('No selfie on this record.'),
            const SizedBox(height: 16),
            _row('Site', record.siteName ?? 'Site ${record.siteId}'),
            if (record.projectName != null)
              _row('Project', record.projectName!),
            _row('Checked in', _hhmm(record.checkInAt)),
            _row('Checked out', _hhmm(record.checkOutAt)),
            _row('Worked', _minutes(record.workingMinutes)),
            if (record.lateMinutes > 0)
              _row('Late by', _minutes(record.lateMinutes)),
            if (record.checkInLatitude != null &&
                record.checkInLongitude != null)
              _row(
                'Check-in location',
                '${record.checkInLatitude!.toStringAsFixed(5)}, '
                    '${record.checkInLongitude!.toStringAsFixed(5)}',
              ),
            if (record.checkInDistance != null)
              _row(
                'Distance from site',
                '${record.checkInDistance!.round()} m',
              ),
            if (record.checkOutLatitude != null &&
                record.checkOutLongitude != null)
              _row(
                'Check-out location',
                '${record.checkOutLatitude!.toStringAsFixed(5)}, '
                    '${record.checkOutLongitude!.toStringAsFixed(5)}',
              ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 140, child: Text(label)),
        Expanded(
          child: Text(
            value,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ],
    ),
  );
}

class _Selfie extends ConsumerWidget {
  const _Selfie({required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return switch (ref.watch(_selfieProvider(id))) {
      AsyncData(:final value) => ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.memory(
          value,
          key: const ValueKey('attendance-selfie'),
          height: 240,
          fit: BoxFit.cover,
        ),
      ),
      AsyncError(:final error) => Text(
        error is ApiException ? error.message : 'Could not load the selfie.',
      ),
      _ => const SizedBox(
        height: 240,
        child: Center(child: CircularProgressIndicator()),
      ),
    };
  }
}

String _who(AttendanceRecord record) {
  final name = record.employeeName;
  if (name == null || name.isEmpty) return 'Employee #${record.employeeId}';
  final code = record.employeeCode;
  return code == null || code.isEmpty ? name : '$name ($code)';
}

String _isoDate(DateTime day) =>
    '${day.year}-${day.month.toString().padLeft(2, '0')}-'
    '${day.day.toString().padLeft(2, '0')}';

String _hhmm(String? iso) {
  final at = DateTime.tryParse(iso ?? '');
  if (at == null) return '—';
  final local = at.toLocal();
  return '${local.hour.toString().padLeft(2, '0')}:'
      '${local.minute.toString().padLeft(2, '0')}';
}

String _minutes(int minutes) {
  if (minutes <= 0) return '—';
  final hours = minutes ~/ 60;
  final rest = minutes % 60;
  return hours == 0
      ? '${rest}m'
      : '${hours}h ${rest.toString().padLeft(2, '0')}m';
}
