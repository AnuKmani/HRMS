import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image/image.dart' as img;

final selfieCompressorProvider = Provider<SelfieCompressor>(
  (ref) => const SelfieCompressor(),
);

/// The file the server would have refused, caught before it was sent.
class SelfieRejected implements Exception {
  const SelfieRejected(this.message);

  final String message;

  @override
  String toString() => 'SelfieRejected: $message';
}

/// Turns whatever the camera handed over into something the API will accept.
///
/// Three constraints, and each one mirrors a server rule rather than
/// inventing a client-side preference:
///
///  * **decoded at all** — a "selfie" that is not an image is refused here,
///    which is the same answer `mimes`/`mimetypes` would give, asked early
///    enough that the person can retake it;
///  * **longest edge capped** — a 12-megapixel frame uploads slowly over a
///    site's patchy signal and shows as a thumbnail anyway;
///  * **bytes under `hrms.storage.selfie_max_kilobytes`** (5 MB by default)
///    — the ceiling the check-in validator enforces, applied here so a
///    rejection costs a retry rather than a trip back to the gate.
///
/// Pure Dart (`package:image`), so it works identically on every platform
/// and needs no native code to keep a photograph private on its way in.
class SelfieCompressor {
  const SelfieCompressor();

  static const int maxEdge = 1280;

  static const int maxBytes = 5120 * 1024;

  static const int startQuality = 82;

  static const int floorQuality = 45;

  Future<Uint8List> compress(Uint8List raw, {bool isolate = true}) async {
    if (isolate) {
      // A camera frame is tens of millions of pixels; decoding it on the UI
      // thread is a visible stutter at exactly the moment the screen is
      // waiting on a shutter.
      final result = await compute(_compress, raw);
      return _require(result);
    }

    return _require(_compress(raw));
  }

  static Uint8List? _compress(Uint8List raw) {
    final decoded = img.decodeImage(raw);
    if (decoded == null) return null;

    var image = decoded;

    final longest = math.max(image.width, image.height);

    if (longest > maxEdge) {
      image = image.width >= image.height
          ? img.copyResize(image, width: maxEdge)
          : img.copyResize(image, height: maxEdge);
    }

    var quality = startQuality;

    while (true) {
      final encoded = img.encodeJpg(image, quality: quality);

      if (encoded.length <= maxBytes) return Uint8List.fromList(encoded);

      if (quality <= floorQuality) return Uint8List.fromList(encoded);

      quality = math.max(floorQuality, quality - 12);
    }
  }

  Uint8List _require(Uint8List? bytes) {
    if (bytes == null) {
      throw const SelfieRejected(
        'That file is not a readable image. Take the photo again.',
      );
    }

    return bytes;
  }
}
