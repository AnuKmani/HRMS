import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'features/attendance/data/offline_queue.dart';

Future<void> main() async {
  // ProviderScope is the root of every dependency the app has: the HTTP
  // client, the secure token store, the auth controller and the router.
  // Tests wrap the same tree and override those providers, which is how they
  // get a fake server without a single platform channel in sight.
  //
  // SharedPreferences has to exist before the first frame because the
  // offline attendance queue lives in it and is read the moment the
  // attendance screen opens. Awaiting it here rather than inside the queue
  // keeps every read synchronous once the app is running.
  WidgetsFlutterBinding.ensureInitialized();

  final preferences = await SharedPreferences.getInstance();

  runApp(
    ProviderScope(
      overrides: [
        offlineQueueStoreProvider.overrideWithValue(
          SharedPreferencesOfflineQueueStore(preferences),
        ),
      ],
      child: const HrmsApp(),
    ),
  );
}
