import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_controller.dart';

/// Where the app lands while it decides whether a stored session still works.
///
/// It deliberately decides nothing itself. The router already knows the answer
/// is pending — `AuthStatus.restoring` is what puts the user here — and will
/// move the moment it changes, so this screen only has to be honest about
/// waiting rather than pretend to a progress it has no part in.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();

    // Deferred to the end of the first frame: restoreSession() writes provider
    // state, and writing state while the tree is still building is an error.
    //
    // Not awaited — nothing here depends on the result. The controller owns
    // the outcome and the router reacts to it; the future is fire-and-forget
    // by design rather than by oversight.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      unawaited(ref.read(authControllerProvider.notifier).restoreSession());
    });
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 20),
            Text('Starting HRMS…'),
          ],
        ),
      ),
    );
  }
}
