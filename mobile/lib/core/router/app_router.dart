import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/auth_controller.dart';
import '../../features/auth/auth_state.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/departments/presentation/department_form_screen.dart';
import '../../features/departments/presentation/departments_list_screen.dart';
import '../../features/designations/presentation/designation_form_screen.dart';
import '../../features/designations/presentation/designations_list_screen.dart';
import '../../features/employees/presentation/employee_detail_screen.dart';
import '../../features/employees/presentation/employee_form_screen.dart';
import '../../features/employees/presentation/employees_list_screen.dart';
import '../../features/home/home_screen.dart';
import '../../features/projects/presentation/project_detail_screen.dart';
import '../../features/projects/presentation/project_form_screen.dart';
import '../../features/projects/presentation/projects_list_screen.dart';
import '../../features/sites/presentation/site_detail_screen.dart';
import '../../features/sites/presentation/site_form_screen.dart';
import '../../features/sites/presentation/sites_list_screen.dart';

/// The app's routes, one redirect, and a guard driven entirely by auth state.
///
/// Phase 4 adds the five business modules. Two rules shape how they are laid
/// out:
///
///  - **`new` before `:id`, and `:id` only matches digits.** A route
///    `'/employees/:id'` would otherwise swallow `/employees/new` and try to
///    parse it as a person. The `(\d+)` pattern is the belt; the ordering is
///    the braces.
///  - **No route guards anything on a permission.** Every screen behind one
///    asks `PermissionScope` whether to offer the way in, and every request
///    it makes is checked again by the API. A redirect that hid a URL would
///    be a second, weaker authorisation system to keep in step with the
///    first — so there isn't one; the redirect only ever answers "is there a
///    session?".
final routerProvider = Provider<GoRouter>((ref) {
  // GoRouter re-runs its redirect whenever this notifies.
  //
  // Bumping a plain notifier — rather than recreating the GoRouter itself —
  // is what keeps a sign-in from tearing down the route stack: rebuilding the
  // router would dispose the route currently on screen, and with it the
  // email and password the user had already typed.
  final authChanged = ValueNotifier<int>(0);

  ref.listen(authControllerProvider, (_, _) => authChanged.value++);

  /// The `:id` path parameter, as the integer the repositories expect.
  ///
  /// The `(\d+)` pattern on each route means this cannot throw for a
  /// well-formed URL; a malformed one never reaches the builder.
  int idOf(GoRouterState state) =>
      int.parse(state.pathParameters['id']!);

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

      /* ------------------------------------------------------ employees */

      GoRoute(
        path: '/employees',
        builder: (context, state) => const EmployeesListScreen(),
      ),
      GoRoute(
        path: '/employees/new',
        builder: (context, state) => const EmployeeFormScreen(),
      ),
      GoRoute(
        path: '/employees/:id',
        builder: (context, state) =>
            EmployeeDetailScreen(employeeId: idOf(state)),
      ),
      GoRoute(
        path: '/employees/:id/edit',
        builder: (context, state) =>
            EmployeeFormScreen(employeeId: idOf(state)),
      ),

      /* ---------------------------------------------------- departments */

      GoRoute(
        path: '/departments',
        builder: (context, state) => const DepartmentsListScreen(),
      ),
      GoRoute(
        path: '/departments/new',
        builder: (context, state) => const DepartmentFormScreen(),
      ),
      GoRoute(
        path: '/departments/:id',
        builder: (context, state) =>
            DepartmentFormScreen(departmentId: idOf(state)),
      ),

      /* --------------------------------------------------- designations */

      GoRoute(
        path: '/designations',
        builder: (context, state) => const DesignationsListScreen(),
      ),
      GoRoute(
        path: '/designations/new',
        builder: (context, state) => const DesignationFormScreen(),
      ),
      GoRoute(
        path: '/designations/:id',
        builder: (context, state) =>
            DesignationFormScreen(designationId: idOf(state)),
      ),

      /* ------------------------------------------------------- projects */

      GoRoute(
        path: '/projects',
        builder: (context, state) => const ProjectsListScreen(),
      ),
      GoRoute(
        path: '/projects/new',
        builder: (context, state) => const ProjectFormScreen(),
      ),
      GoRoute(
        path: '/projects/:id',
        builder: (context, state) =>
            ProjectDetailScreen(projectId: idOf(state)),
      ),
      GoRoute(
        path: '/projects/:id/edit',
        builder: (context, state) =>
            ProjectFormScreen(projectId: idOf(state)),
      ),

      /* ---------------------------------------------------------- sites */

      GoRoute(
        path: '/sites',
        builder: (context, state) => const SitesListScreen(),
      ),
      GoRoute(
        path: '/sites/new',
        builder: (context, state) => const SiteFormScreen(),
      ),
      GoRoute(
        path: '/sites/:id',
        builder: (context, state) => SiteDetailScreen(siteId: idOf(state)),
      ),
      GoRoute(
        path: '/sites/:id/edit',
        builder: (context, state) =>
            SiteFormScreen(siteId: idOf(state)),
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
