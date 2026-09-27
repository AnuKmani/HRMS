import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The device's cameras, as far as this app is concerned.
///
/// Lives in `core` rather than under `attendance` because two features need
/// one: a check-in face and a photographed medical certificate are the same
/// hardware problem with different subjects, and keeping the abstraction
/// beside the first would make the second import across a feature boundary
/// for what is really a device capability.
///
/// One camera per sheet, opened and closed by the flow that shows it.
final selfieCameraProvider = Provider<SelfieCamera>(
  (ref) => DeviceSelfieCamera(),
);

/// The rear camera, for photographing a document rather than a face.
///
/// A separate provider rather than a parameter at each call site: the lens a
/// flow needs is a property of the flow, and every caller of a certificate
/// sheet wants the same answer without having to state it.
final documentCameraProvider = Provider<SelfieCamera>(
  (ref) => DeviceSelfieCamera(lens: CameraLensDirection.back),
);

/// Why the camera is not showing a picture, in the cases a person can fix.
enum CameraStatus {
  /// Permission granted, hardware present, preview running.
  ready,

  /// Refused, and asking again will still show a prompt.
  permissionDenied,

  /// Refused in a way the app cannot undo by asking. Only Settings can.
  permanentlyDenied,

  /// The device has no camera at all.
  noCamera,

  /// Present but not working — in use by another app, or broken.
  unavailable,
}

/// A camera, as far as a screen is concerned.
///
/// An interface because a widget test cannot have a camera: the whole
/// flow — permission, no hardware, a camera that fails to open, the
/// capture itself — is exercised against this instead of against a phone
/// nobody has.
abstract class SelfieCamera {
  /// Enumerate the cameras, ask for permission if the platform has not yet
  /// granted it, and start the preview.
  ///
  /// Returns the state to render rather than throwing: every failure here
  /// is something the screen has words for, and an exception would only
  /// force every call site to invent those words again.
  Future<CameraStatus> open();

  /// The live preview, or null before [open] succeeds.
  Widget? get preview;

  /// One JPEG frame. Throws [CameraCaptureFailed] if the hardware will not
  /// hand one over.
  Future<Uint8List> capture();

  Future<void> close();
}

class CameraCaptureFailed implements Exception {
  const CameraCaptureFailed(this.message);

  final String message;

  @override
  String toString() => 'CameraCaptureFailed: $message';
}

/// The real one, on top of the `camera` plugin.
///
/// Two details worth knowing:
///
///  * **the permission prompt is raised by `initialize()`, not here.** On
///    Android the plugin asks as the controller comes up, so a refusal
///    arrives as a [CameraException] out of `open()` and is classified
///    below rather than by a second permission library this app does not
///    need;
///  * **`enableAudio` is false.** Neither a check-in selfie nor a
///    photograph of a doctor's note has sound, and requesting the
///    microphone alongside the camera would earn this app a permission it
///    will never use.
class DeviceSelfieCamera implements SelfieCamera {
  DeviceSelfieCamera({this.lens = CameraLensDirection.front});

  /// Which camera to prefer. [CameraLensDirection.front] for a face,
  /// [CameraLensDirection.back] for a document held up to the lens.
  final CameraLensDirection lens;

  CameraController? _controller;

  int _deniedStrikes = 0;

  @override
  Widget? get preview {
    final controller = _controller;

    if (controller == null || !controller.value.isInitialized) return null;

    return CameraPreview(controller);
  }

  @override
  Future<CameraStatus> open() async {
    await close();

    final List<CameraDescription> cameras;

    try {
      cameras = await availableCameras();
    } on CameraException catch (failure) {
      return _classify(failure);
    } catch (_) {
      return CameraStatus.unavailable;
    }

    if (cameras.isEmpty) return CameraStatus.noCamera;

    // The preferred lens first — but a device without one still gets a
    // working preview rather than an error screen.
    final chosen = cameras.firstWhere(
      (camera) => camera.lensDirection == lens,
      orElse: () => cameras.first,
    );

    final controller = CameraController(
      chosen,
      ResolutionPreset.medium,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.jpeg,
    );

    try {
      await controller.initialize();
      _controller = controller;
      _deniedStrikes = 0;

      return CameraStatus.ready;
    } on CameraException catch (failure) {
      await controller.dispose();
      return _classify(failure);
    } catch (_) {
      await controller.dispose();
      return CameraStatus.unavailable;
    }
  }

  @override
  Future<Uint8List> capture() async {
    final controller = _controller;

    if (controller == null || !controller.value.isInitialized) {
      throw const CameraCaptureFailed(
        'The camera is not ready. Close and try again.',
      );
    }

    try {
      final file = await controller.takePicture();
      return await file.readAsBytes();
    } on CameraException {
      throw const CameraCaptureFailed(
        'That picture could not be taken. Try again.',
      );
    }
  }

  @override
  Future<void> close() async {
    final controller = _controller;
    _controller = null;

    if (controller != null) await controller.dispose();
  }

  /// A refusal reported once is a person who tapped "No thanks". Reported a
  /// second time, having just been asked again, is the state Android does
  /// not expose to apps directly — "don't ask again" — and the only honest
  /// next step is to send them to Settings rather than to a prompt that
  /// will never appear.
  CameraStatus _classify(CameraException failure) {
    switch (failure.code) {
      case 'CameraAccessDenied':
        _deniedStrikes++;
        return _deniedStrikes > 1
            ? CameraStatus.permanentlyDenied
            : CameraStatus.permissionDenied;
      case 'CameraAccessDeniedWithoutPrompt':
      case 'CameraAccessRestricted':
        return CameraStatus.permanentlyDenied;
      default:
        return CameraStatus.unavailable;
    }
  }
}
