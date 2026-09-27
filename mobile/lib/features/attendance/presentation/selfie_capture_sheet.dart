import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/selfie_camera.dart';

/// The check-in photograph, start to finish.
///
/// The flow this widget walks is the one the requirement asks for, and each
/// step is a distinct thing the screen has words for:
///
///   **open** → enumerate the cameras and ask for permission;
///   **preview** → the live front camera, with a shutter;
///   **review** → the frame that was taken, with *Retake* next to *Use*;
///   **submit** → hand the bytes back to the caller, which compresses and
///                 sends them.
///
/// Everything that can go wrong on the way — refused, refused for good, no
/// camera, a camera that will not open, a capture that fails — lands on this
/// screen as a message and *one* obvious next step. None of them close the
/// sheet behind the person's back, because the alternative to "try again" is
/// "start over", which is the wrong answer to a permission dialog.
///
/// Returns the raw JPEG bytes, or null if the person backed out. Compression
/// deliberately does not happen here: the caller needs the bytes whether the
/// network is up (send now) or down (store for later), and doing it once in
/// one place means the queued copy and the uploaded copy are identical.
class SelfieCaptureSheet extends ConsumerStatefulWidget {
  const SelfieCaptureSheet({super.key});

  /// Opens the sheet and resolves to the raw bytes, or null if cancelled.
  static Future<Uint8List?> show(BuildContext context) =>
      showModalBottomSheet<Uint8List>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        builder: (context) => const SelfieCaptureSheet(),
      );

  @override
  ConsumerState<SelfieCaptureSheet> createState() => _SelfieCaptureSheetState();
}

class _SelfieCaptureSheetState extends ConsumerState<SelfieCaptureSheet> {
  late final SelfieCamera _camera;

  CameraStatus _status = CameraStatus.unavailable;
  bool _opening = true;
  bool _capturing = false;
  bool _working = false;

  /// The frame under review, once a shutter has been pressed.
  Uint8List? _reviewing;

  @override
  void initState() {
    super.initState();
    _camera = ref.read(selfieCameraProvider);
    _open();
  }

  @override
  void dispose() {
    // The preview is a hardware surface; leaving it running after the sheet
    // is gone keeps the camera open for the rest of the session.
    _camera.close();
    super.dispose();
  }

  Future<void> _open() async {
    setState(() {
      _opening = true;
      _reviewing = null;
    });

    final status = await _camera.open();

    if (!mounted) return;

    setState(() {
      _status = status;
      _opening = false;
    });
  }

  Future<void> _capture() async {
    if (_capturing) return;

    setState(() => _capturing = true);

    try {
      final bytes = await _camera.capture();
      if (!mounted) return;
      setState(() => _reviewing = bytes);
    } on CameraCaptureFailed catch (failure) {
      if (!mounted) return;
      _say(failure.message);
    } catch (_) {
      if (!mounted) return;
      _say('That picture could not be taken. Try again.');
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  void _say(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _openSettings() => _camera.close().then((_) => _open());

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('Check-in photo', style: theme.textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(
            'Your face at the site, taken now. Only you and the people '
            'responsible for your attendance can see it.',
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          if (_reviewing != null) _review(theme) else _live(theme),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: _working
                      ? null
                      : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton(
                  onPressed: _reviewing == null || _working
                      ? null
                      : () {
                          setState(() => _working = true);
                          Navigator.of(context).pop(_reviewing);
                        },
                  child: const Text('Use photo'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _live(ThemeData theme) {
    if (_opening) {
      return const SizedBox(
        height: 260,
        child: Center(child: CircularProgressIndicator()),
      );
    }

    if (_status != CameraStatus.ready) return _problem(theme);

    final preview = _camera.preview;

    if (preview == null) {
      return const SizedBox(
        height: 260,
        child: Center(child: CircularProgressIndicator()),
      );
    }

    return SizedBox(
      height: 300,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Stack(
          fit: StackFit.expand,
          children: [
            preview,
            Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: FloatingActionButton.large(
                  onPressed: _capturing ? null : _capture,
                  child: _capturing
                      ? const SizedBox(
                          width: 28,
                          height: 28,
                          child: CircularProgressIndicator(strokeWidth: 3),
                        )
                      : const Icon(Icons.camera_alt),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _review(ThemeData theme) => SizedBox(
    height: 300,
    child: ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Stack(
        fit: StackFit.expand,
        children: [
          Image.memory(
            _reviewing!,
            fit: BoxFit.cover,
            // A frame the decoder rejects must not take the sheet with
            // it: the person still has a Retake and a Use, and the
            // server will reject a photograph it cannot read anyway.
            errorBuilder: (context, error, stackTrace) => const ColoredBox(
              color: Colors.black12,
              child: Center(child: Icon(Icons.broken_image_outlined, size: 40)),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: FilledButton.icon(
                onPressed: _open,
                icon: const Icon(Icons.refresh),
                label: const Text('Retake'),
              ),
            ),
          ),
        ],
      ),
    ),
  );

  /// Every non-ready status, each with the single action that can change it.
  Widget _problem(ThemeData theme) {
    final (message, action) = switch (_status) {
      CameraStatus.permissionDenied => (
        'The camera is needed for your check-in photo. Nothing is '
            'recorded without one.',
        _Action('Allow camera', _open),
      ),
      CameraStatus.permanentlyDenied => (
        'Camera access is blocked for this app. Open Settings, allow the '
            'camera, then come back.',
        _Action('Open Settings', _openSettings),
      ),
      CameraStatus.noCamera => (
        'This device has no camera, so a check-in photo cannot be taken.',
        null,
      ),
      CameraStatus.unavailable => (
        'The camera could not be opened. Another app may be using it.',
        _Action('Try again', _open),
      ),
      _ => ('The camera is not ready.', _Action('Try again', _open)),
    };

    return Container(
      height: 260,
      padding: const EdgeInsets.all(24),
      alignment: Alignment.center,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            _status == CameraStatus.noCamera
                ? Icons.no_photography_outlined
                : Icons.camera_alt_outlined,
            size: 40,
            color: theme.colorScheme.outline,
          ),
          const SizedBox(height: 12),
          Text(message, textAlign: TextAlign.center),
          if (action != null) ...[
            const SizedBox(height: 16),
            FilledButton(onPressed: action.onTap, child: Text(action.label)),
          ],
        ],
      ),
    );
  }
}

class _Action {
  const _Action(this.label, this.onTap);

  final String label;
  final Future<void> Function() onTap;
}
