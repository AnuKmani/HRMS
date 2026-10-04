import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/device_location.dart';
import '../domain/assigned_site.dart';
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
  const AttendanceScreen({super.key, this.startAction});

  /// `check-in` or `check-out` when opened from the dashboard's one-tap
  /// button: the action starts by itself as soon as it is allowed — GPS
  /// fixed, a site chosen, the server saying today permits it. It goes
  /// through exactly the same path as pressing the button here, so no
  /// geofence, selfie or shift rule is skipped; if the moment never comes
  /// (no site, no GPS) nothing happens and the screen explains why.
  final String? startAction;

  static const checkIn = 'check-in';
  static const checkOut = 'check-out';

  /// Start a site visit — or, with one already open, just show it so the
  /// person can end it.
  static const visit = 'visit';

  @override
  ConsumerState<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends ConsumerState<AttendanceScreen> {
  bool _autoStarted = false;

  @override
  void initState() {
    super.initState();
    Future.microtask(
      () => ref.read(attendanceControllerProvider.notifier).load(),
    );

    if (widget.startAction != null) {
      ref.listenManual<AttendanceState>(
        attendanceControllerProvider,
        (_, state) => _maybeAutoStart(state),
      );
    }
  }

  void _maybeAutoStart(AttendanceState state) {
    if (_autoStarted || !mounted || !state.canSubmit) return;

    final today = state.today;
    final located = state.location.status == LocationStatus.granted;
    if (today == null || !located) return;

    switch (widget.startAction) {
      case AttendanceScreen.checkIn
          when today.canCheckIn &&
              (!today.requiresSite || state.selectedSite != null):
        _autoStarted = true;
        // After this frame: the selfie sheet needs a settled route to
        // present over.
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _checkIn();
        });
      case AttendanceScreen.visit
          when state.openVisit == null && today.canStartSiteVisit:
        _autoStarted = true;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _startVisit();
        });
      case AttendanceScreen.checkOut when today.canCheckOut:
        _autoStarted = true;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) {
            _run(ref.read(attendanceControllerProvider.notifier).checkOut);
          }
        });
    }
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
    final before = ref.read(attendanceControllerProvider);
    final requiresSite = before.today?.requiresSite ?? false;
    final site = requiresSite ? before.selectedSite : null;
    if (requiresSite && site == null) return;

    // Attendance is a selfie and the phone's location, wherever the person
    // is — office, site, anywhere. The dialog shows the GPS reading that
    // will be recorded (or the site, where a deployment requires one).
    final fix = before.fix;
    final where = site != null
        ? (site.projectName == null
              ? site.name
              : '${site.name} · ${site.projectName}')
        : fix == null
        ? 'Your current location (GPS)'
        : 'Your current location\n'
              '${fix.latitude.toStringAsFixed(5)}, '
              '${fix.longitude.toStringAsFixed(5)} · ±${fix.accuracy.round()} m';

    // Where and why first, then the photograph: the selfie is the last
    // step so the moment it captures is the moment of check-in.
    final purpose = await showDialog<String>(
      context: context,
      builder: (context) => _CheckInPurposeDialog(siteName: where),
    );
    if (purpose == null || !mounted) return;

    final bytes = await SelfieCaptureSheet.show(context);
    if (bytes == null || !mounted) return;

    await _run(
      () => ref
          .read(attendanceControllerProvider.notifier)
          .checkIn(bytes, purpose: purpose),
    );

    // Only for a check-in the server accepted — not a refusal, and not one
    // queued offline, which has not happened yet.
    final after = ref.read(attendanceControllerProvider);
    if (!mounted ||
        after.submitError != null ||
        after.notice != 'Checked in.') {
      return;
    }
    final at = DateTime.tryParse(after.today?.attendance?.checkInAt ?? '')
        ?.toLocal();
    final time = at == null
        ? 'now'
        : '${at.hour.toString().padLeft(2, '0')}:'
              '${at.minute.toString().padLeft(2, '0')}';

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        key: const ValueKey('checked-in-dialog'),
        icon: const Icon(Icons.check_circle, color: Colors.green, size: 52),
        title: const Text('You are checked in'),
        content: Text(
          'Checked in at $time'
          '${site == null ? '' : ' at ${site.name}'}. '
          'Your selfie and location are saved.\n\n'
          'Remember to tap CHECK OUT in the evening before you leave.'
          '${after.today?.canStartSiteVisit == true ? ' If you go to a site during the day, use SITE VISIT.' : ''}',
          textAlign: TextAlign.center,
        ),
        actions: [
          FilledButton(
            key: const ValueKey('checked-in-ok'),
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  Future<void> _startVisit() async {
    final state = ref.read(attendanceControllerProvider);
    final today = state.today;
    if (today == null) return;

    final sites = today.visitSites.isNotEmpty ? today.visitSites : today.sites;
    final answer = await showDialog<_VisitAnswer>(
      context: context,
      builder: (context) =>
          _PurposeDialog(sites: sites, initialSiteId: state.selectedSiteId),
    );
    if (answer == null || !mounted) return;

    await _run(
      () => ref
          .read(attendanceControllerProvider.notifier)
          .startVisit(
            purpose: answer.purpose,
            remarks: answer.remarks,
            siteId: answer.siteId,
          ),
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
          if (today.requiresSite) ...[
            _siteSection(state, controller),
            const SizedBox(height: 20),
          ] else
            const SizedBox(height: 8),
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
            'Work location',
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
      decoration: const InputDecoration(labelText: 'Work location'),
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
    final canAct = state.canSubmit;
    final hasLocation = state.location.status == LocationStatus.granted;
    final siteReady = !today.requiresSite || state.selectedSite != null;

    final buttons = <Widget>[];

    // Everyone: check in and check out with a selfie and the phone's
    // location. No site is needed.
    if (today.canCheckIn) {
      buttons.add(
        _bigButton(
          valueKey: const ValueKey('attendance-check-in'),
          label: 'CHECK IN (SELFIE)',
          icon: Icons.photo_camera_front,
          enabled: canAct && hasLocation && siteReady,
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

    // Field roles only (Project Manager, Site Engineer, Site Officer, Site
    // Supervisor, Technician): the server says so with
    // `can_start_site_visit`. An open visit can always be ended.
    if (state.openVisit != null || today.canStartSiteVisit) {
      final noSites =
          state.openVisit == null &&
          today.visitSites.isEmpty &&
          today.sites.isEmpty;
      buttons.add(
        _bigButton(
          valueKey: const ValueKey('attendance-site-visit'),
          label: state.openVisit == null
              ? 'START SITE VISIT'
              : 'END SITE VISIT',
          icon: Icons.explore_outlined,
          enabled: canAct && hasLocation && !noSites,
          loading: state.submitting,
          outlined: true,
          onTap: state.openVisit == null
              ? _startVisit
              : () => _run(controller.endVisit),
        ),
      );
      if (noSites) {
        buttons.add(
          Text(
            'No sites on the company list yet. A Project Manager adds '
            'sites; then you can start a site visit.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        );
      }
    }

    if (!hasLocation) {
      buttons.add(
        Text(
          'Turn on location — your GPS position is recorded with every '
          'check-in and check-out.',
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
}

/// Where you are checking in, and what you are there to do.
///
/// The suggestions are only shortcuts into the text box — the employee can
/// type anything, and the server stores exactly what was sent.
class _CheckInPurposeDialog extends StatefulWidget {
  const _CheckInPurposeDialog({required this.siteName});

  final String siteName;

  @override
  State<_CheckInPurposeDialog> createState() => _CheckInPurposeDialogState();
}

class _CheckInPurposeDialogState extends State<_CheckInPurposeDialog> {
  static const _suggestions = <String>[
    'Office work',
    'Site work',
    'Field work',
    'Meeting',
    'Training',
  ];

  final TextEditingController _purpose = TextEditingController();

  @override
  void dispose() {
    _purpose.dispose();
    super.dispose();
  }

  void _continue() {
    final text = _purpose.text.trim();
    if (text.isEmpty) return;
    Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AlertDialog(
      title: const Text('Check in'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              key: const ValueKey('check-in-site'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: theme.colorScheme.primaryContainer,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Icon(
                    Icons.location_on,
                    color: theme.colorScheme.onPrimaryContainer,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      widget.siteName,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: theme.colorScheme.onPrimaryContainer,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            TextField(
              key: const ValueKey('check-in-purpose'),
              controller: _purpose,
              autofocus: true,
              maxLength: 150,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _continue(),
              decoration: const InputDecoration(
                labelText: 'Purpose',
                hintText: 'What will you be doing here today?',
              ),
            ),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                for (final suggestion in _suggestions)
                  ActionChip(
                    label: Text(suggestion),
                    onPressed: () => setState(() {
                      _purpose.text = suggestion;
                      _purpose.selection = TextSelection.collapsed(
                        offset: suggestion.length,
                      );
                    }),
                  ),
              ],
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        ListenableBuilder(
          listenable: _purpose,
          builder: (context, _) => FilledButton.icon(
            key: const ValueKey('check-in-continue'),
            onPressed: _purpose.text.trim().isEmpty ? null : _continue,
            icon: const Icon(Icons.photo_camera_front),
            label: const Text('Take selfie'),
          ),
        ),
      ],
    );
  }
}

class _VisitAnswer {
  const _VisitAnswer(this.siteId, this.purpose, this.remarks);

  final int siteId;
  final String purpose;
  final String? remarks;
}

/// Which site, and why — asked once when a visit starts.
///
/// The sites are the company-wide list from the server (`visit_sites`).
/// The controllers live in this State, not in the caller, because the
/// future returned by `showDialog` resolves a moment before the route is
/// gone; disposing them there would throw from the exit animation.
class _PurposeDialog extends StatefulWidget {
  const _PurposeDialog({required this.sites, this.initialSiteId});

  final List<AssignedSite> sites;
  final int? initialSiteId;

  @override
  State<_PurposeDialog> createState() => _PurposeDialogState();
}

class _PurposeDialogState extends State<_PurposeDialog> {
  final TextEditingController _purpose = TextEditingController();
  final TextEditingController _remarks = TextEditingController();
  int? _siteId;

  @override
  void initState() {
    super.initState();
    final wanted = widget.initialSiteId;
    _siteId = widget.sites.any((s) => s.id == wanted)
        ? wanted
        : (widget.sites.length == 1 ? widget.sites.first.id : null);
  }

  @override
  void dispose() {
    _purpose.dispose();
    _remarks.dispose();
    super.dispose();
  }

  void _start() {
    final text = _purpose.text.trim();
    final site = _siteId;
    if (text.isEmpty || site == null) return;

    final notes = _remarks.text.trim();

    Navigator.of(context)
        .pop(_VisitAnswer(site, text, notes.isEmpty ? null : notes));
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Start site visit'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          DropdownButtonFormField<int>(
            key: const ValueKey('visit-site'),
            initialValue: _siteId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Site'),
            items: [
              for (final site in widget.sites)
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
            onChanged: (id) => setState(() => _siteId = id),
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('visit-purpose'),
            controller: _purpose,
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
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      ListenableBuilder(
        listenable: _purpose,
        builder: (context, _) => FilledButton(
          key: const ValueKey('visit-start'),
          onPressed: _purpose.text.trim().isEmpty || _siteId == null
              ? null
              : _start,
          child: const Text('Start'),
        ),
      ),
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
