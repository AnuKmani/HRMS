import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/presentation/list_state.dart';
import '../../../core/presentation/paged_list_view.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../auth/auth_controller.dart';
import '../data/attendance_records_repository.dart';
import '../data/my_earnings.dart';
import '../domain/attendance_record.dart';

final attendanceRecordsProvider =
    NotifierProvider<AttendanceRecordsController, ListState<AttendanceRecord>>(
      AttendanceRecordsController.new,
    );

class AttendanceRecordsController
    extends PagedListController<AttendanceRecord> {
  /// HR opens on today — "who is in right now?" is the question HR asks
  /// first. Someone reading only their own history opens on all of it.
  @override
  Map<String, Object?> get initialQuery =>
      ref.read(permissionScopeProvider).canViewEmployees
      ? <String, Object?>{'date': _isoDate(DateTime.now())}
      : const <String, Object?>{};

  @override
  Future<PageResult<AttendanceRecord>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(attendanceRecordsRepositoryProvider)
      .list(page: page, query: query);
}

final siteVisitRecordsProvider =
    NotifierProvider<SiteVisitRecordsController, ListState<SiteVisit>>(
      SiteVisitRecordsController.new,
    );

class SiteVisitRecordsController extends PagedListController<SiteVisit> {
  @override
  Map<String, Object?> get initialQuery => <String, Object?>{
    'date': _isoDate(DateTime.now()),
  };

  @override
  Future<PageResult<SiteVisit>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(attendanceRecordsRepositoryProvider)
      .visits(page: page, query: query);
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
class AttendanceRecordsScreen extends StatelessWidget {
  const AttendanceRecordsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Attendance records'),
          bottom: const TabBar(
            tabs: [
              Tab(
                key: ValueKey('tab-check-ins'),
                icon: Icon(Icons.how_to_reg_outlined),
                text: 'Check-ins',
              ),
              Tab(
                key: ValueKey('tab-site-visits'),
                icon: Icon(Icons.directions_walk),
                text: 'Site visits',
              ),
            ],
          ),
        ),
        body: const TabBarView(children: [_CheckInsTab(), _SiteVisitsTab()]),
      ),
    );
  }
}

class _DateFilter extends StatelessWidget {
  const _DateFilter({required this.date, required this.onChanged});

  final String? date;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: OutlinedButton.icon(
            key: const ValueKey('records-date'),
            icon: const Icon(Icons.calendar_today, size: 18),
            label: Text(date ?? 'All dates'),
            onPressed: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: DateTime.tryParse(date ?? '') ?? DateTime.now(),
                firstDate: DateTime(2020),
                lastDate: DateTime.now(),
              );
              if (picked != null) onChanged(_isoDate(picked));
            },
          ),
        ),
        if (date != null)
          IconButton(
            key: const ValueKey('records-all-dates'),
            tooltip: 'All dates',
            icon: const Icon(Icons.clear),
            onPressed: () => onChanged(null),
          ),
      ],
    );
  }
}

class _CheckInsTab extends ConsumerWidget {
  const _CheckInsTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(attendanceRecordsProvider);
    final controller = ref.read(attendanceRecordsProvider.notifier);
    final date = state.query['date'] as String?;
    final status = state.query['status'] as String? ?? '';

    final ownEmployee = ref.watch(
      authControllerProvider.select((auth) => auth.user?.employee),
    );
    final month = (date ?? _isoDate(DateTime.now())).substring(0, 7);

    return PagedListView<AttendanceRecord>(
      provider: attendanceRecordsProvider,
      emptyMessage: date == null
          ? 'No attendance recorded yet.'
          : 'Nobody has checked in on $date.',
      emptyHint: 'Records appear here as people check in.',
      filter: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (ownEmployee != null) _EarningsBanner(month: month),
          Row(
            children: [
              Expanded(
                child: _DateFilter(
                  date: date,
                  onChanged: (value) => controller.setFilter('date', value),
                ),
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
            if (record.checkInPurpose != null) record.checkInPurpose!,
          ].join('\n'),
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
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
    );
  }
}

class _SiteVisitsTab extends ConsumerWidget {
  const _SiteVisitsTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(siteVisitRecordsProvider);
    final controller = ref.read(siteVisitRecordsProvider.notifier);
    final date = state.query['date'] as String?;

    return PagedListView<SiteVisit>(
      provider: siteVisitRecordsProvider,
      emptyMessage: date == null
          ? 'No site visits recorded yet.'
          : 'No site visits on $date.',
      emptyHint: 'Visits appear here when someone starts one from Attendance.',
      filter: _DateFilter(
        date: date,
        onChanged: (value) => controller.setFilter('date', value),
      ),
      itemBuilder: (context, visit, index) => ListTile(
        key: ValueKey('site-visit-${visit.id}'),
        leading: Icon(
          visit.isOpen ? Icons.directions_walk : Icons.flag_outlined,
          color: visit.isOpen ? Theme.of(context).colorScheme.primary : null,
        ),
        title: Text(_visitor(visit)),
        subtitle: Text(
          [
            visit.siteName ?? 'Site ${visit.siteId}',
            '${_hhmm(visit.startedAt)} – '
                '${visit.isOpen ? 'now' : _hhmm(visit.endedAt)}'
                '${visit.durationMinutes == null ? '' : ' · ${_minutes(visit.durationMinutes!)}'}',
            visit.purpose,
          ].join('\n'),
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        isThreeLine: true,
        trailing: Chip(
          label: Text(visit.isOpen ? 'In progress' : 'Done'),
          visualDensity: VisualDensity.compact,
        ),
        onTap: () => showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          showDragHandle: true,
          builder: (_) => _VisitSheet(visit: visit),
        ),
      ),
    );
  }
}

class _VisitSheet extends StatelessWidget {
  const _VisitSheet({required this.visit});

  final SiteVisit visit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_visitor(visit), style: theme.textTheme.titleLarge),
            Text(
              visit.siteName ?? 'Site ${visit.siteId}',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            _row('Purpose', visit.purpose),
            if (visit.remarks != null && visit.remarks!.isNotEmpty)
              _row('Remarks', visit.remarks!),
            if (visit.projectName != null) _row('Project', visit.projectName!),
            _row('Started', _dateTime(visit.startedAt)),
            _row(
              'Ended',
              visit.isOpen ? 'In progress' : _dateTime(visit.endedAt),
            ),
            if (visit.durationMinutes != null)
              _row('Duration', _minutes(visit.durationMinutes!)),
            if (visit.startLatitude != null && visit.startLongitude != null)
              _row(
                'Start location',
                '${visit.startLatitude!.toStringAsFixed(5)}, '
                    '${visit.startLongitude!.toStringAsFixed(5)}',
              ),
            if (visit.startDistance != null)
              _row('Distance at start', '${visit.startDistance!.round()} m'),
            if (visit.endLatitude != null && visit.endLongitude != null)
              _row(
                'End location',
                '${visit.endLatitude!.toStringAsFixed(5)}, '
                    '${visit.endLongitude!.toStringAsFixed(5)}',
              ),
            if (visit.endDistance != null)
              _row('Distance at end', '${visit.endDistance!.round()} m'),
          ],
        ),
      ),
    );
  }
}

Widget _row(String label, String value) => Padding(
  padding: const EdgeInsets.symmetric(vertical: 4),
  child: Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      SizedBox(width: 140, child: Text(label)),
      Expanded(
        child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
      ),
    ],
  ),
);

/// "This month: 12 days × AED 1,000 = AED 12,000" — the employee's own
/// estimate, from the server, for the month being looked at.
class _EarningsBanner extends ConsumerWidget {
  const _EarningsBanner({required this.month});

  final String month;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final earnings = ref.watch(myEarningsProvider(month)).value;
    if (earnings == null || !earnings.available) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('earnings-banner'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFF22C55E).withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Earned in $month (estimate)',
            style: theme.textTheme.labelLarge,
          ),
          const SizedBox(height: 4),
          Text(
            formatMoney(earnings.currency, earnings.total ?? 0),
            style: theme.textTheme.headlineSmall?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          Text(
            '${earnings.daysWorked} day${earnings.daysWorked == 1 ? '' : 's'} × '
            '${formatMoney(earnings.currency, earnings.dailyRate ?? 0)} a day',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 4),
          Text(
            'Your payslip adds allowances and overtime and takes off leave '
            'and deductions.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _DayPay extends ConsumerWidget {
  const _DayPay({required this.record});

  final AttendanceRecord record;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (record.attendanceDate.length < 7) return const SizedBox.shrink();
    final earnings = ref
        .watch(myEarningsProvider(record.attendanceDate.substring(0, 7)))
        .value;
    final pay = earnings?.payByAttendance[record.id];
    if (earnings == null || pay == null) return const SizedBox.shrink();

    return Container(
      key: const ValueKey('day-pay'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFF22C55E).withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(Icons.payments_outlined, color: Color(0xFF15803D)),
          const SizedBox(width: 10),
          const Expanded(child: Text("This day's pay (estimate)")),
          Text(
            formatMoney(earnings.currency, pay),
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
        ],
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
            if (record.checkInPurpose != null)
              _row('Purpose', record.checkInPurpose!),
            if (record.projectName != null)
              _row('Project', record.projectName!),
            _row('Checked in', _hhmm(record.checkInAt)),
            _row('Checked out', _hhmm(record.checkOutAt)),
            _row('Worked', _minutes(record.workingMinutes)),
            if (ref.watch(
                  authControllerProvider.select(
                    (auth) => auth.user?.employee?.id,
                  ),
                ) ==
                record.employeeId)
              _DayPay(record: record),
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

String _visitor(SiteVisit visit) {
  final name = visit.employeeName;
  if (name == null || name.isEmpty) {
    return visit.employeeId == null
        ? 'Site visit'
        : 'Employee #${visit.employeeId}';
  }
  final code = visit.employeeCode;
  return code == null || code.isEmpty ? name : '$name ($code)';
}

String _dateTime(String? iso) {
  final at = DateTime.tryParse(iso ?? '');
  if (at == null) return '—';
  final local = at.toLocal();
  String two(int value) => value.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)} '
      '${two(local.hour)}:${two(local.minute)}';
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
