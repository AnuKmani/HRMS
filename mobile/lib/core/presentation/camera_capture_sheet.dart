import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/device_camera.dart';

/// The four sentences a capture flow cannot write itself.
///
/// Parameterised because the flow is shared — a check-in face and a
/// photographed medical certificate are the same six states with different
/// subjects, and the copy that explains a refusal has to name the right one.
/// Everything else in [CameraCaptureSheet] is identical between them and is
/// written once.
class CameraCaptureCopy {
  const CameraCaptureCopy({
    required this.title,
    required this.description,
    required this.permissionDenied,
    required this.noCamera,
  });

  final String title;
  final String description;

  /// Shown when the person has not granted the camera yet.
  final String permissionDenied;

  /// Shown when there is no hardware, where no amount of retrying helps.
  final String noCamera;
}

/// A photograph, start to finish, for any subject.
///
/// The flow this widget walks is the one the requirement asks for, and each
/// step is a distinct thing the screen has words for:
///
///   **open** → enumerate the cameras and ask for permission;
///   **preview** → the live camera, with a shutter;
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
class CameraCaptureSheet extends ConsumerStatefulWidget {
  const CameraCaptureSheet({
    super.key,
    required this.cameraProvider,
    required this.copy,
  });

  /// Opens the sheet and resolves to the raw bytes, or null if cancelled.
  static Future<Uint8List?> show(
    BuildContext context, {
    required Provider<SelfieCamera> cameraProvider,
    required CameraCaptureCopy copy,
  }) => showModalBottomSheet<Uint8List>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (context) =>
        CameraCaptureSheet(cameraProvider: cameraProvider, copy: copy),
  );

  /// Which camera to open — front for a face, back for a document.
  final Provider<SelfieCamera> cameraProvider;

  final CameraCaptureCopy copy;

  @override
  ConsumerState<CameraCaptureSheet> createState() => _CameraCaptureSheetState();
}

class _CameraCaptureSheetState extends ConsumerState<CameraCaptureSheet> {
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
    _camera = ref.read(widget.cameraProvider);
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
    final copy = widget.copy;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(copy.title, style: theme.textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(
            copy.description,
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
        widget.copy.permissionDenied,
        _Action('Allow camera', _open),
      ),
      CameraStatus.permanentlyDenied => (
        'Camera access is blocked for this app. Open Settings, allow the '
            'camera, then come back.',
        _Action('Open Settings', _openSettings),
      ),
      CameraStatus.noCamera => (widget.copy.noCamera, null),
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
