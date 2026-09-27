# Flutter Guide

> **Status:** Phase 6 — four new modules are built and tested on top of the
> organisation, auth and attendance slices: `leave/`, `holidays/`, `timesheet/`
> and `overtime/`, in the same `data / domain / presentation` shape as
> everything before them. `dart format .` clean, `flutter analyze` clean,
> `flutter test` **215 passed**. This guide explains the concepts and patterns
> the app uses, written for someone who knows PHP/Laravel but is new to
> Flutter/Dart. Sections that were written as a plan in earlier phases — the
> offline queue, the location and camera permission flows, the approval chain,
> the module permission gates — are now describing shipped code, and say so.

---

## 1. Why these explanations exist

You know Laravel well. Flutter is a different language (Dart) with a different
architecture, but the *ideas* map closely. Where a concept has a Laravel equivalent,
this guide says so.

---

## 2. Dart in 5 minutes (for Laravel developers)

| Dart | PHP equivalent |
|---|---|
| `String`, `int`, `double`, `bool` | `string`, `int`, `float`, `bool` |
| `List<String>` | `array` of strings |
| `Map<String, dynamic>` | associative array / `array` |
| `class` | `class` |
| `final` | `const` reference — cannot be reassigned |
| `const` | compile-time constant |
| `var` | inferred type, reassignable |
| `required` named param | required argument |
| `async` / `await` | `async` / `await` |
| `Future<T>` | `Promise<T>` / awaiting a result |
| `null` safety (`String?`) | nullable type — `?` means it may be null |
| `late` | variable assigned before use (avoid if possible) |
| `mixin` | trait / `use` behaviour |
| `extends` | `extends` |

**Important Dart rule:** there is no `null` by default. `String name;` cannot be null —
`String? name;` can. This is stricter than PHP and prevents a whole class of bugs.

### 2.1 A typical model class

```dart
class Site {
  final int id;
  final String name;
  final double latitude;
  final double longitude;
  final int geofenceRadius;

  const Site({
    required this.id,
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.geofenceRadius,
  });

  // Equivalent of Laravel's API Resource / toArray()
  factory Site.fromJson(Map<String, dynamic> json) => Site(
        id: json['id'] as int,
        name: json['name'] as String,
        latitude: (json['latitude'] as num).toDouble(),
        longitude: (json['longitude'] as num).toDouble(),
        geofenceRadius: json['geofence_radius'] as int,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'latitude': latitude,
        'longitude': longitude,
        'geofence_radius': geofenceRadius,
      };
}
```

**What this is:** a plain data class — the Dart version of a Laravel Model's `toArray()`.
**Why we need it:** JSON from the API is untyped `Map<String, dynamic>`; converting it
once at the edge means the rest of the app works with real types.
**Where it belongs:** `features/sites/data/models/site.dart`.

---

## 3. Widget = Blade component

In Flutter, **everything is a Widget** — a button, a text label, a whole screen, even
padding. A widget is immutable and cheap; Flutter rebuilds them constantly.

```dart
class AttendanceScreen extends ConsumerWidget {
  const AttendanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(attendanceProvider);   // ← like watching a variable
    return Scaffold(
      appBar: AppBar(title: const Text("Today's Attendance")),
      body: state.isLoading
          ? const CircularProgressIndicator()        // loading state
          : state.hasError
              ? ErrorView(message: state.error!)    // error state
              : AttendanceBody(state: state),       // data state
    );
  }
}
```

**What it is:** a screen that rebuilds automatically when its data changes.
**Why `ConsumerWidget` instead of `StatelessWidget`:** it lets the widget *watch* a
Riverpod provider. Plain widgets cannot access providers.
**Where it belongs:** `features/attendance/presentation/attendance_screen.dart` —
which exists, and is where the example below was taken from.

**Every screen must handle four states:** loading, error, empty, data. `core/widgets/`
provides shared `LoadingView`, `ErrorView`, `EmptyState` for this.

---

## 4. Riverpod = reactive state (compare: Laravel service + view)

**What it is:** a dependency-injection and state-management system.

**Why not just call the repository from the screen?** Because that scatters loading
flags, error handling and caching across every widget, and makes testing painful.

```dart
// 1. Declare the provider (compare: a Laravel service binding)
final attendanceRepositoryProvider = Provider<AttendanceRepository>((ref) {
  return AttendanceRepositoryImpl(ref.watch(apiServiceProvider));
});

// 2. State for a screen (compare: a controller action's view data)
final todayAttendanceProvider = FutureProvider<TodayAttendance>((ref) async {
  final repo = ref.watch(attendanceRepositoryProvider);
  return repo.fetchToday();
});
```

```dart
// 3. Use it in a widget
final attendance = ref.watch(todayAttendanceProvider);

return attendance.when(
  loading:  () => const CircularProgressIndicator(),
  error:    (e, _) => ErrorView(message: friendlyMessage(e)),
  data:     (a) => AttendanceCard(attendance: a),
);
```

**Key idea:** the widget does not know or care whether the data came from the network,
the offline database, or a cache. That decision lives in the Repository.

**As implemented in Phase 3 — `Notifier` for a state machine.** `FutureProvider`
models *a value that will arrive*; auth needs *a status the whole app keys off*,
including transitions where nothing is loading. That is `Notifier`:

```dart
// features/auth/auth_controller.dart
final authControllerProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    _repository   = ref.watch(authRepositoryProvider);
    _tokenStore   = ref.watch(tokenStoreProvider);
    _deviceIdentity = ref.watch(deviceIdentityProvider);

    // The HTTP layer announcing that a token we sent was rejected.
    _sessionSubscription = ref
        .watch(apiClientProvider)
        .sessionRejected
        .listen((_) => signOutLocally());

    ref.onDispose(() => _sessionSubscription?.cancel());
    return AuthState.restoring();          // build() must be synchronous
  }

  Future<bool> login({required String email, required String password}) async {
    state = const AuthState(status: AuthStatus.authenticating);
    try {
      final result = await _repository.login(/* … */);
      await _tokenStore.write(result.token);
      state = AuthState(status: AuthStatus.authenticated, user: result.user);
      return true;
    } on ApiException catch (failure) {
      state = AuthState(status: AuthStatus.failed, message: failure.message,
                        errors: failure.errors, retryAfter: failure.retryAfter);
      return false;
    }
  }
}
```

Three habits worth keeping:

- **Every transition writes a whole new `AuthState`.** No partially updated
  object where `user` belongs to one status and `status` says another.
- **`build()` returns synchronously.** Asynchronous work starts after it and
  writes `state` when it lands — which is exactly what `AuthState.restoring()`
  exists for.
- **Methods called from a button never throw.** `login()` returns `bool` and
  puts every failure into `state`, because an unhandled exception there would
  leave the form spinning with no way to find out why.

A provider that never changes shape (`tokenStoreProvider`, `apiClientProvider`)
stays a plain `Provider` — see `core/`.

---

## 5. Repository = the seam between UI and data

**What it is:** a class the provider calls instead of touching HTTP directly.
**Why we need it:** it answers one question — *"where does this data come from?"*

```dart
class AttendanceRepositoryImpl implements AttendanceRepository {
  final AttendanceApi _api;
  final AttendanceLocalDb _local;

  AttendanceRepositoryImpl(this._api, this._local);

  @override
  Future<TodayAttendance> fetchToday() async {
    if (await isConnected()) {
      final remote = await _api.fetchToday();
      await _local.cacheToday(remote);      // keep a fresh local copy
      return remote;
    }
    return _local.getCachedToday();          // offline fallback
  }

  @override
  Future<SyncResult> syncPending() async {
    final pending = await _local.pendingActions();
    final result  = await _api.sync(pending);   // server re-validates everything
    await _local.markSynced(result);
    return result;
  }
}
```

**Where it belongs:** the contract in `features/attendance/domain/attendance_repository.dart`,
the Dio implementation beside it in `features/attendance/data/api_attendance_repository.dart`.
That split is what lets a widget test hand the screen a scripted repository that
records every call instead of a socket.

**Why this matters for offline:** when you add offline support you change *this class*.
The screen and the provider stay untouched.

**As implemented in Phase 3 — the interface comes first.** `AuthRepository` is
abstract and the provider binds the real one, so every auth test substitutes an
in-memory implementation: no socket, no timing, no flake.

```dart
// features/auth/auth_repository.dart
abstract class AuthRepository {
  Future<LoginResult> login({required String email, required String password,
                             required String deviceName});
  Future<AuthUser> me();
  Future<void> logout();
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => ApiAuthRepository(ref.watch(apiClientProvider)),
);
```

The controller, the screens and the router never learn that a `Dio` exists.
The same seam later holds the offline queue: `AuthRepository` grows a second
implementation, and nothing above it changes.

---

## 6. Dio + interceptors = the API client

**What it is:** an HTTP client with pluggable middleware (like Laravel middleware, but
for outgoing requests).

`mobile/lib/core/network/api_client.dart` — one interceptor, three jobs:

```dart
InterceptorsWrapper(
  onRequest: (options, handler) async {
    final token = await tokenStore.read();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
      options.extra[_taggedToken] = true;   // "this request carried a token"
    }
    handler.next(options);
  },
  onError: (failure, handler) {
    final presented = failure.requestOptions.extra[_taggedToken] == true;
    if (presented && failure.response?.statusCode == 401) {
      _sessionRejected.add(null);           // a stored session just died
    }
    handler.next(failure);
  },
)
```

**What it does:**
- attaches the Sanctum token to every request that should carry one
- catches `401` centrally — **but only when a token was attached** (see below)
- guarantees every failure leaves as `ApiException`, never a raw `DioException`
- applies connect / receive timeouts

**Why the `presented_token` tag matters.** A wrong password on the sign-in form
comes back `401` too, and that request carried no token. Keying the broadcast
off *"did we send a credential?"* rather than off the status code means a bad
password can never be mistaken for an expired session — the app will not sign
someone out because they mistyped.

**Where it belongs:** `core/network/`.

---

## 7. The response envelope — one parser for everything

Laravel returns `{success, message, data, errors}`. Parse it **once**, in
`core/network/api_client.dart` (success) and `core/network/api_exception.dart`
(failure):

```dart
/// success → data + message
class ApiEnvelope {
  final String message;
  final Object? data;
}

/// failure → one shape for every way a request can go wrong
class ApiException implements Exception {
  final int statusCode;              // 0 when no response arrived at all
  final String message;              // safe to render verbatim
  final Map<String, String> errors;  // field => message, {} when there is none
  final Duration? retryAfter;        // parsed from Retry-After on 429

  bool get isUnauthenticated => statusCode == 401;
  bool get isValidation      => statusCode == 422;
  bool get isRateLimited     => statusCode == 429;
}
```

`apiExceptionFrom(DioException)` copies the server's own message across when
the body is a real envelope, and substitutes copy written in the app when it is
not. That second half is the important one: a captive portal answering with
HTML, a proxy returning an empty body, a timeout with no response at all — none
of that should ever reach a screen as though it were a sentence.

Repositories return a typed model or throw `ApiException`. Screens never see
raw JSON, and never see a `DioException`.

**Failure types:**

| Trigger | `statusCode` | User sees |
|---|---|---|
| No internet / timeout / connection refused | `0` | "Could not reach the server. Check your connection and try again." |
| `401` | `401` | force re-login (the router does this, not the screen) |
| `403` | `403` | "not authorized" — **do not** log out |
| `422` | `422` | banner `message` + `errors` under the named fields |
| `429` | `429` | `message` + "You can try again in *N*s" from `retryAfter` |
| `404` / `5xx` / non-envelope body | as received | "The server returned an unexpected response. Please try again." |

Never show a raw exception to a user. Log it for developers instead.

> Laravel's `errors` map is `field: [messages]`. `_fieldErrors()` flattens it
> to the first message per field — one line under an input is what a form can
> actually draw.

---

## 8. Secure storage vs preferences

| Data | Storage |
|---|---|
| Auth token | **`flutter_secure_storage`** (Keychain / Keystore) |
| User profile, cached lists | Drift (SQLite) — *planned; nothing needs it yet* |
| Non-sensitive flags (theme, last tab) | `shared_preferences` |
| Queued attendance events | `shared_preferences` — one JSON blob under `attendance.offline_queue.v1` |
| Queued selfies | app-private documents dir, `attendance-offline-selfies/{uuid}.jpg` |
| Live selfie (before submit) | app-private temp dir, deleted on success or discard |

**Never** store a password or token in `shared_preferences` — it is written as plain
XML/plist on disk.

**As implemented in Phase 3:** `core/storage/token_store.dart` wraps
`FlutterSecureStorage` behind a `TokenStore` interface — one fixed key, so
signing in overwrites rather than accumulates. Being an interface is what lets
tests use an in-memory map: `flutter_secure_storage` talks to the platform
keychain over a method channel, which has nothing to say inside a widget test.
`core/storage/device_identity.dart` writes a random id **once** per install and
reuses it forever, which is what makes "one token per device" hold across two
handsets without either carrying anything about the account.

---

## 9. GoRouter = declarative navigation

`mobile/lib/core/router/app_router.dart`:

```dart
final routerProvider = Provider<GoRouter>((ref) {
  // GoRouter re-runs its redirect whenever this notifies.
  final authChanged = ValueNotifier<int>(0);

  ref.listen(authControllerProvider, (_, _) => authChanged.value++);

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: authChanged,      // ← the guard's trigger
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final location = state.matchedLocation;

      // Answer not known yet — hold on the splash rather than guessing.
      if (auth.status == AuthStatus.restoring) {
        return location == '/' ? null : '/';
      }
      if (auth.isAuthenticated) {
        return (location == '/' || location == '/login') ? '/home' : null;
      }
      return location == '/login' ? null : '/login';
    },
    routes: [
      GoRoute(path: '/',      builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/home',  builder: (_, _) => const HomeScreen()),
      // … and, since Phase 4, the module routes in the table below.
    ],
  );

  ref.onDispose(() {
    authChanged.dispose();
    router.dispose();
  });

  return router;
});
```

**What it is:** URL-based routing with a central `redirect` — equivalent to Laravel
route middleware, but for the client.
**Why:** auth guards and role-based redirection live in one place instead of scattered
`Navigator.push` calls.

**Two decisions worth copying:**

1. **Bump a notifier; never rebuild the router.** Recreating `GoRouter` on every
   auth change would dispose the route on screen — and with it whatever the
   user had already typed into the sign-in form. `refreshListenable` re-runs
   `redirect` and touches nothing else.
2. **No screen navigates on login or logout.** The controller writes
   `AuthState`; the redirect notices. That is why `LoginScreen._submit()` has
   nothing to do after `await login(...)` — success moves the user, failure is
   already in the state the form draws.

**Three statuses, three behaviours:** `restoring` holds everything on `/`;
`authenticated` is pushed off the landing pages to `/home`; everything else
(`unauthenticated`, `authenticating`, `failed`) is on `/login`. Treating
`authenticating` as "not signed in" is what keeps the form from flickering to
a dashboard and back while the request is in flight.

**Role-based menus:** `HomeScreen` builds its tiles from `permissionScopeProvider`
rather than from raw `authState.permissions`, so every screen asks one object the
same question (`scope.canViewProjects`) instead of spelling the permission string
afresh at each call site. Hiding a tile is *convenience* — Laravel still enforces
access, and a hand-typed location lands on a screen that draws a lock rather than
a roster.

**Phase 4 route table:**

| Path | Screen | Notes |
|---|---|---|
| `/employees` · `/employees/new` · `/employees/:id` · `/employees/:id/edit` | list · form · detail · form | `/new` is declared **before** `:id` |
| `/departments` · `/departments/new` · `/departments/:id` | list · form | |
| `/designations` · `/designations/new` · `/designations/:id` | list · form | |
| `/projects` · `/projects/new` · `/projects/:id` · `/projects/:id/edit` | list · form · detail · form | |
| `/sites` · `/sites/new` · `/sites/:id` · `/sites/:id/edit` | list · form · detail · form | |

`/new` is declared before `:id` because GoRouter matches in declaration order,
and `:id` carries the regex `(\d+)` so `/employees/new` is never read as an id.
Detail routes parse `int.parse(state.pathParameters['id']!)`; forms take the id
as a nullable `int?`, `null` meaning create.

**Phase 6 route table (15 routes):**

| Path | Screen | Notes |
|---|---|---|
| `/leave` · `/leave/new` | list · form | the list draws `NoPermission` without `leave.view` and never builds the controller |
| `/leave/:id` · `/leave/:id/edit` | detail · form | detail carries the approval chain, the certificate block and the four transitions |
| `/leave-balances` | list | read-only; entry point from the leave list's app bar |
| `/holidays` · `/holidays/new` · `/holidays/:id/edit` | list · form | **no `/holidays/:id` detail route** — the calendar *is* the detail. There is also no delete route to match the API: a day is retired with `status=inactive` |
| `/timesheets` · `/timesheets/:id` | list · detail | read + generate only; there is nothing to edit or approve |
| `/overtime` · `/overtime/new` | list · form | |
| `/overtime/:id` · `/overtime/:id/edit` | detail · form | detail mirrors leave, plus the *minutes to allow* field on approve |

Ordering rules from Phase 4 still apply: `/leave/new` before `/leave/:id`,
`/leave-balances` declared where it cannot be read as `/leave/:id`, and
`timesheets/generate` (backend) registered before `timesheets/{timesheet}` —
GoRouter and Laravel both match in declaration order, and the bug on either
side is "your new page 404s", which is easy to misread as a permission
problem.

**Screens navigate with `context.go(...)`, not `context.pop()`.** After a
save, a detail screen must re-run `initState` to fetch what was just written;
`pop()` would return to a stale detail still holding the pre-edit model.
`pop()` stays where nothing was persisted.

---

## 10. Offline queue design

**As built in Phase 5 ✅** — `features/attendance/data/offline_queue.dart`:

```
Check-in happens with no signal
        ↓
Compress selfie → app-private documents dir
attendance-offline-selfies/{clientEventId}.jpg
        ↓
POST /attendance/check-in  (the request is attempted, not parked)
        ↓
network failure (statusCode == 0)
   ├─ any other failure (403 / 409 / 422 / 429) → NOT queued — it is shown,
   │  because retrying will not turn a refusal into an acceptance
   └─ only a transport failure queues:
        OfflineEvent {
          clientEventId,        ← minted BEFORE the first attempt
          action,               check_in | check_out | start_visit
          siteId, purpose, remarks,
          latitude, longitude, accuracy,
          capturedAt,           ← device clock, for the person to read
          deviceReference,
          selfiePath,           ← local file, not bytes in the blob
          syncStatus: pending_sync,
          attempts, lastError
        }
        ↓
UI shows the queued events with a "Sync now" action
        ↓
Manual replay, oldest first
        ↓
Laravel re-validates everything as if live: assignment, geofence,
accuracy ceiling, duplicate-day, open-row
   ├─ accepted → 201/200 with the row → removed from the queue
   ├─ rejected → 4xx with a message → syncStatus = 'failed', kept for reading
   └─ 0 / 401 → stop; the rest stay 'pending_sync'
```

**Why preferences and not Drift.** The queue is a *list of outbound
messages*, not a set of relations: each event is self-contained, nothing
joins to anything, and it disappears once sent. The duplicate-prevention
unique constraint lives **on the server** (`client_event_id`), which is
where it has to live — a local constraint only stops a device from
duplicating itself, not two devices or a lost reply. A whole SQL engine for
a list that is emptied daily is a dependency with no work to do.

`OfflineQueueStore` is an interface, so a later module that *is* relational
(timesheets, payroll runs) can put a Drift implementation behind the same
name and nothing above it changes.

**Two properties the tests hold:**

1. **A replay is byte-identical in identity.** Retrying re-sends the *same*
   `clientEventId` — never a fresh one — so the server can recognise it as
   the same event rather than as a second day.
2. **A refusal does not accumulate.** A rejected replay marks `failed` and
   keeps the event and its selfie, so the reason can be read and the person
   can decide, instead of an endlessly retrying loop.

---

## 11. Permissions in the UI

```dart
// Phase 4 — one object answers every "may they?" question.
final scope = ref.watch(permissionScopeProvider);

if (scope.canManageSites) ...[ /* HR-only controls */ ],

// A screen that may show the list also *loads* it — watch the flag, not the
// raw list, so a session without the permission never fires the request:
final canView = ref.watch(
  permissionScopeProvider.select((s) => s.canViewEmployees),
);
```

`PermissionScope` (`core/permissions/permission_scope.dart`) exposes `can`,
`canAny`, `canAll` plus the named getters the screens actually use
(`canViewEmployees`, `canCreateEmployees`, `canViewSalary`, …). Phase 6 added
the timekeeping half: `canViewLeaveRequests`, `canCreateLeaveRequests`,
`canApproveLeaveRequests`, `canViewLeaveBalances`, `canManageHolidays`,
`canViewTimesheets`, `canManageTimesheets`, `canViewOvertime`,
`canCreateOvertime`, `canApproveOvertime` — every one of them a **getter over
the same list**, so a permission renamed server-side breaks one line here and
shows up as a single failing test rather than as scattered string literals.

Four properties it is built to hold, each of them under test:

1. **Fails closed.** A session with no permissions denies everything, so a
   partially-restored app draws nothing rather than everything.
2. **Salary is not a subset of the roster.** `employees.view` never implies
   `employees.salary.view`; the same two-rule split the API uses
   (`EmployeePolicy::viewSalary` needs *both*) is phrased the same way here.
   The same is true of `leave.view` vs `leave.balance.view`, and of reading
   the holiday calendar (no permission at all) vs writing one
   (`holidays.manage`).
3. **A misspelling denies.** An unknown permission string reads as denied, not
   as granted — the failure mode of a typo should be an absent button, never
   an exposed one.
4. **Visibility is not authorization.** A drawn button is a courtesy; the
   server's answer is the rule. Phase 6 tests this in both directions: a
   session without `leave.view` draws a lock, and a session *with* it that
   points at someone else's request is still refused by the row scope.

**Remember:** this only controls *visibility*. The API enforces the real rule and
returns `403` if the app is bypassed — which is why every form and detail screen
has a test that renders that `403` as a refusal rather than as a broken page.

### 11.1 OS permissions (Phase 5 ✅) — a different kind of permission

`PermissionScope` answers *"may this account do this?"*. Android and iOS ask
*"may this app use this hardware?"*. The two are unrelated: an account may be
allowed to check in while the handset refuses to hand over a location. Both
answers have to be drawn, and they are drawn differently.

`LocationStatus` (`features/attendance/data/device_location.dart`) is an
enum with a message attached to each value — the screen never has to invent
one:

| Status | What the screen shows |
|---|---|
| `notDetermined` | Ask, with a plain-language reason **first** — why a geofence needs a fix |
| `denied` | "Location access is off for this app." + a way to request again |
| `permanentlyDenied` | "You chose not to share location." + a button to Settings, plus the pre-filled "Allow only while using the app" guide |
| `restricted` | Platform-managed (parental controls / MDM) — explain, do not offer Settings as if it would help |
| `servicesOff` | The OS granted permission but the **location switch is off**. A separate state, because "you said yes but the radio is off" is a different fix from "you said no" |
| `granted` | A fix with accuracy — usable or not, decided by `LocationFix.isUsable` |

Five rules the implementation holds:

1. **Foreground only.** `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` and
   `CAMERA` are declared; `ACCESS_BACKGROUND_LOCATION` is not. Nothing
   requests a permission the feature does not use.
2. **The reason precedes the dialog.** The system prompt is a yes/no; the
   person needs a sentence explaining what for before it appears.
3. **Advisory, labelled as advisory.** `LocalGeofence.assess()` computes the
   distance so the user learns they are outside the radius *before* taking a
   selfie they would rather not take — and the UI says the server measures
   again. The client never decides; it only warns.
4. **A denial never blocks the app.** The attendance screen explains and
   points at Settings; the rest of the application keeps working.
5. **No crash, no silent loop.** Every status is a state the UI renders;
   there is no code path where a missing permission surfaces as an
   unhandled exception or a request fired into nothing.

Camera permission is the same shape: `CameraStatus` distinguishes
`notDetermined` / `denied` / `permanentlyDenied` / `unavailable` (no
front-facing camera, or the simulator has none) / `ready`, each with its own
message and its own way forward — including "no camera" being an honest
answer rather than a black preview.

**Phase 6 widened the abstraction without widening the API.** The camera now
serves two jobs — a selfie and a medical certificate — so the implementation
moved to `core/data/device_camera.dart` and gained one `lens` parameter
(`CameraLens.front` by default, `CameraLens.back` for documents). Two named
providers (`selfieCameraProvider`, `documentCameraProvider`) and one shared
`CameraCaptureSheet` keep both call sites honest, while `SelfieCaptureSheet`
still exists as a thin wrapper so Phase 5's screens and tests are untouched.
One flow, two lenses, and no second camera package.

### 11.2 Module permission gates (Phase 6 ✅) — the list that never asks

An OS permission is about hardware; a module gate is about the *list request*.
Phase 4's rule was "a list you may not open is never fetched", but it was
applied inside each screen — which means the `Notifier` was already built and
the provider already mounted by the time the guard ran.

Phase 6 moved the guard **outward**:

```dart
class LeaveListScreen extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scope = ref.watch(permissionScopeProvider);
    if (!scope.canViewLeaveRequests) return const NoPermission(module: 'Leave');
    return const _LeaveList();   // ← the only place the controller is built
  }
}
```

`NoPermission` (shared, `core/presentation/no_permission.dart`, key
`no-permission`) draws a lock rather than a red error, and the important part
is the boundary: because `_LeaveList` is the `ConsumerWidget` that watches
`listControllerProvider`, that provider is **never constructed** for a session
without the permission. A test asserting `listControllerProvider` was never
built is therefore stronger than one asserting the widget says "no access" —
the first proves no request was contemplated, the second only that it was
politely declined.

Leave, timesheets and overtime all gate this way. **Holidays deliberately
does not**: `HolidayPolicy::viewAny()` is unconditionally true on the server,
so a lock there would be the app inventing a rule the API does not have —
and would hide the calendar from exactly the people who need to plan around it.

---

## 12. Project structure

**As built after Phase 4:**

```
mobile/lib/
├── core/
│   ├── config/app_config.dart          # base URL from --dart-define
│   ├── data/page_result.dart           # PagedList<T> envelope → items/meta/has_next
│   ├── network/api_client.dart         # Dio + bearer interceptor + ApiEnvelope
│   ├── network/api_exception.dart      # ApiException + envelope parsing
│   ├── permissions/permission_scope.dart  # may()/can() — one place that answers
│   ├── data/
│   │   ├── page_result.dart               # (see above) PagedList<T> envelope
│   │   └── device_camera.dart             # ← Phase 6: front/back lens, the ONE camera seam
│   ├── presentation/
│   │   ├── paged_list_view.dart           # spinner / empty / error+retry / rows / banner
│   │   ├── list_state.dart                # PagedListController<T>, generation guard
│   │   ├── form_controls.dart             # LabeledTextField, StatusField, FormBanner
│   │   ├── fields.dart                    # DateField, StatusFilter, SectionCard
│   │   ├── remote_picker.dart             # debounced searchable option sheet
│   │   ├── status_chip.dart               # ← Phase 6: StatusChip + StatusTone, every module
│   │   ├── no_permission.dart             # ← Phase 6: the lock a list draws instead of fetching
│   │   └── camera_capture_sheet.dart      # ← Phase 6: shared capture → preview → retake flow
│   ├── data/approval_step.dart            # ← Phase 6: one link of an approval chain
│   ├── router/app_router.dart          # GoRouter + refreshListenable guard
│   └── storage/
│       ├── token_store.dart            # flutter_secure_storage (TokenStore)
│       └── device_identity.dart        # stable per-install device name
│
├── features/
│   ├── auth/                           # deliberately flat — five files
│   │   ├── auth_models.dart            # AuthUser, EmployeeBrief, LoginResult
│   │   ├── auth_repository.dart        # AuthRepository (abstract) + Api impl
│   │   ├── auth_state.dart             # AuthStatus + AuthState
│   │   ├── auth_controller.dart        # Notifier<AuthState>
│   │   ├── login_screen.dart
│   │   └── splash_screen.dart
│   ├── home/home_screen.dart           # permission-gated module tiles
│   │
│   │   └── each of the nine below has the same three layers:
│   ├── employees/   ├── departments/   ├── designations/
│   ├── projects/    ├── sites/         ├── attendance/
│   ├── leave/       ├── timesheet/     ├── overtime/      ├── holidays/
│   │
│   └── <feature>/
│       ├── data/
│       │   ├── api_<feature>_repository.dart  # the Dio implementation
│       │   └── <feature>_providers.dart       # repository + list/picker providers
│       ├── domain/
│       │   ├── <feature>.dart                 # the model + fromJson + summary
│       │   └── <feature>_repository.dart      # the contract the fake implements
│       └── presentation/
│           ├── <feature>_list_screen.dart
│           ├── <feature>_detail_screen.dart   # (employees / projects / sites / leave / overtime)
│           ├── <feature>_form_screen.dart
│           └── <feature>_controller.dart      # PagedListController<T>
│
├── app.dart                            # MaterialApp.router
└── main.dart                           # ProviderScope + runApp
```

**Why `domain/` exists as its own layer:** it holds a *contract*, so a widget test
can substitute a scripted repository that records what the screen asked for
(`listCalls`, `lastQuery`, `lastBody`) without the screen knowing it is being
watched. The model lives there too — `Employee.fromJson` reading a `full_name`
from a nested `EmployeeResource`, `Site.fromJson` parsing decimals that arrive
as **strings** — which is what makes `page_result_test.dart` able to prove the
parsing without a socket.

`auth/` stays flat because five files do not need four directories. The nested
shape starts paying for itself exactly when a feature grows local sources of
its own — which the Phase 4 modules did, and which `attendance/` did in Phase
5: it is the first feature with sources that are not repositories
(`device_location.dart`, `offline_queue.dart`,
`local_geofence.dart`), and each of those is a seam a test replaces with a
fake instead of with a permission it does not have. **Phase 6 added a second
kind of seam, this time shared**: a camera used by two features is not a
feature's private source, so it moved out of `attendance/` into
`core/data/device_camera.dart` — and `features/attendance/data/selfie_camera.dart`
was deleted rather than left behind as a stale import.

---

## 13. Running the app

```bash
cd mobile
flutter pub get          # install dependencies (like composer install)
flutter analyze          # static analysis — must be clean
flutter test             # run tests
flutter run              # build + install on a connected device/emulator
```

**Pointing the app at a backend:**

```bash
# Default — Android emulator reaching the host machine:
#   http://10.0.2.2:8000/api/v1
flutter run

# Physical device, or a backend on another host:
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1
```

`dart-define` values are baked into the binary at build time. There is no
config file on disk to read, leak, or edit after the app ships — and no runtime
setting that could repoint a device at an attacker's server.

Start the API first:

```bash
cd backend
php artisan serve        # http://127.0.0.1:8000
```

**Useful:**
```bash
flutter devices                 # list connected devices
flutter run --debug             # debug build (hot reload)
flutter build apk --release     # build a release APK
flutter build appbundle         # build an AAB for Play Store
```

---

## 14. Commands reference

| Task | Command |
|---|---|
| Add a package | `flutter pub add <package>` |
| Remove a package | `flutter pub remove <package>` |
| Upgrade packages | `flutter pub upgrade` |
| Format code | `dart format .` (from `mobile/`) |
| Analyze | `flutter analyze` |
| Test coverage | `flutter test --coverage` |
| Clean build | `flutter clean` (use when builds behave oddly) |

> **Format from `mobile/`, not `mobile/lib`.** `dart format .` covers the
> tests too, and `flutter analyze` will happily pass on files a formatter
> would rewrite. The gate before a commit is all three in order:
> `dart format .`, `flutter analyze`, `flutter test`.

---

## 15. Common mistakes to avoid

| ❌ Don't | ✅ Do instead |
|---|---|
| Call Dio directly inside a `build()` method | Call a Repository via a provider |
| Validate geofence only in Flutter | Always let Laravel make the final decision — the phone *advises*, the request carries coordinates, the server decides |
| Store tokens in `shared_preferences` | Use `flutter_secure_storage` |
| Show raw exceptions to users | Map to friendly messages, log the details |
| Skip loading/error/empty states | Use the shared widgets from `core/widgets/` |
| Hard-code site coordinates or radii | Read them from the API — `site.geofence_radius` and `max_gps_accuracy_metres` arrive already defaulted |
| Rebuild everything for a small change | Watch only the specific provider you need |
| Mint a new `clientEventId` on a retry | Re-send the one that was generated before the first attempt, or the server sees a second event |
| Queue a 403/409/422 | Queue only a transport failure; a refusal will not become an acceptance by being asked again |
| Convert a server timestamp to local time for display | Slice it (`'HH:mm'.substring(0, 5)` on the wall-clock string the server sent) — the day the server assigned and the time it recorded must not be shifted by the phone's zone |
| Dispose a `TextEditingController` while its dialog is still animating out | Let the dialog own its controllers and dispose them itself |
| Assume `flutter test` can rasterise a camera frame | Script the camera to return real bytes (a 1×1 PNG) and assert on the logic |
| `const AppBar(title: Text('…'))` | Write `appBar: AppBar(title: const Text('…'))` — in this Flutter version `AppBar` is **not** const-constructible and `const_with_non_const` fires on the whole expression |
| Call `find(id)` inside a `Scripted*` test double | Call `super.find(id)` — bare `find` resolves to flutter_test's `find` getter and fails as `invocation_of_non_function` |
| Name a scripted field after its method (`types` / `balances`) | Rename the field (`leaveTypes` / `balanceRows`) — a field cannot shadow a method of the same name |
| Put two identical rows in a list fixture | Make them distinct, or scope the assertion (`find.text('Pending').last`, `find.widgetWithText(FilledButton, 'OK').last`) — otherwise `findsOneWidget` fails with "too many" for a reason unrelated to the code |
| Blank a query parameter to clear a filter | Delete the key — `status=` matches the empty string and returns nothing, while an absent `status` means "every status". The tests assert this with `expectQuery(..., absent)` |
| Pop() back to a detail screen after editing it | `context.go(...)` so the detail re-runs `initState` and re-fetches what was just written |

---

## 16. Implementation Status

| Item | Status |
|---|---|
| This guide (concepts + patterns) | ✅ Written |
| Flutter SDK install (on `F:`) | ✅ Phase 1b |
| Flutter project skeleton | ✅ Phase 1b |
| Packages: `flutter_riverpod` 3.4.3 · `dio` 5.11.1 · `go_router` 18.0.1 · `flutter_secure_storage` 11.2.0 | ✅ Phase 3 |
| Packages: `geolocator` ^14.1.0 · `camera` ^0.12.1 · `image` ^4.10.1 · `shared_preferences` ^2.5.5 · `path_provider` ^2.1.6 | ✅ Phase 5 — five additions, each checked for Dart 3.13 / Flutter 3.47 compatibility before it was added |
| Packages deliberately **not** added | ✅ Phase 5 — `connectivity_plus` (the queue learns a failure is transport-level from the failed request itself; a connectivity plugin would report "online" at a captive portal) and `permission_handler` (it drags in platform channels the two permissions we need do not require — `geolocator` and `camera` already surface their own statuses) · ✅ Phase 6 — `file_picker` (the certificate is captured with the back camera rather than chosen from disk, so the one package the feature would have needed is not added) and `intl` (the app formats its own dates) |
| `core/` — config, network, storage, router | ✅ Phase 3 |
| `core/data` page envelope · `core/permissions` scope · `core/presentation` list + form widgets | ✅ Phase 4 |
| `core/presentation` `StatusChip` · `NoPermission` · `CameraCaptureSheet`; `core/data` `device_camera`; `core/data` `approval_step` | ✅ Phase 6 |
| Auth feature — controller, repository, models, login + splash screens | ✅ Phase 3 |
| Router guard (`refreshListenable`) + session restore | ✅ Phase 3 |
| Module routes + permission-gated home tiles | ✅ Phase 4 |
| Employees / departments / designations / projects / sites — 13 screens | ✅ Phase 4 |
| `features/attendance/` — data · domain · presentation (3 layers) | ✅ Phase 5 |
| Attendance screen — today, site picker, CHECK IN / SITE VISIT / CHECK OUT, loading / disabled / error states | ✅ Phase 5 |
| Location permission flow (6 states incl. GPS-off) + advisory local geofence | ✅ Phase 5 |
| Camera permission flow + capture → preview → retake → compress → submit | ✅ Phase 5 |
| Offline queue (event UUID, `pending_sync`, manual Sync now, `clientEventId` reuse) | ✅ Phase 5 |
| Android manifest — fine/coarse location + camera, **no** background location | ✅ Phase 5 |
| `features/leave` — list · detail (chain, certificate, 4 transitions) · form · balances | ✅ Phase 6 |
| `features/holidays` — calendar list · form, retired-by-status instead of delete | ✅ Phase 6 |
| `features/timesheet` — list · detail, generate action behind `timesheets.manage` | ✅ Phase 6 |
| `features/overtime` — list · detail (with *minutes to allow*) · form | ✅ Phase 6 |
| Back-lens document capture sharing the front-lens selfie flow | ✅ Phase 6 |
| `NoPermission` gate drawn **before** the list controller is built | ✅ Phase 6 |
| `dart format .` | ✅ clean (34 files reflowed in Phase 6) |
| `flutter analyze` | ✅ clean |
| `flutter test` | ✅ **215 passed** |
| Local database (Drift) + relational offline cache | ⬜ Not started — Phase 5 proved the queue does not need it (§10); revisit when a module is genuinely relational |
| Shared widgets under `core/widgets/` | ⬜ The list and form widgets live in `core/presentation/` today; the split is worth it once a second, differently-shaped widget set appears |
