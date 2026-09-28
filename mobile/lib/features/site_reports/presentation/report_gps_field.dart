import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../attendance/data/device_location.dart';
import '../../attendance/domain/location_fix.dart';

/// The "where was this written?" control shared by the activity form and
/// the submit buttons.
///
/// Reads the phone's radio through attendance's [LocationGateway] rather
/// than owning a second one. The gateway, the five permission states and
/// the copy for each were built for a check-in and are correct for a report
/// too — and two implementations of "ask for location" would mean two
/// places to get `permanentlyDenied` wrong, which is the one state where
/// asking again is useless and only Settings can help.
///
/// What is *different* here is when it happens. A draft may be saved with
/// no fix at all — half a report written indoors is a normal thing — and
/// the reading becomes mandatory only at **submit**, which is why the
/// parent owns [fix] rather than this widget. The server's own rule
/// (optional while drafting, required to submit) is the rule this draws;
/// nothing here invents a second one.
///
/// No background location, and no polling: one reading, taken when a
/// person asks for it.
class ReportGpsField extends ConsumerStatefulWidget {
  const ReportGpsField({
    super.key,
    required this.fix,
    required this.onCaptured,
    this.label = 'Location',
    this.required = false,
    this.errorText,
    this.busy = false,
  });

  /// The reading the report will carry, or null when none has been taken.
  final LocationFix? fix;

  final ValueChanged<LocationFix?> onCaptured;

  final String label;

  /// Whether the *next* action (submit) needs a reading. It changes the
  /// helper sentence under the field, not whether the button exists: a
  /// person who cannot get a fix should still be able to save a draft.
  final bool required;

  final String? errorText;

  /// True while the parent is doing something else that a second tap would
  /// disturb.
  final bool busy;

  @override
  ConsumerState<ReportGpsField> createState() => _ReportGpsFieldState();
}

class _ReportGpsFieldState extends ConsumerState<ReportGpsField> {
  LocationStatus? _status;
  String? _message;
  bool _reading = false;

  LocationGateway get _gateway => ref.read(locationGatewayProvider);

  Future<void> _capture() async {
    setState(() => _reading = true);

    try {
      var status = await _gateway.checkPermission();

      if (status != LocationStatus.granted) {
        status = await _gateway.requestPermission();
      }

      if (status != LocationStatus.granted) {
        if (!mounted) return;

        setState(() {
          _status = status;
          _message = null;
          _reading = false;
        });

        return;
      }

      if (!await _gateway.isServiceEnabled()) {
        if (!mounted) return;

        setState(() {
          _status = LocationStatus.serviceDisabled;
          _message = null;
          _reading = false;
        });

        return;
      }

      final fix = await _gateway.currentFix();

      if (!mounted) return;

      setState(() {
        _status = LocationStatus.granted;
        _message = null;
        _reading = false;
      });

      widget.onCaptured(fix.isUsable ? fix : null);

      if (!fix.isUsable) {
        setState(() {
          _status = LocationStatus.unavailable;
          _message =
              'The receiver did not return a usable reading. Move into the '
              'open and try again.';
        });
      }
    } on LocationAcquisitionFailed catch (failure) {
      if (!mounted) return;

      setState(() {
        _status = LocationStatus.unavailable;
        _message = failure.message;
        _reading = false;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _status = LocationStatus.unavailable;
        _message = 'The reading failed. Try again.';
        _reading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fix = widget.fix;
    final hasFix = fix != null;

    final condition = !hasFix && _status != null
        ? LocationCondition(_status!, _message ?? '')
        : null;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(widget.label, style: theme.textTheme.labelLarge),
              const SizedBox(width: 8),
              if (widget.required)
                Text(
                  'Required to submit',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.primary,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Card(
            key: const ValueKey('report-gps'),
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        hasFix
                            ? Icons.gps_fixed
                            : switch (_status) {
                                LocationStatus.serviceDisabled => Icons.gps_off,
                                LocationStatus.permanentlyDenied => Icons.block,
                                LocationStatus.granted => Icons.gps_fixed,
                                _ => Icons.gps_not_fixed,
                              },
                        size: 20,
                        color: hasFix
                            ? Colors.green.shade700
                            : theme.colorScheme.error,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          hasFix
                              ? '±${fix.accuracy.round()} m accuracy — '
                                    '${fix.latitude.toStringAsFixed(5)}, '
                                    '${fix.longitude.toStringAsFixed(5)}'
                              : condition?.message ??
                                    'No reading yet. Take one before you '
                                        'submit.',
                          key: ValueKey(
                            hasFix ? 'report-gps-taken' : 'report-gps-empty',
                          ),
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      FilledButton.tonalIcon(
                        key: const ValueKey('report-gps-capture'),
                        onPressed: widget.busy || _reading ? null : _capture,
                        icon: _reading
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.my_location, size: 18),
                        label: Text(hasFix ? 'Take another' : 'Take reading'),
                      ),
                      const SizedBox(width: 8),
                      if (_status == LocationStatus.serviceDisabled)
                        TextButton(
                          key: const ValueKey('report-gps-open-settings'),
                          onPressed: widget.busy
                              ? null
                              : _gateway.openLocationSettings,
                          child: const Text('Turn on GPS'),
                        )
                      else if (_status == LocationStatus.permanentlyDenied)
                        TextButton(
                          key: const ValueKey('report-gps-open-settings'),
                          onPressed: widget.busy
                              ? null
                              : _gateway.openAppSettings,
                          child: const Text('Open Settings'),
                        ),
                    ],
                  ),
                  if (condition != null && !condition.isUsable) ...[
                    const SizedBox(height: 8),
                    Text(
                      condition.message,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.error,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          if (widget.errorText != null) ...[
            const SizedBox(height: 6),
            Text(
              widget.errorText!,
              key: const ValueKey('report-gps-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
