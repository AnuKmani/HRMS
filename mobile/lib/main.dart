import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';

void main() {
  // ProviderScope is the root of every dependency the app has: the HTTP
  // client, the secure token store, the auth controller and the router. Tests
  // wrap the same tree and override those providers, which is how they get a
  // fake server without a single platform channel in sight.
  runApp(const ProviderScope(child: HrmsApp()));
}
