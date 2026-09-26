import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/auth_controller.dart';
import '../../features/auth/auth_state.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/home/home_screen.dart';

/// Three routes, one redirect, and a guard driven entirely by auth state.
///
/// The whole app moves between `/login` and `/home` because [AuthState]
/// changed; no screen calls `context.go` to sign anyone in or out.
final routerProvider = Provider<GoRouter>((ref) {
  // GoRouter re-runs its redirect whenever this notifies.
  //
  // Bumping a plain notifier — rather than recreating the GoRouter itself —
  // is what keeps a sign-in from tearing down the route stack: rebuilding the
  // router would dispose the route currently on screen, and with it the
  // email and password the user had already typed.
  final authChanged = ValueNotifier<int>(0);

  ref.listen(authControllerProvider, (_, _) => authChanged.value++);

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: authChanged,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final location = state.matchedLocation;

      // The answer is not known yet. Send everything to the splash screen
      // rather than guessing — otherwise a returning user gets shown the
      // sign-in form for the length of a single request, then bounced away.
      if (auth.status == AuthStatus.restoring) {
        return location == '/' ? null : '/';
      }

      final landing = location == '/' || location == '/login';

      if (auth.isAuthenticated) {
        // Also covers a deep link back to the landing pages, so a stale
        // notification cannot drop a signed-in user onto the sign-in form.
        return landing ? '/home' : null;
      }

      // Not a session — which covers `authenticating` and `failed` as well:
      // neither should read as signed in. Anything but the form itself goes
      // there, `/` included: the splash screen exists only while the answer
      // is pending, and by now it has arrived.
      return location == '/login' ? null : '/login';
    },
    routes: [
      GoRoute(
        path: '/',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/home',
        builder: (context, state) => const HomeScreen(),
      ),
    ],
  );

  // Riverpod owns the router's lifetime. Both resources outlive exactly one
  // ProviderScope, and neither is safe to leak: a retained router keeps
  // listening to a notifier that no longer has a screen to refresh.
  ref.onDispose(() {
    authChanged.dispose();
    router.dispose();
  });

  return router;
});
