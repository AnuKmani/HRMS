import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/device_location.dart';
import '../domain/today_status.dart';
import 'attendance_controller.dart';
import 'selfie_capture_sheet.dart';

/// The front door of Phase 5: where you are, how good the GPS is, and the
/// three things you can do about it.
///
/// Deliberately a *single* screen rather than a dashboard. Everything on it
/// is one of three questions — which site am I at, can the phone see the
/// sky, and what may I press — and every one of those has its answer
/// decided by the server. The advisory geofence, the button enablement and
/// the shift summary are all read straight out of
/// `GET /attendance/today`, so this screen cannot end up believing
/// something the API would refuse.
class AttendanceScreen extends ConsumerStatefulWidget {
  const AttendanceScreen({super.key});

  @override
  ConsumerState<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends ConsumerState<AttendanceScreen> {
  @override
  void initState() {
    super.initState();
    Future.microtask(
      () => ref.read(attendanceControllerProvider.notifier).load(),
    );
  }

  Future<void> _run(Future<void> Function() action) async {
    final controller = ref.read(attendanceControllerProvider.notifier);
    controller.dismissFeedback();
    await action();
    // A snackbar from a state change is easier to read than a banner that
    // competes with the buttons.
    _announce();
  }

  void _announce() {
    final state = ref.read(attendanceControllerProvider);
    final message = state.submitError ?? state.notice;
    if (message == null) return;

    final scheme = Theme.of(context).colorScheme;

    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          backgroundColor: state.submitError == null
              ? scheme.primaryContainer
              : null,
        ),
      );
  }

  Future<void> _checkIn() async {
    final bytes = await SelfieCaptureSheet.show(context);
    if (bytes == null || !mounted) return;

    await _run(
      () => ref.read(attendanceControllerProvider.notifier).checkIn(bytes),
    );
  }

  Future<void> _startVisit() async {
    final purpose = await _askPurpose(context);
    if (purpose == null || !mounted) return;

    await _run(
      () => ref
          .read(attendanceControllerProvider.notifier)
          .startVisit(purpose: purpose.$1, remarks: purpose.$2),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(attendanceControllerProvider);
    final controller = ref.read(attendanceControllerProvider.notifier);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Attendance'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: state.isReady && !state.submitting && !state.syncing
                ? controller.refresh
                : null,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: switch (state.phase) {
        AttendancePhase.loading => const Center(
          key: ValueKey('attendance-loading'),
          child: CircularProgressIndicator(),
        ),
        AttendancePhase.failed => _failed(state, controller),
        AttendancePhase.ready => _ready(state, controller),
      },
    );
  }

  /* ------------------------------------------------------------- states */

  Widget _failed(AttendanceState state, AttendanceController controller) =>
      Center(
        key: const ValueKey('attendance-error'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off, size: 40),
              const SizedBox(height: 16),
              Text(
                state.error ?? 'Could not load today.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: controller.load,
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      );

  Widget _ready(AttendanceState state, AttendanceController controller) {
    final today = state.today!;

    return RefreshIndicator(
      onRefresh: controller.refresh,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _todayCard(state, today),
          const SizedBox(height: 12),
          _locationCard(state, controller),
          const SizedBox(height: 12),
          _siteSection(state, controller),
          const SizedBox(height: 20),
          ..._actions(state, controller),
          if (state.pending.isNotEmpty) ...[
            const SizedBox(height: 20),
            _queueCard(state, controller),
          ],
        ],
      ),
    );
  }

  /* ------------------------------------------------------------- pieces */

  Widget _todayCard(AttendanceState state, TodayStatus today) {
    final attendance = today.attendance;
    final shift = today.shift;
    final theme = Theme.of(context);

    final status =
        attendance?.statusLabel ??
        (today.checkedIn ? 'Checked in' : 'Not started today');

    return Card(
      key: const ValueKey('attendance-today'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(today.date, style: theme.textTheme.titleMedium),
                ),
                Chip(label: Text(status), visualDensity: VisualDensity.compact),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 24,
              runSpacing: 8,
              children: [
                _fact('In', _hhmm(attendance?.checkInAt)),
                _fact('Out', _hhmm(attendance?.checkOutAt)),
                _fact('Worked', _minutes(today.workingMinutes)),
                _fact('Late', _minutes(today.lateMinutes)),
              ],
            ),
            if (shift != null) ...[
              const Divider(height: 24),
              Text(
                'Shift ${shift.startsAt}–${shift.endsAt}'
                '${shift.crossesMidnight ? ' (overnight)' : ''}'
                ' · grace ${shift.graceMinutes} min'
                ' · break ${shift.breakMinutes} min'
                ' · minimum ${_minutes(shift.minimumWorkingMinutes)}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _fact(String label, String value) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(label, style: const TextStyle(fontSize: 12)),
      Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
    ],
  );

  Widget _locationCard(AttendanceState state, AttendanceController controller) {
    final verdict = state.geofence;
    final granted = state.location.status == LocationStatus.granted;
    final theme = Theme.of(context);

    return Card(
      key: const ValueKey('attendance-location'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  switch (state.location.status) {
                    LocationStatus.granted => Icons.gps_fixed,
                    LocationStatus.serviceDisabled => Icons.gps_off,
                    LocationStatus.permanentlyDenied => Icons.block,
                    _ => Icons.gps_not_fixed,
                  },
                  color: granted
                      ? Colors.green.shade700
                      : theme.colorScheme.error,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    state.location.message,
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
            if (!granted) ...[
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton.tonal(
                  onPressed: state.submitting
                      ? null
                      : controller.requestLocation,
                  child: Text(switch (state.location.status) {
                    LocationStatus.serviceDisabled => 'Turn on GPS',
                    LocationStatus.permanentlyDenied => 'Open Settings',
                    _ => 'Allow location',
                  }),
                ),
              ),
            ],
            if (verdict != null) ...[
              const Divider(height: 20),
              Text(
                verdict.message,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: verdict.isWithin
                      ? Colors.green.shade800
                      : theme.colorScheme.error,
                ),
              ),
              Text(
                'Advisory — the server measures again and its answer is the '
                'one that counts.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// The site, always named — a person on a single-site posting should not
  /// have to infer where they are from the button underneath it.
  Widget _siteSection(AttendanceState state, AttendanceController controller) =>
      state.today!.sites.length > 1
      ? _sitePicker(state, controller)
      : _siteLine(state);

  Widget _siteLine(AttendanceState state) {
    final site = state.selectedSite;
    final theme = Theme.of(context);

    final name = site == null
        ? 'None'
        : site.projectName == null
        ? site.name
        : '${site.name} · ${site.projectName}';

    return Padding(
      key: const ValueKey('attendance-site'),
      padding: const EdgeInsets.only(top: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Current site',
            style: theme.textTheme.labelMedium?.copyWith(
              color: theme.colorScheme.outline,
            ),
          ),
          const SizedBox(height: 2),
          Text(name, style: theme.textTheme.titleSmall),
        ],
      ),
    );
  }

  Widget _sitePicker(AttendanceState state, AttendanceController controller) {
    final today = state.today!;

    // Only ever an id that is actually in `items`: a DropdownButtonFormField
    // whose value is not among its own items asserts on the first frame, and
    // `selectedSite` is allowed to fall back to a site from the attendance
    // row that is not in today's assignment list.
    return DropdownButtonFormField<int>(
      initialValue: state.selectedSiteId,
      decoration: const InputDecoration(labelText: 'Current site'),
      items: [
        for (final site in today.sites)
          DropdownMenuItem(
            value: site.id,
            child: Text(
              site.projectName == null
                  ? site.name
                  : '${site.name} · ${site.projectName}',
              overflow: TextOverflow.ellipsis,
            ),
          ),
      ],
      onChanged: state.submitting
          ? null
          : (id) {
              if (id != null) controller.selectSite(id);
            },
    );
  }

  /* ----------------------------------------------------------- actions */

  List<Widget> _actions(
    AttendanceState state,
    AttendanceController controller,
  ) {
    final today = state.today!;
    final site = state.selectedSite;
    final canAct = state.canSubmit;
    final hasLocation = state.location.status == LocationStatus.granted;

    final buttons = <Widget>[];

    if (today.canCheckIn) {
      buttons.add(
        _bigButton(
          valueKey: const ValueKey('attendance-check-in'),
          label: 'CHECK IN',
          icon: Icons.login,
          enabled: canAct && hasLocation && site != null,
          loading: state.submitting,
          onTap: _checkIn,
        ),
      );
    } else if (today.canCheckOut) {
      buttons.add(
        _bigButton(
          valueKey: const ValueKey('attendance-check-out'),
          label: 'CHECK OUT',
          icon: Icons.logout,
          enabled: canAct && hasLocation,
          loading: state.submitting,
          onTap: () => _run(controller.checkOut),
        ),
      );
    }

    buttons.add(
      _bigButton(
        valueKey: const ValueKey('attendance-site-visit'),
        label: state.openVisit == null ? 'START SITE VISIT' : 'END SITE VISIT',
        icon: Icons.explore_outlined,
        enabled: canAct && hasLocation && site != null,
        loading: state.submitting,
        outlined: true,
        onTap: state.openVisit == null
            ? _startVisit
            : () => _run(controller.endVisit),
      ),
    );

    if (today.sites.isEmpty) {
      buttons.add(
        Text(
          'You have no active site assignment, so there is nothing to check '
          'in at. Ask HR to post you to one.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      );
    } else if (!hasLocation) {
      buttons.add(
        Text(
          'Location is needed before any of these can be recorded.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      );
    }

    return [
      for (final button in buttons) ...[button, const SizedBox(height: 12)],
    ];
  }

  Widget _bigButton({
    required String label,
    required IconData icon,
    required bool enabled,
    required VoidCallback onTap,
    Key? valueKey,
    bool loading = false,
    bool outlined = false,
  }) {
    final child = loading
        ? const SizedBox(
            width: 24,
            height: 24,
            child: CircularProgressIndicator(strokeWidth: 3),
          )
        : Text(label);

    final style = FilledButton.styleFrom(
      minimumSize: const Size.fromHeight(64),
    );

    return outlined
        ? OutlinedButton.icon(
            key: valueKey,
            style: style,
            onPressed: enabled && !loading ? onTap : null,
            icon: Icon(icon),
            label: child,
          )
        : FilledButton.icon(
            key: valueKey,
            style: style,
            onPressed: enabled && !loading ? onTap : null,
            icon: Icon(icon),
            label: child,
          );
  }

  Widget _queueCard(AttendanceState state, AttendanceController controller) {
    final count = state.pending.length;
    final theme = Theme.of(context);

    return Card(
      color: theme.colorScheme.secondaryContainer,
      key: const ValueKey('attendance-queue'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '$count event${count == 1 ? '' : 's'} saved on this phone',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 4),
            Text(
              'They were recorded without a connection. Nothing has been sent '
              'yet — sync when you have signal and the server will check '
              'them as if they had arrived on time.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            FilledButton.tonalIcon(
              onPressed: state.syncing || state.submitting
                  ? null
                  : controller.syncPending,
              icon: state.syncing
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 3),
                    )
                  : const Icon(Icons.sync),
              label: const Text('Sync now'),
            ),
          ],
        ),
      ),
    );
  }

  /* ------------------------------------------------------------- prompts */

  static Future<(String, String?)?> _askPurpose(BuildContext context) =>
      showDialog<(String, String?)>(
        context: context,
        builder: (context) => const _PurposeDialog(),
      );
}

/// Why the visit is happening, asked once.
///
/// The two controllers live here rather than in `_askPurpose` because the
/// future returned by `showDialog` resolves when the route is *popped*,
/// which is a moment before the route is gone — the dialog is still on its
/// way out, its fields still attached. Disposing at that point throws
/// "TextEditingController was used after being disposed" from the exit
/// animation. Owned by this State, they are released when the element is,
/// which is the same moment and not a frame early.
class _PurposeDialog extends StatefulWidget {
  const _PurposeDialog();

  @override
  State<_PurposeDialog> createState() => _PurposeDialogState();
}

class _PurposeDialogState extends State<_PurposeDialog> {
  final TextEditingController _purpose = TextEditingController();
  final TextEditingController _remarks = TextEditingController();

  @override
  void dispose() {
    _purpose.dispose();
    _remarks.dispose();
    super.dispose();
  }

  void _start() {
    final text = _purpose.text.trim();
    if (text.isEmpty) return;

    final notes = _remarks.text.trim();

    Navigator.of(context).pop((text, notes.isEmpty ? null : notes));
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Why are you visiting?'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        TextField(
          controller: _purpose,
          autofocus: true,
          maxLength: 150,
          decoration: const InputDecoration(
            labelText: 'Purpose',
            hintText: 'Material delivery, inspection, meeting…',
          ),
        ),
        TextField(
          controller: _remarks,
          maxLength: 500,
          decoration: const InputDecoration(labelText: 'Notes (optional)'),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(onPressed: _start, child: const Text('Start')),
    ],
  );
}

/// `2026-09-27T09:05:00+00:00` → `09:05`, read as the server wrote it.
///
/// Deliberately *not* converted to the device's zone: every schedule in this
/// system — the shift, the grace period, the day the row belongs to — is
/// anchored to the server clock, and rendering one field in a different
/// zone from all the others would show an arrival at 14:35 for a 09:05
/// check-in.
String _hhmm(String? iso) {
  if (iso == null || iso.length < 16) return '—';
  return iso.substring(11, 16);
}

String _minutes(int minutes) {
  if (minutes <= 0) return '—';

  final hours = minutes ~/ 60;
  final rest = minutes % 60;

  if (hours == 0) return '$rest min';
  if (rest == 0) return '$hours h';

  return '$hours h $rest min';
}
