import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';

/// Where report photographs are read from.
///
/// An interface, and one that exists purely so a widget test can be handed
/// a single JPEG: `ApiClient` is a concrete class built on a real Dio
/// configuration, and the alternative to this — constructing one per test
/// just to satisfy a thumbnail — would put a network stack in the middle of
/// a layout assertion.
///
/// Bytes rather than a URL, and the reason matters: every report
/// photograph sits on a private disk behind an authenticated endpoint.
/// There is no `Image.network` anywhere in this app, because a URL that
/// could be pasted into a browser would be exactly the leak the server's
/// private-storage policy exists to prevent.
abstract class ReportPhotoSource {
  Future<Uint8List> fetch(String path);
}

final reportPhotoSourceProvider = Provider<ReportPhotoSource>(
  (ref) => ApiReportPhotoSource(ref.watch(apiClientProvider)),
);

class ApiReportPhotoSource implements ReportPhotoSource {
  ApiReportPhotoSource(this._client);

  final ApiClient _client;

  @override
  Future<Uint8List> fetch(String path) => _client.bytes(path);
}
