import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/leave_request.dart';
import '../domain/leave_type.dart';

/// HR's leave tools: the leave types (days per year, unlimited, paid,
/// certificate) and one page with every employee's leave left and history.
final hrLeaveApiProvider = Provider<HrLeaveApi>(
  (ref) => HrLeaveApi(ref.watch(apiClientProvider)),
);

class HrLeaveApi {
  HrLeaveApi(this._client);

  final ApiClient _client;

  List<Map<String, dynamic>> _items(Object? data) => [
    if (data is Map && data['items'] is List)
      for (final row in data['items'] as List)
        if (row is Map<String, dynamic>) row,
  ];

  Future<List<LeaveType>> types() async =>
      _items((await _client.get('/leave-types', query: {'per_page': 100})).data)
          .map(LeaveType.fromJson)
          .toList();

  Future<void> saveType(int? id, Map<String, Object?> body) => id == null
      ? _client.post('/leave-types', body: body)
      : _client.put('/leave-types/$id', body: body);

  Future<List<EmployeeLeaveRow>> overview({
    required int year,
    String? search,
  }) async => _items(
    (await _client.get(
      '/leave-overview',
      query: {'year': year, 'search': ?search},
    )).data,
  ).map(EmployeeLeaveRow.fromJson).toList();

  Future<void> setBalance(int balanceId, {int? entitlement, int? adjustment}) =>
      _client.put(
        '/leave-balances/$balanceId',
        body: {'entitlement': ?entitlement, 'adjustment': ?adjustment},
      );

  Future<List<LeaveRequest>> requestsOf(int employeeId, int year) async =>
      _items(
        (await _client.get(
          '/leave',
          query: {'employee_id': employeeId, 'year': year, 'per_page': 100},
        )).data,
      ).map(LeaveRequest.fromJson).toList();
}

double _d(Object? v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
int _i(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;
String _days(double v) =>
    v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);

class BalanceRow {
  const BalanceRow({
    required this.id,
    required this.leaveType,
    required this.code,
    required this.entitlement,
    required this.adjustment,
    required this.carryForward,
    required this.used,
    required this.pending,
    required this.remaining,
    required this.unlimited,
  });

  final int id;
  final String leaveType;
  final String code;
  final int entitlement;
  final int adjustment;
  final int carryForward;
  final double used;
  final double pending;
  final double remaining;
  final bool unlimited;

  String get leftLabel => unlimited ? 'Unlimited' : '${_days(remaining)} left';

  factory BalanceRow.fromJson(Map<String, dynamic> j) => BalanceRow(
    id: _i(j['id']),
    leaveType: j['leave_type'] as String? ?? '',
    code: j['code'] as String? ?? '',
    entitlement: _i(j['entitlement']),
    adjustment: _i(j['adjustment']),
    carryForward: _i(j['carry_forward']),
    used: _d(j['used']),
    pending: _d(j['pending']),
    remaining: _d(j['remaining']),
    unlimited: j['unlimited'] == true,
  );
}

class EmployeeLeaveRow {
  const EmployeeLeaveRow({
    required this.employeeId,
    required this.name,
    this.code,
    required this.balances,
    required this.requests,
  });

  final int employeeId;
  final String name;
  final String? code;
  final List<BalanceRow> balances;

  /// Status → number of requests this year.
  final Map<String, int> requests;

  factory EmployeeLeaveRow.fromJson(Map<String, dynamic> j) => EmployeeLeaveRow(
    employeeId: _i(j['employee_id']),
    name: j['name'] as String? ?? '',
    code: j['employee_code'] as String?,
    balances: [
      if (j['balances'] is List)
        for (final b in j['balances'] as List)
          if (b is Map<String, dynamic>) BalanceRow.fromJson(b),
    ],
    requests: {
      if (j['requests'] is Map)
        for (final e in (j['requests'] as Map).entries) '${e.key}': _i(e.value),
    },
  );
}

const _statusLabels = <String, String>{
  'pending': 'Pending',
  'approved': 'Approved',
  'rejected': 'Rejected',
  'cancelled': 'Cancelled',
  'lop': 'Loss of pay',
};

String _message(Object error) => error is ApiException
    ? error.message
    : 'Something went wrong. Please try again.';

/* ------------------------------------------------------------ leave types */

class LeaveTypesAdminScreen extends ConsumerStatefulWidget {
  const LeaveTypesAdminScreen({super.key});

  @override
  ConsumerState<LeaveTypesAdminScreen> createState() =>
      _LeaveTypesAdminScreenState();
}

class _LeaveTypesAdminScreenState extends ConsumerState<LeaveTypesAdminScreen> {
  late Future<List<LeaveType>> _future = ref.read(hrLeaveApiProvider).types();

  void _reload() =>
      setState(() => _future = ref.read(hrLeaveApiProvider).types());

  Future<void> _edit(LeaveType? type) async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => LeaveTypeFormScreen(type: type)),
    );
    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Leave types')),
      floatingActionButton: FloatingActionButton.extended(
        key: const ValueKey('leave-type-add'),
        icon: const Icon(Icons.add),
        label: const Text('Add leave type'),
        onPressed: () => _edit(null),
      ),
      body: FutureBuilder<List<LeaveType>>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            return Center(child: Text(_message(snap.error!)));
          }
          final types = snap.data ?? const [];
          return ListView(
            padding: const EdgeInsets.only(bottom: 96),
            children: [
              const Padding(
                padding: EdgeInsets.all(16),
                child: Text(
                  'Days per year apply to every employee. To give one person '
                  'a different number, use Leave overview.',
                ),
              ),
              for (final t in types)
                ListTile(
                  key: ValueKey('leave-type-${t.id}'),
                  title: Text('${t.name} (${t.code})'),
                  subtitle: Text(t.helpText),
                  trailing: Text(
                    t.isUnlimited
                        ? 'Unlimited'
                        : t.isActive
                        ? '${t.entitlementDays} days'
                        : 'Inactive',
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  onTap: () => _edit(t),
                ),
            ],
          );
        },
      ),
    );
  }
}

class LeaveTypeFormScreen extends ConsumerStatefulWidget {
  const LeaveTypeFormScreen({super.key, this.type});

  final LeaveType? type;

  @override
  ConsumerState<LeaveTypeFormScreen> createState() =>
      _LeaveTypeFormScreenState();
}

class _LeaveTypeFormScreenState extends ConsumerState<LeaveTypeFormScreen> {
  late final _name = TextEditingController(text: widget.type?.name);
  late final _code = TextEditingController(text: widget.type?.code);
  late final _days = TextEditingController(
    text: '${widget.type?.entitlementDays ?? 0}',
  );
  late final _max = TextEditingController(
    text: '${widget.type?.maximumDaysPerRequest ?? 0}',
  );
  late final _deadline = TextEditingController(
    text: '${widget.type?.documentDeadlineDays ?? 1}',
  );
  late bool _unlimited = widget.type?.isUnlimited ?? false;
  late bool _paid = widget.type?.isPaid ?? true;
  late bool _certificate = widget.type?.requiresDocument ?? false;
  late bool _active = widget.type?.isActive ?? true;
  bool _saving = false;
  Map<String, String> _errors = const {};
  String? _banner;

  @override
  void dispose() {
    for (final c in [_name, _code, _days, _max, _deadline]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _errors = const {};
      _banner = null;
    });
    try {
      await ref.read(hrLeaveApiProvider).saveType(widget.type?.id, {
        'name': _name.text.trim(),
        'code': _code.text.trim().toUpperCase(),
        'entitlement_days': _unlimited
            ? 0
            : int.tryParse(_days.text.trim()) ?? 0,
        'is_unlimited': _unlimited,
        'maximum_days_per_request': int.tryParse(_max.text.trim()) ?? 0,
        'is_paid': _paid,
        'requires_document': _certificate,
        'document_deadline_days': int.tryParse(_deadline.text.trim()) ?? 1,
        'status': _active ? 'active' : 'inactive',
      });
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _errors = failure.errors;
        _banner = failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.type == null ? 'New leave type' : 'Edit leave type'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                _banner!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          TextField(
            key: const ValueKey('lt-name'),
            controller: _name,
            decoration: InputDecoration(
              labelText: 'Name *',
              hintText: 'Sick Leave',
              errorText: _errors['name'],
            ),
          ),
          TextField(
            key: const ValueKey('lt-code'),
            controller: _code,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(
              labelText: 'Short code *',
              hintText: 'SL',
              errorText: _errors['code'],
            ),
          ),
          SwitchListTile(
            key: const ValueKey('lt-unlimited'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Unlimited'),
            subtitle: const Text('No yearly limit — never refused for days'),
            value: _unlimited,
            onChanged: (v) => setState(() => _unlimited = v),
          ),
          if (!_unlimited)
            TextField(
              key: const ValueKey('lt-days'),
              controller: _days,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: 'Days per year',
                errorText: _errors['entitlement_days'],
              ),
            ),
          TextField(
            controller: _max,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: 'Most days in one request (0 = no limit)',
              errorText: _errors['maximum_days_per_request'],
            ),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Paid leave'),
            value: _paid,
            onChanged: (v) => setState(() => _paid = v),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Needs a certificate'),
            subtitle: const Text(
              'Without it the leave becomes loss of pay (salary cut)',
            ),
            value: _certificate,
            onChanged: (v) => setState(() => _certificate = v),
          ),
          if (_certificate)
            TextField(
              controller: _deadline,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Days after the leave to upload it',
              ),
            ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Active'),
            subtitle: const Text('Employees can choose it'),
            value: _active,
            onChanged: (v) => setState(() => _active = v),
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const ValueKey('lt-save'),
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save'),
          ),
        ],
      ),
    );
  }
}

/* -------------------------------------------------------- leave overview */

class LeaveOverviewScreen extends ConsumerStatefulWidget {
  const LeaveOverviewScreen({super.key});

  @override
  ConsumerState<LeaveOverviewScreen> createState() =>
      _LeaveOverviewScreenState();
}

class _LeaveOverviewScreenState extends ConsumerState<LeaveOverviewScreen> {
  int _year = DateTime.now().year;
  String _search = '';
  late Future<List<EmployeeLeaveRow>> _future = _fetch();

  Future<List<EmployeeLeaveRow>> _fetch() => ref
      .read(hrLeaveApiProvider)
      .overview(year: _year, search: _search.isEmpty ? null : _search);

  void _reload() => setState(() => _future = _fetch());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Leave overview'),
        actions: [
          IconButton(
            tooltip: 'Previous year',
            icon: const Icon(Icons.chevron_left),
            onPressed: () {
              _year--;
              _reload();
            },
          ),
          Center(child: Text('$_year')),
          IconButton(
            tooltip: 'Next year',
            icon: const Icon(Icons.chevron_right),
            onPressed: () {
              _year++;
              _reload();
            },
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
            child: TextField(
              key: const ValueKey('overview-search'),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'Search employee name or code',
              ),
              onSubmitted: (v) {
                _search = v.trim();
                _reload();
              },
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                _reload();
                await _future.catchError((_) => <EmployeeLeaveRow>[]);
              },
              child: FutureBuilder<List<EmployeeLeaveRow>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snap.hasError) {
                    return ListView(
                      children: [
                        Padding(
                          padding: const EdgeInsets.all(32),
                          child: Text(_message(snap.error!)),
                        ),
                      ],
                    );
                  }
                  final rows = snap.data ?? const [];
                  if (rows.isEmpty) {
                    return ListView(
                      children: const [
                        Padding(
                          padding: EdgeInsets.all(32),
                          child: Text('No employees found.'),
                        ),
                      ],
                    );
                  }
                  return ListView.separated(
                    itemCount: rows.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (context, i) {
                      final r = rows[i];
                      final counts = [
                        for (final e in _statusLabels.entries)
                          if ((r.requests[e.key] ?? 0) > 0)
                            '${r.requests[e.key]} ${e.value.toLowerCase()}',
                      ];
                      return ListTile(
                        key: ValueKey('overview-${r.employeeId}'),
                        title: Text(
                          r.code == null ? r.name : '${r.name} · ${r.code}',
                        ),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const SizedBox(height: 4),
                            Wrap(
                              spacing: 6,
                              runSpacing: 4,
                              children: [
                                for (final b in r.balances)
                                  Chip(
                                    visualDensity: VisualDensity.compact,
                                    label: Text('${b.code}: ${b.leftLabel}'),
                                  ),
                              ],
                            ),
                            if (counts.isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(counts.join(' · ')),
                              ),
                          ],
                        ),
                        onTap: () async {
                          await Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) =>
                                  EmployeeLeaveScreen(row: r, year: _year),
                            ),
                          );
                          if (mounted) _reload();
                        },
                      );
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One employee: what they have of each leave type (HR can change it), and
/// every request this year with its status.
class EmployeeLeaveScreen extends ConsumerStatefulWidget {
  const EmployeeLeaveScreen({super.key, required this.row, required this.year});

  final EmployeeLeaveRow row;
  final int year;

  @override
  ConsumerState<EmployeeLeaveScreen> createState() =>
      _EmployeeLeaveScreenState();
}

class _EmployeeLeaveScreenState extends ConsumerState<EmployeeLeaveScreen> {
  late EmployeeLeaveRow _row = widget.row;
  late Future<List<LeaveRequest>> _requests = _fetchRequests();

  Future<List<LeaveRequest>> _fetchRequests() => ref
      .read(hrLeaveApiProvider)
      .requestsOf(widget.row.employeeId, widget.year);

  Future<void> _refresh() async {
    final rows = await ref
        .read(hrLeaveApiProvider)
        .overview(
          year: widget.year,
          search: widget.row.code ?? widget.row.name,
        );
    final mine = rows.where((r) => r.employeeId == widget.row.employeeId);
    if (!mounted) return;
    setState(() {
      if (mine.isNotEmpty) _row = mine.first;
      _requests = _fetchRequests();
    });
  }

  Future<void> _editBalance(BalanceRow b) async {
    final days = TextEditingController(text: '${b.entitlement}');
    final extra = TextEditingController(text: '${b.adjustment}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('${b.leaveType} — ${_row.name}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const ValueKey('bal-days'),
              controller: days,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Days for this year',
              ),
            ),
            TextField(
              key: const ValueKey('bal-extra'),
              controller: extra,
              keyboardType: const TextInputType.numberWithOptions(signed: true),
              decoration: const InputDecoration(
                labelText: 'Extra days (+) or deduction (−)',
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
            key: const ValueKey('bal-save'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref
          .read(hrLeaveApiProvider)
          .setBalance(
            b.id,
            entitlement: int.tryParse(days.text.trim()),
            adjustment: int.tryParse(extra.text.trim()),
          );
      messenger.showSnackBar(
        const SnackBar(content: Text('Leave days saved.')),
      );
      await _refresh();
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(_message(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(_row.name)),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              'Leave left in ${widget.year}',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            Card(
              child: Column(
                children: [
                  for (final b in _row.balances)
                    ListTile(
                      key: ValueKey('emp-balance-${b.id}'),
                      title: Text(b.leaveType),
                      subtitle: Text(
                        b.unlimited
                            ? '${_days(b.used)} used · ${_days(b.pending)} pending · no limit'
                            : '${b.entitlement + b.carryForward + b.adjustment} given · '
                                  '${_days(b.used)} used · ${_days(b.pending)} pending',
                      ),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            b.leftLabel,
                            style: const TextStyle(fontWeight: FontWeight.w600),
                          ),
                          if (!b.unlimited)
                            const Icon(Icons.edit_outlined, size: 18),
                        ],
                      ),
                      onTap: b.unlimited ? null : () => _editBalance(b),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Text('Leave requests', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            FutureBuilder<List<LeaveRequest>>(
              future: _requests,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.all(16),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                final list = (snap.data ?? const <LeaveRequest>[])
                    .where((l) => l.status != 'draft')
                    .toList();
                if (list.isEmpty) {
                  return const Text('No leave requests this year.');
                }
                return Card(
                  child: Column(
                    children: [
                      for (final l in list)
                        ListTile(
                          key: ValueKey('emp-leave-${l.id}'),
                          title: Text(l.leaveTypeName ?? 'Leave'),
                          subtitle: Text(
                            '${l.startDate} → ${l.endDate} · '
                            '${_days(l.requestedDays)} day(s)',
                          ),
                          trailing: Text(
                            l.statusLabel,
                            style: TextStyle(
                              fontWeight: FontWeight.w600,
                              color: switch (l.status) {
                                'approved' => Colors.green.shade700,
                                'rejected' || 'lop' => Colors.red.shade700,
                                'pending' => Colors.orange.shade800,
                                _ => Colors.grey.shade700,
                              },
                            ),
                          ),
                          onTap: () async {
                            await context.push('/leave/${l.id}');
                            if (mounted) await _refresh();
                          },
                        ),
                    ],
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}
