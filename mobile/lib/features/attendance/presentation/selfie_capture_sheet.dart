import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/data/device_camera.dart';
import '../../../core/presentation/camera_capture_sheet.dart';

/// The check-in photograph — the shared capture flow wearing attendance's
/// own words.
///
/// The flow itself lives in [CameraCaptureSheet], because a photographed
/// medical certificate (see Phase 6's leave feature) is the same six states
/// with a different subject. What is specific to *this* one is the copy: a
/// refusal to grant the camera has to say why it was wanted, and "your face
/// at the site" and "your medical certificate" are not interchangeable
/// explanations.
///
/// Nothing about the widget's behaviour moved with the extraction — the
/// states, the buttons and the exact sentences are unchanged, which is what
/// the Phase 5 attendance tests still assert.
class SelfieCaptureSheet extends StatelessWidget {
  const SelfieCaptureSheet({super.key});

  static const _copy = CameraCaptureCopy(
    title: 'Check-in photo',
    description:
        'Your face at the site, taken now. Only you and the people '
        'responsible for your attendance can see it.',
    permissionDenied:
        'The camera is needed for your check-in photo. Nothing is '
        'recorded without one.',
    noCamera: 'This device has no camera, so a check-in photo cannot be taken.',
  );

  /// Opens the sheet and resolves to the raw bytes, or null if cancelled.
  static Future<Uint8List?> show(BuildContext context) =>
      CameraCaptureSheet.show(
        context,
        cameraProvider: selfieCameraProvider,
        copy: _copy,
      );

  @override
  Widget build(BuildContext context) =>
      CameraCaptureSheet(cameraProvider: selfieCameraProvider, copy: _copy);
}
