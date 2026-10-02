# Flutter Guide

> **Status:** Phase 11 — the organisation, auth and attendance slices plus
> `leave/`, `holidays/`, `timesheet/`, `overtime/`, `site_reports/`,
> **`payroll/`, `loans/` and `salary_certificates/`** (the ledger, the run
> report and its ladder, salary slips handed to the OS viewer, a loan
> schedule, and a certificate that is asked for, decided and exported), and
> **`expenses/`** (a claim filed against a date, a category, a place and
> a project, receipts from the camera, the same approval engine as leave,
> and money kept as a decimal string from the API to the screen),
> **`documents/` and `onboarding/`** (a file picked from the camera, the
> gallery or the phone's disk and posted as bytes; the server's own answer
> to "how long has this got" drawn rather than derived; the joiner
> checklist and its stages), and now
> **`training/` and `assets/`** (an enrolment ledger read through one list,
> a course catalogue, a certificate the server has already dated, a
> compliance summary, a register of company property and its hand-over log),
> all in the same `data / domain / presentation` shape. `dart format .` clean
> (285 files), `flutter analyze` clean, `flutter test` **654 passed**. This
> guide explains the concepts and patterns the app uses, written for someone
> who knows PHP/Laravel but is new to Flutter/Dart. Sections that were
> written as a plan in earlier phases — the offline queue, the location and
> camera permission flows, the approval chain, the module permission gates —
> are now describing shipped code, and say so.

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

**Phase 7 route table (8 routes):**

| Path | Screen | Notes |
|---|---|---|
| `/site-reports` · `/site-reports/new` | list · form | the form owns a GPS fix and up to six camera photographs |
| `/site-reports/:id` · `/site-reports/:id/edit` | detail · form | |
| `/daily-reports` · `/daily-reports/new` | list · form | repeatable manpower / materials / equipment rows |
| `/daily-reports/:id` · `/daily-reports/:id/edit` | detail · form | |

**Phase 8 route table (10 routes):**

| Path | Screen | Notes |
|---|---|---|
| `/payroll` | list | **two doors on one path**: `payroll.view` draws the ledger, `payroll.summary.view` *alone* draws the totals card and **never builds the rows provider** |
| `/payroll/:id` | detail | the itemised slip plus exactly one ladder step (review / finalize / lock), each behind its own grant |
| `/salary-slips` | list | its own feature, its own permission — it reads `/salary-slips` and never the ledger |
| `/loans` · `/loans/new` | list · form | the form never asks for an employee id; it defaults to you |
| `/loans/:id` · `/loans/:id/edit` | detail · form | detail carries the schedule, submit / cancel while a draft, and decide only for `loans.approve` |
| `/salary-certificates` · `/salary-certificates/new` | list · form | only `purpose` and `request_date` in the body |
| `/salary-certificates/:id` | detail | approve / reject / issue / export, decided by `can_issue` from the server rather than re-derived from `status` |

There is **no `/payroll/:id/edit`** and no delete route for anything in this
family: a row moves one way along the ladder and a correction is a new entry
with its own sign, so the routes that would have offered an edit simply do
not exist. GoRouter will 404 them the same way Laravel 404s an unregistered
path — the absence *is* the rule.

**Phase 9 route table (4 routes):**

| Path | Screen | Notes |
|---|---|---|
| `/expenses` | list | draws `NoPermission` and never builds `expenseListProvider` without `expenses.view`; the claim FAB additionally needs `expenses.create` |
| `/expenses/new` | form | **declared before `/expenses/:id`** — GoRouter matches in declaration order, so the word `new` is read as a literal path instead of arriving at `idOf()` as an id `int.parse` would choke on. Needs `expenses.create`; without it the form draws *"You may not raise an expense claim."* |
| `/expenses/:id` | detail | gates on `canViewExpenses` *before* the fetch (`NoPermission` rather than a spinner that ends in a refusal); carries the chain, the receipts and the seven transitions |
| `/expenses/:id/edit` | form | the same form with `expenseId != null`; `initState` gates its load on `expenses.update`, the same permission its `build` checks, so a claim the session may not correct is never downloaded |

`app_router.dart` now holds **58 `GoRoute` entries** — 54 at `8d0e9c2`, plus
these four.

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
Phase 9 added the expense seven the same way: `canViewExpenses`,
`canCreateExpenses`, `canUpdateExpenses`, `canApproveExpenses`,
`canManageExpenses`, `canViewExpenseReceipts` — and `canWriteExpenses`, the
one **derived** getter (`canCreateExpenses || canUpdateExpenses`), so the
form asks a single question instead of repeating an `||` at both of its
call sites.

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

### 11.3 Three seams the report screens add (Phase 7 ✅)

The report feature needed three things a widget test must be able to reach,
and each was given an abstraction rather than a package:

| Seam | Provider | Why it is a seam |
|---|---|---|
| `ReportPhotoSource` | `reportPhotoSourceProvider` | Draws a photograph by *ids*, never by URL. The app has no public photo URL to draw, so `ReportPhotoSource` fetches the bytes through the authenticated `ApiClient` and hands them to `Image.memory`. This is why **no `Image.network` appears anywhere in the app**: a bare URL would have to be public to be fetchable, and there isn't one |
| `ReportPdfOpener` | `reportPdfOpenerProvider` | Writes the bytes the API returned to a temp file and hands them to the OS viewer. A test replaces it with a fake that records the id, filename and bytes — and can park on a `Completer` so the *loading* state can be asserted, which a double that answers immediately would never render |
| `ReportDraftStore` | `reportDraftStoreProvider` | `SharedPreferencesReportDraftStore` in the app, an in-memory fake in tests. The slot for a create screen is `activity:new` / `daily:new`, written after a 600 ms pause rather than on every keystroke |

**The draft is not a queue.** It exists so a half-written form survives a
tapped Back button; it says *Local draft* on the screen precisely because it
has **not** been sent. It is deleted only after a successful save, only for
the create screen (`widget.reportId == null`) — editing an already-filed
report must not wipe an unrelated local draft — and through **Start over**.
Photographs are deliberately not part of the snapshot: a byte array in
`SharedPreferences` is not a place to put twelve megabytes.

**GPS is owned by the parent, not the widget.** `ReportGpsField` borrows
`locationGatewayProvider` from the attendance feature and hands the fix back
up. The screen decides what a usable fix is, and the submit button asks for a
*new* one: the server does not merge a stored reading (§5 of the API doc), so
`submit()` carries the coordinates as arguments. No background location, no
polling — the reading is taken on demand and held in memory while the form is
open.

**Rows that can be added.** `RepeatableRowsField` is one widget used three
times (manpower, materials, equipment) because those interactions are the
same one; only the column list differs, and it is data. Two rules keep it
honest: cells call `onRowChanged`, because a total derived from the rows and
a deferred draft would otherwise both sit at yesterday's answer while the
person typed; and `errorText` renders only **after the first save attempt**
(`_rowsValidated`), so an untouched form does not scold you for a row you
have not reached yet.

### 11.4 Three things the money screens add (Phase 8 ✅)

| Seam / rule | Where | Why it is one |
|---|---|---|
| `PdfOpener` | `core/presentation/pdf_opener.dart` → `pdfOpenerProvider` | Phase 8 needed "write these bytes somewhere private and hand them to the OS" twice — a payslip and a certificate — and Phase 7's `ReportPdfOpener` already had exactly those three lines. **The file handling was lifted out of the feature and the report opener now delegates to it** (`DeviceReportPdfOpener` takes a `PdfOpener?`), because two implementations of "where does the temp file go" is how you find out they disagree. `openBytes(Uint8List, String)` takes *bytes*, never an id or a URL: the document is rendered for this press and there is nothing to fetch twice |
| `Money` / `MoneyText` | `core/presentation/money.dart` | The only thing in the app allowed to turn a figure into text. It parses the server's decimal **string** into an integer of minor units and never holds a `double`, so the screen, the list column and the PDF agree to the cent — and a screen that hand-writes `"₹ 30,000"` will be wrong the first time `system.currency` changes |
| **A totals-only session never creates a list provider** | `PayrollListController.initialQuery` → `currentPayrollQuery()` | `PagedListController.build()` opens with `Future.microtask(reload)`, so merely *watching* a list provider issues a request. A Management session holding `payroll.summary.view` must be shown totals without being sent a ledger it is not allowed to read — the card builds its own provider, and **the rows provider is never constructed**, which is stronger than a permission check because no request exists to be refused. `payroll_list_screen_test` asserts `listCalls == 0` |

**One ladder, drawn from the server's answer.** `/payroll/:id` offers
review, finalize or lock — never two of them — because the button is chosen
from `PayrollResource.next_action`, and `lock` additionally requires
`can_lock`. The screen does not re-derive state from a status string: a
client that guessed the next step would eventually guess a step the server
has frozen, and the error it got back would read as a bug rather than as the
rule.

**SnackBars queue.** A run shows *Running payroll…* first and the report
second, so the report is behind a four-second banner. `pumpAndSettle` stops
the moment nothing animates and never reaches it — pump a fixed loop of
100 ms steps instead (§5.3 of TESTING).

### 11.5 Three things the document screens add (Phase 10 ✅)

| Seam / rule | Where | Why it is one |
|---|---|---|
| `DocumentFilePicker` | `features/documents/data/document_source.dart` | Three doors — camera, gallery, PDF — behind one interface with two methods (`pickImage` / `pickPdf`). The camera already existed (`documentCameraProvider`, the same `CameraCaptureSheet` the selfie, the sick certificate and the receipts use), so the *new* problem was only "get a file off the phone's disk", which is `file_picker`'s entire job. `FilePicker` is an `abstract final class` with **static** methods — no `.platform` singleton, no `withData` — and `PlatformFile` exposes `name`, `path`, `lengthSync()`, `readAsBytes()`. Because `allowedExtensions` only applies to `FileType.custom`, the extension is repaired **client-side** from `name` before upload rather than trusted from `path`. Keeping it behind an interface means the widget tests never import a platform channel |
| **The expiry label is drawn, never derived** | `features/documents/domain/employee_document.dart` | `expiry_state` and `days_until_expiry` arrive on the row and the label is built from *them*: `Expires in 5 days`, `Expires today`, `Expired 12 days ago`, `No expiry date` — always **words**, always with an icon, never colour alone. A client that recomputed the count from `DateTime.now()` would disagree with the server's nightly scan the first time the two clocks differed, and the disagreement would show as a wrong number on HR's screen rather than as a bug report |
| `ApiClient.putMultipart()` | `core/network/api_client.dart` | The twin of `postMultipart()`. Editing a document is a `PUT` and `Dio` will not put a file in a `post()` body, so the pair now exists — **and multipart bodies drop null fields**, because a cleared date must reach the server as an absence rather than as the string `"null"` |
| **The gate is read before the request** | `DocumentDetailScreen` / `DocumentFormScreen` / `OnboardingDetailScreen` `initState` | House pattern is a `ConsumerStatefulWidget` + `initState` load (no family notifiers), so the permission has to be read *inside* `initState` rather than only in `build` — otherwise a typed URL fires an unauthenticated request before the `NoPermission` card is drawn. All three screens also `didUpdateWidget`-reload when the id changes, because a route push to a different row reuses the widget |

**After a write, the screen shows what the server returned.** Verify,
reject and archive hand the row back; the detail screen uses *that* row and
calls `documentListProvider` / `documentExpiryProvider` `.reload()`, rather
than patching a local copy — a local copy and the list would drift the
moment the two disagreed about what `pending` means.

**`file_url` is a route, not an image.** The detail screen never feeds it to
`Image.network`; it fetches bytes through the repository's own `file(id)` and
hands a PDF to `pdfOpenerProvider.openBytes(...)`, while an image draws from
the bytes that same route returned.

### 11.6 Three things the training and asset screens add (Phase 11 ✅)

| Seam / rule | Where | Why it is one |
|---|---|---|
| `ApiClient.patch(path, {body})` | `core/network/api_client.dart` | Exactly one endpoint in this API needs it — `PATCH /assets/{asset}/status`. Adding a verb is a deliberate act: `put` is an update of a *resource*, `post` is an *action*, and "move this along a state machine" is neither. One caller, one method, no `patch()` that everything drifts toward |
| **Two words for two columns** | `features/assets/presentation/asset_sheets.dart`, `asset_detail_screen.dart` | `status` and `current_condition` are drawn separately, always. An asset is very often both *assigned* and *fair*, and a screen that printed one chip would have to choose — so the detail shows status, condition **and** readiness as three separate words, and the register's filters ship `status` and `condition` as two query parameters rather than one dropdown. The status dropdown **never offers `Assigned`**: it is a projection of an open hand-over, not a choice anybody makes |
| **A withheld figure reads as withheld** | `features/assets/domain/asset.dart`, `asset_detail_screen.dart` | `Asset.costVisible = json.containsKey('purchase_cost')`, decided when the row is parsed rather than when it is drawn. Absent → *Not shown to your role*; present → `Money.format(...)` in the company's currency. The client never sees `null`, so it can never render a missing cost as `AED 0.00` — the lie a `null` would invite |

**The server's answer is drawn, not recomputed** — the same rule the
document expiry label follows, extended to two new answers.
`certificate_expiry_state` (`none` / `valid` / `expiring_soon` / `expired`)
and `days_until_expiry` arrive on the enrolment row, and the expiry chips,
the compliance buckets and the *"Expired 12 days ago"* line are all built
from *them*; a phone that recomputed from `DateTime.now()` would disagree
with the nightly scan whenever the two clocks did. Likewise `days_out` and
`is_overdue` arrive on a hand-over row: the log's *"23 days out"* and its
overdue line are the server's arithmetic, and `spanLabel` only assembles
them (`'2026-09-01 →'` while open, `'2026-09-01 → 2026-09-20'` once back).

**Every mutation goes through a bottom sheet, and a refusal stays on
screen.** `_StatusSheet`, `_AssignSheet`, `_ReturnSheet`, `_CompletionSheet`
and `_CancelSheet` each build their own body, send it through the
repository, and — when the server answers **409** — put the sentence it sent
into the sheet rather than dismissing it. That is the whole reason the
backend refuses states with a sentence instead of a bare code: *"That
laptop is with Ada Lovelace."* is actionable, and a sheet that closed
immediately would have thrown it away.

**A mutation's door is read from the row, not from the URL.** The completion
button is offered when `is_completable` **and** `training.complete` both say
so; cancellation needs `is_editable` **and** `training.update`; *Hand out*
needs `assets.assign` **and** a row whose status is not `assigned`. The
server would refuse the same combinations with a 409, but drawing a button
that is certain to fail teaches people the app is broken.

---

## 12. Project structure

**As built after Phase 4:**

```
mobile/lib/
├── core/
│   ├── config/app_config.dart          # base URL from --dart-define
│   ├── config/client_settings.dart      # ← currency: what the server configured, read not remembered
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
│   │   ├── money.dart                     # ← Phase 8: Money + MoneyText, the only formatter
│   │   ├── pdf_opener.dart                # ← Phase 8: bytes → private temp file → the OS
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
│   │   └── each of the fourteen below has the same three layers:
│   ├── employees/   ├── departments/   ├── designations/
│   ├── projects/    ├── sites/         ├── attendance/
│   ├── leave/       ├── timesheet/     ├── overtime/      ├── holidays/
│   ├── site_reports/ ├── payroll/      ├── loans/         ├── salary_certificates/
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

**`site_reports/` is the first feature with two screens per side of the same
idea** (an activity report is a person's note; a daily report is an official
document), so it carries a little more of its own: `repeatable_rows.dart`
(the one widget used for manpower, materials and equipment),
`report_gps_field.dart`, `report_photos_section.dart`,
`site_report_photo_sheet.dart`, `report_photo_source.dart`,
`report_pdf_opener.dart` and `data/report_draft_store.dart`. Two repositories,
two models and two controllers rather than one of each — the alternative is
a model with a nullable field for every key the other family has, and a
`kind:` switch in every screen.

### 12.1 `features/expenses/` — the folder, the screens, the wiring (Phase 9 ✅)

Ten files under the three layers the tree above draws:

```
mobile/lib/features/expenses/
├── domain/
│   ├── expense.dart                 # the claim: status, draft/chain helpers, decimal-string amount
│   ├── expense_category.dart        # name + the two rules (requires receipt, ceiling)
│   ├── expense_receipt.dart         # metadata only — never bytes, never a storage path
│   └── expense_repository.dart      # the contract, shaped like leave and overtime
├── data/
│   └── api_expense_repository.dart  # expenseRepositoryProvider — the Dio implementation
└── presentation/
    ├── expenses_controller.dart     # expenseListProvider + expenseCategoriesPickerProvider
    ├── expense_list_screen.dart
    ├── expense_detail_screen.dart
    ├── expense_form_screen.dart
    └── expense_receipt_capture_sheet.dart
```

**What each one is for:**

| File | Responsibility |
|---|---|
| `expense.dart` | The claim. `status` and `isDraft` kept apart from the approval chain, `amount` as a decimal **String**, `needsReceipt` / `receiptLabel` / `statusLabel` read off the row rather than re-derived by a screen |
| `expense_category.dart` | The two rules a claim must satisfy (`requires_receipt`, `maximum_amount`) plus `ruleSummary`, the sentence the form prints under the picker |
| `expense_receipt.dart` | Name, MIME, size, `isImage` / `isPdf` — and `canPreview`, because only an image may be drawn inline. **No path, no URL**: the only way back in is the id |
| `expense_repository.dart` | The contract: `list` · `find` · `create` · `update` · `submit` · `approve` · `reject` · `cancel` · `categories` · `addReceipts` · `receipt` · `removeReceipt`. Same shape as leave and overtime on purpose — expenses run through the *same* approval engine. `reject(id, {required String remarks})` is stricter than its siblings because the server answers `422` without a reason, so the rule lives in the type instead of being rediscovered as a red field. Seven named transition methods, no stringly-typed `act(action)` |
| `api_expense_repository.dart` | `expenseRepositoryProvider` over `ApiClient`. `categories()` returns a plain `List<ExpenseCategory>` because `GET /expense-categories` is not paginated; `addReceipts` posts **one** multipart key `receipts` carrying a `List<MultipartFile>` (dio's `ListFormat.multi` → a PHP array), filenames `receipt-1.jpg`, `receipt-2.jpg`, … |
| `expenses_controller.dart` | `expenseListProvider` (a `PagedListController` whose filters are *query parameters* — the server owns the scoping and the totals) and `expenseCategoriesPickerProvider`, whose `fetch` wraps the unpaginated list into a single-page `PageResult` so a "Load more" tile can never appear under four rows |
| `expense_list_screen.dart` | The list: `NoPermission` before the controller is built without `expenses.view`, a status filter, the claim FAB behind `expenses.create`, and rows drawn with `Money.format` |
| `expense_detail_screen.dart` | One claim: the amount, the chain, the receipts and every action, each gated on the permission **and** the state — approve / reject only for `canApproveExpenses && isAwaitingDecision`, edit and submit only while draft, cancel only while `isOpen && !canApprove`, receipts added and removed only while draft — and every transition followed by a re-fetch from the server |
| `expense_form_screen.dart` | Create (`expenses.create`) or correct a draft (`expenses.update`). Deliberately carries **no** status, approval step or employee field, and draws the server's `errors` under the matching inputs |
| `expense_receipt_capture_sheet.dart` | The shared `CameraCaptureSheet` in this feature's words: back camera, preview, retake, and the description says out loud who will see the slip. Returns the raw JPEG bytes — or `null` if the person backed out |

**Wiring around it:**

- **The home door** — `features/home/home_screen.dart` gains **Expenses**:
  `Icons.receipt_long_outlined`, label `Expenses`, subtitle *Claims, receipts
  and approvals*, path `/expenses`, gated on `expenses.view`, placed after
  Overtime — a different question from overtime's: not "how long did you
  work?" but "what did you spend, and who signed it off?".
- **Four routes** — `/expenses`, `/expenses/new`, `/expenses/:id`,
  `/expenses/:id/edit`, with `/expenses/new` declared **before**
  `/expenses/:id` (§9).
- **`RemotePickerField` grows an optional `helper`** —
  `core/presentation/remote_picker.dart` renders it as `helperText` **only
  when `errorText == null`**, so a hint never competes with a refusal for the
  two lines under an input.
- **`core/data/approval_step.dart` fixes a name it was always missing** —
  `_personName` now reads `full_name` first and falls back to `name`.
  `resolved_approver` arrives as an `EmployeeBriefResource`, which spells it
  `full_name`, so every resolved approver in leave, overtime *and* expenses
  previously fell through to the generic phrase. One line, three modules.
- **Receipt capture adds no package** — the sheet runs the existing
  `CameraCaptureSheet` through `documentCameraProvider` (the same back lens
  the sick certificate uses). No `file_picker`, no second camera package.
- **Receipts are read by id, never by URL** — `receipt()` returns bytes
  through the policy-checked route; images go to an `Image.memory` dialog and
  PDFs to `pdfOpenerProvider`, which Phase 8 lifted into `core/`.

**The harness these tests stand on:** `test/support/phase9.dart` joins
`fakes.dart`, `phase4.dart`, `attendance.dart`, `phase6.dart`,
`site_reports.dart` and `phase8.dart` — `ScriptedExpenses` (records
`lastTransition` / `lastRemarks` / `lastCreated`, the receipt calls and
`categoryRows`; the lifecycle verbs are recorded rather than modelled, and
receipts are the exception because receipt *state* is what two of the tests
are about), `scopedPhase9(...)` with exactly the permissions under test,
`phase9Router(...)`, the fixtures `expenseRow` / `expenseCategory` /
`expenseReceipt`, and re-exports of `advance`, `useTallScreen`,
`forbidden403`, `notFound404` and `unreachable` from `phase4` so a Phase 9
test needs one import. Reading a receipt resolves to a **real 1×1 PNG**, so
the dialog's `Image.memory` decodes instead of failing for a reason unrelated
to the flow under test.

**The keys those tests drive** — each a `ValueKey` on the widget itself, so a
finder names the control rather than hunting for its label:
`expense-status-filter`, `claim-expense`,
`expense-row-{id}`, `receipt-{id}`, `view-receipt-{id}`, `remove-receipt-{id}`,
`add-receipt`, `submit-expense`, `edit-expense`, `approve-expense`,
`reject-expense`, `cancel-expense`, `expense-banner`, `expense-loading`,
`expense-error`, `expense-form-banner`, `expense-date`, `expense-category`,
`expense-category-rules`, `expense-amount`, `expense-currency`,
`expense-description`, `expense-site`, `expense-project`, `save-expense`,
`receipt-required`.

### 12.2 Money, category rules and the validation boundary (Phase 9 ✅)

**Money is a decimal `String` from the API to the eye.** `Expense.amount`
is `DECIMAL(12,2)` on the server and arrives as text; `Money.format(value,
currency:)` is the only printer, and the **domain layer never imports
presentation** — `expense.dart` knows nothing about `money.dart`. The form
field is a `LabeledTextField` whose `controller.text` is sent on as
`'amount': _amount.text.trim()`, not a `double` form field:

| Aspect | Text field holding the string | A `double` field (`double.parse`, `toStringAsFixed`) |
|---|---|---|
| What is stored | exactly what the person typed, and exactly what the server sent | a binary approximation of it |
| Rounding | none — the server already settled the figure | re-rounds on the way in *and* out |
| Two screens showing one claim | always agree to the cent | disagree the first time a `.005` lands or `system.currency` changes |

**The category rules are a line of text derived from the row, never a rule in
the form.** `_selectedCategory` reads the picker's row by id and falls back to
the claim's own copy of the category (so a draft opened on a slow connection
still says *Needs a receipt*), and `ruleSummary` is printed under the picker
under the key `expense-category-rules` only when it is non-empty. Nothing in
the form knows what `requires_receipt` or `maximum_amount` *means*: the API
re-reads both on create, update and submit, so a ceiling changed on the server
tonight is what tomorrow's claim is checked against — a hard-coded copy would
be a rule that stops being true the day the backend changes it.

**The form does not mirror the server's validation** — the house convention
`fields.dart` exists for. On save:

- `failure.isValidation` (422) → the envelope's `errors` map is dropped onto
  the matching field's `errorText` (`expense_date`,
  `expense_category_id`, `amount`, `currency`, `description`, `site_id`,
  `project_id`) — one line under the input, in the server's own words;
- anything else → the banner under `expense-form-banner`, with the API's
  message when it has one;
- a `403` additionally sets `_forbidden`, which disables **Save**: retrying a
  refusal the session cannot lift would only earn a second one.

And the gate that precedes all of it runs **twice on purpose**: the form's
`initState` fetches a draft only under `canUpdateExpenses`, the same
permission its `build` checks — so a claim the session may not edit is never
downloaded first and refused a moment later.

### 12.3 The currency comes from the server (currency configuration ✅)

The form used to start the `currency` box at a value somebody had typed into
the source. That is a configuration item wearing a code costume — it survives
a company moving country and every claim it produces is wrong in a way the
person filing it had no way to notice. `core/config/client_settings.dart`
replaces the constant with a read.

| Aspect | Design |
|---|---|
| What is fetched | `GET /client-settings` → `{default_currency, supported_currencies}`. Two values, allow-listed on the server, so this route cannot leak anything else |
| When | once, for a **new** claim only. An **edit never fetches it** — the draft's own `currency` overwrites the box, so a record filed in another code keeps it and a narrowed setting cannot trap somebody out of correcting their own claim |
| One code configured | `_currencyEditable` is false: the value is shown and closed. A field that offers a choice between one option is an invitation to type over it |
| Two or more | the box opens, prefilled with the default, and the helper names the accepted codes — the server's list, not the app's |
| Fetch fails | `_settingsFailed` opens the box rather than blocking the form: an unreachable settings call is not the same statement as "this company accepts nothing", and `StoreExpenseRequest` decides anyway. While nothing has arrived, `_awaitingCurrency` disables **Save**, so a claim cannot be submitted with a box the configuration has not filled in yet |
| Conversion | none, anywhere. `currency` travels as a three-letter code and `Money.format(value, currency:)` prints it — no figure is ever re-priced from one code into another |

The source is an interface (`ClientSettingsSource`) for the reason every
repository here is one: `ScriptedClientSettings` in `test/support/phase9.dart`
answers with a fixture, so a widget test never stands a server up to ask what
currency it should offer.

### 12.4 `features/documents/` and `features/onboarding/` — the folders, the screens, the wiring (Phase 10 ✅)

```
features/documents/                      # 11 files
├── domain/      # EmployeeDocument · DocumentType · DocumentRepository
│                #   ← no Flutter import, no JSON, no HTTP
├── data/        # ApiDocumentRepository + DocumentFilePicker (the one file-picking seam)
│                #   ← the only place that knows the envelope and the bytes
└── presentation/# documents_controller · list · detail · form · expiry
                 #   + DocumentSourceSheet (camera | gallery | PDF, one sheet)

features/onboarding/                     # 6 files
├── domain/      # OnboardingRecord · OnboardingChecklistItem · OnboardingRepository
├── data/        # ApiOnboardingRepository
└── presentation/# onboarding_controller · list · detail
```

**The list has two doors, not one.** `documentListProvider` serves the
directory and `documentExpiryProvider` serves the report; they are separate
`PagedListController`s because they are different questions with different
grants (`documents.view` vs `documents.expiry.view`) and different query
strings. The screen-local *expiry* dropdown is **not** a filter the client
applies — `DocumentListController.fetch()` translates it into the two
booleans the API reads (`expired=1`, `expiring_soon=1`) and sends them, so
the narrowing happens in SQL where the server's own warning window is.

**The form is told what to ask for by the type.** `DocumentFormScreen` takes
an optional `documentId`, `employeeId` and `typeCode`; `_applyTypeCode()`
matches a requirement's code to a document type **by code** and fills the
three `requires_*` flags, then clears the number / issue / expiry errors.
Changing the type clears them again, because the fields it demands are a
property of the type and not of what was typed a moment ago. The file is
required on create and replaceable on edit — an edit with no new file keeps
the one already stored.

**The checklist can hand work to the upload form.** An outstanding
`document` requirement with `documents.create` draws an **Attach…** tile
that pushes `/documents/new` with `extra: {typeCode, employeeId}` — one
screen filling in another's first page, rather than the user retyping both.

**Both features are behind `permissionScopeProvider` at `initState`.** See
§11.5: the coarse gate is read before the first request, so a typed URL
never produces a `401` flash, and `didUpdateWidget` reloads when the id in
the route changes.

### 12.5 `features/training/` and `features/assets/` — the folders, the screens, the wiring (Phase 11 ✅)

```
features/training/                        # 15 files
├── domain/      # EmployeeTraining · TrainingProgram · TrainingCompliance
│                #   · TrainingType · TrainingRepository
│                #   ← no Flutter import, no JSON, no HTTP
├── data/        # ApiTrainingRepository
│                #   ← the only place that knows the envelope, and the one
│                #     multipart `file` the completion sheet can attach
└── presentation/# training_controller · list · detail · enroll form ·
                 #   expiry report · compliance · programs · program form
                 #   + training_sheets (complete · cancel)

features/assets/                          # 11 files
├── domain/      # Asset · AssetAssignment · AssetType · AssetRepository
├── data/        # ApiAssetRepository
└── presentation/# asset_controller · list · detail · form · history
                 #   + asset_sheets (hand out · take back · change status)
```

**One list screen, two lists.** `trainingListProvider` is the enrolment
ledger and `trainingProgramsProvider` is the catalogue; they are separate
`PagedListController`s because they are different questions with different
grants (`training.assign` to book somebody, `training.create` to add a
course) and different query strings. `assetListProvider` and
`assetHistoryProvider` are the same shape — and **the log opens on
`{'status': 'active'}`, not on everything**, because the question a
hand-over log is asked first is always "what is out right now?"; narrowing
to `Everything` is one tap away and travels as a query parameter.

**The screen-local certificate selector is not a client-side slice.** The
three-way *All / Certified / Expiring* dropdown on the training list is
translated by `TrainingListController.fetch()` into the three booleans the
API reads (`certified`, `expired`, `expiring_soon`) and sent, so the
narrowing happens in SQL next to the server's own warning window — the same
trade the document expiry selector made in §12.4.

**The catalogues are read from the server and never hard-coded.**
`trainingTypesPickerProvider` and `assetTypesPickerProvider` fetch
`GET /training-types` / `GET /asset-types`, whose `data` is a **plain
array**, and both pickers guard their `DropdownButton.value` against a list
that has not arrived yet (an unset `value` in `items` asserts). No screen
contains a list of course kinds or of kinds of property.

**The enrol form and the course form share `_touch(String field)`.** It
clears exactly one field's error when that field changes, so a 422 that
landed on `training_date` disappears when `training_date` is corrected and
not before — the same helper under three different names in
`training_enroll_screen`, `training_program_form` and `asset_form_screen`.

**The certificate travels as bytes and a name, never a path.** The
completion sheet posts `file` through the repository's own multipart call;
`EmployeeTraining` then reports `has_certificate`,
`certificate_original_name`, `certificate_size` and `certificate_file_url`,
and the detail screen fetches the bytes **by id** through
`TrainingRepository.file(int)` — never from `file_url`.

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
| Draw a report photograph with `Image.network(...)` | Fetch the bytes through `ApiClient` via `ReportPhotoSource` — there is no public photo URL, and creating one would break the storage rule the server enforces |
| Re-send the fix you stored when the report was saved | Pass the coordinates as arguments to `submit(...)` — the server does not merge a stored reading, because the field is named for the moment of submission |
| Let a repeatable-row cell update only the local list | Call `onRowChanged` (the screen `setState`s and schedules the draft) — a total derived from the rows and a deferred draft would otherwise both sit stale while the person types |
| Show `errorText` on the repeatable sections from the first frame | Gate it on `_rowsValidated`, set inside the first save attempt — an untouched form must not scold you for a row you have not reached |
| Put the pending photographs into the draft snapshot | Keep them in memory for the session: `SharedPreferences` is not a place for twelve megabytes, and the draft's job is the *words* |
| Assert `find.text('0 rows')` on a screen with three repeatable sections | Look the row up by key (`manpower-count`) — every section renders the same phrase, so a text match counts the neighbours too |
| `pumpAndSettle` over a screen holding a spinner | Drive it with `advance(tester)` (six × 100 ms). `pumpAndSettle` advances 100 ms per iteration and never stops while a `CircularProgressIndicator` runs |
| Print a salary with `double.toStringAsFixed(2)` | Format it with `Money.format()` / `MoneyText`. The API sends a decimal **string**; turning it into a `double` re-rounds a figure the server already settled, and two screens will then disagree about one payslip by a cent |
| Watch a list provider to render a totals card | Give the card its own provider from `currentPayrollQuery()`. `PagedListController.build()` schedules a reload the moment it is built, so the "read-only summary" would have requested a ledger — and no permission check can refuse a request that was never made |
| Guess the next payroll step from `status` | Read `next_action`, and `can_lock` for the lock. The ladder is the server's answer; a client that guesses will eventually guess a step the row has frozen, and the `409` it gets back will look like a bug instead of the rule |
| `pumpAndSettle` to reach the second SnackBar | Pump a fixed loop of 100 ms steps — banners queue, and the first one's four seconds are not animation, so nothing tells `pumpAndSettle` to keep going |

---

## 16. Implementation Status

| Item | Status |
|---|---|
| This guide (concepts + patterns) | ✅ Written |
| Flutter SDK install (on `F:`) | ✅ Phase 1b |
| Flutter project skeleton | ✅ Phase 1b |
| Packages: `flutter_riverpod` 3.4.3 · `dio` 5.11.1 · `go_router` 18.0.1 · `flutter_secure_storage` 11.2.0 | ✅ Phase 3 |
| Packages: `geolocator` ^14.1.0 · `camera` ^0.12.1 · `image` ^4.10.1 · `shared_preferences` ^2.5.5 · `path_provider` ^2.1.6 | ✅ Phase 5 — five additions, each checked for Dart 3.13 / Flutter 3.47 compatibility before it was added |
| Package: `open_filex` ^4.7.0 | ✅ Phase 7 — the only dependency a downloaded PDF needs. The bytes come from the API, land in a temp file, and the system's own viewer opens them; no in-app PDF engine, no rendering of untrusted content |
| Packages deliberately **not** added | ✅ Phase 5 — `connectivity_plus` (the queue learns a failure is transport-level from the failed request itself; a connectivity plugin would report "online" at a captive portal) and `permission_handler` (it drags in platform channels the two permissions we need do not require — `geolocator` and `camera` already surface their own statuses) · ✅ Phase 6 — `file_picker` (**see the row below: Phase 10 added it back**) and `intl` (the app formats its own dates) · ✅ Phase 7 — `image_picker` (the report photographs are taken with the **camera**, through the same `DeviceCamera` the selfie and the certificate already use) |
| Package: `file_picker` **13.1.0** | ✅ Phase 10 — added back, and the **only** package this phase takes. Phase 6 declined it because a sick certificate is captured with the back camera rather than chosen from disk; a passport scan is neither. `FilePicker` is an `abstract final class` with **static** methods (no `.platform` singleton, no `withData`/`allowMultiple`), `allowedExtensions` is only valid with `FileType.custom`, and the extension is repaired client-side from `PlatformFile.name`. It sits behind `DocumentFilePicker`, so no widget test imports a platform channel · ✅ no pre-existing package was upgraded |
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
| `features/site_reports/` — 2 repositories · 4 models · 3 controllers · 8 screens | ✅ Phase 7 |
| Site activity list · detail · form (camera photographs, submit-time GPS, local draft) | ✅ Phase 7 |
| Daily report list · detail · form (manpower/materials/equipment rows, derived total, local draft) | ✅ Phase 7 |
| `RepeatableRowsField` — one widget, three sections, errors only after the first save attempt | ✅ Phase 7 |
| Private photograph fetching through the API (`ReportPhotoSource`, **no `Image.network`**) | ✅ Phase 7 |
| On-demand PDF download (`ReportPdfOpener` → `open_filex`) with loading / 401 / 403 / 422 / transport states | ✅ Phase 7 |
| Local draft store (`ReportDraftStore`, 600 ms debounce, labelled *Local draft*, not a sync queue) | ✅ Phase 7 |
| `reportable-sites` picker — the site list for a role that holds no `sites.view` | ✅ Phase 7 |
| Router: **23 routes** (Phase 7 added 8) · home tiles gated on the two report permissions | ✅ Phase 7 |
| Camera-only capture for report photographs (gallery deferred — `image_picker` deliberately not added) | ✅ Phase 7 |
| `features/payroll/` — repository · model · `PagedListController` + a period window · ledger list · detail with one ladder step · salary-slip list | ✅ Phase 8 |
| `features/loans/` — repository · model · schedule · list · form (no employee-id field) · detail with submit / cancel / decide | ✅ Phase 8 |
| `features/salary_certificates/` — repository · model · list · form (`purpose`, `request_date` only) · detail with approve / reject / issue / export | ✅ Phase 8 |
| `core/presentation/money.dart` — `Money` + `MoneyText`, minor units from a decimal string, half away from zero, no `double`, **`intl` still deliberately not added** | ✅ Phase 8 |
| `core/presentation/pdf_opener.dart` — `PdfOpener` / `DevicePdfOpener` / `safePdfFilename`, lifted out of the report feature so the file handling exists once | ✅ Phase 8 |
| Totals-only session never builds a list provider (`currentPayrollQuery()`) | ✅ Phase 8 |
| Home tiles for the four money doors, each gated on its own permission | ✅ Phase 8 |
| Router: **33 routes** (Phase 8 added 10 — `/payroll`, `/payroll/:id`, `/salary-slips`, four loan paths, three certificate paths) · no edit or delete path exists for a payroll row | ✅ Phase 8 |
| `features/expenses/` — 4 domain files · `expenseRepositoryProvider` · `expenseListProvider` + `expenseCategoriesPickerProvider` · list · detail · form · receipt capture sheet (**10 files**, 3 layers) | ✅ Phase 9 |
| PermissionScope: `canViewExpenses` · `canCreateExpenses` · `canUpdateExpenses` · `canApproveExpenses` · `canManageExpenses` · `canViewExpenseReceipts` (+ derived `canWriteExpenses`) · home door **Expenses** (`Icons.receipt_long_outlined`, `expenses.view`, after Overtime) | ✅ Phase 9 |
| Router: **58 `GoRoute` entries** — Phase 9 adds 4: `/expenses`, `/expenses/new` (**before** `/expenses/:id`), `/expenses/:id`, `/expenses/:id/edit` | ✅ Phase 9 |
| `RemotePickerField.helper` (drawn as `helperText` only while `errorText == null`) · `approval_step._personName` reads `full_name` first — resolved approvers in leave, overtime *and* expenses show a name instead of the generic phrase | ✅ Phase 9 |
| Receipt capture through the existing `CameraCaptureSheet` / `documentCameraProvider` — **no new file-handling package** · one multipart key `receipts` carrying a `List<MultipartFile>` (`ListFormat.multi` → PHP array), filenames `receipt-1.jpg`, `receipt-2.jpg`, … | ✅ Phase 9 |
| Harness `test/support/phase9.dart` — `ScriptedExpenses` · `scopedPhase9(...)` · `phase9Router(...)` · `expenseRow` / `expenseCategory` / `expenseReceipt` · re-exports from `phase4` | ✅ Phase 9 |
| `dart format .` | ✅ clean (8 files reflowed in Phase 7) |
| `flutter analyze` | ✅ clean |
| `flutter test` | ✅ **378 passed** (303 before Phase 8, +74 in `test/features/{payroll,loans,salary_certificates}/` + `money_test` + the route and home tests, +1 for a partly deducted installment) |
| **Phase 9 validation summary** — `dart format .` **clean** (216 files) · `flutter analyze` **clean** · `flutter test` **454 passed** (378 before Phase 9, **+76** in `test/features/expenses/` + `test/features/home/home_phase9_test.dart` + `test/app_phase9_routes_test.dart`) | ✅ Phase 9 |
| **Currency configuration** — `core/config/client_settings.dart` (`ClientSettings` · `ClientSettingsSource` · `clientSettingsProvider`) + the form's `_currencyEditable` / `_currencyHelper` / `_awaitingCurrency`; `ScriptedClientSettings` added to `scopedPhase9` so no test reaches the network for it; `expense.dart`'s parser no longer invents a code when the server omits one | ✅ Post-Phase 9 |
| **Currency configuration validation summary** — `dart format .` **clean** (218 files) · `flutter analyze` **clean** · `flutter test` **465 passed** (**+11**: 7 in `test/core/config/client_settings_test.dart`, 4 in `expense_form_screen_test.dart`) | ✅ Post-Phase 9 |
| `features/documents/` — `EmployeeDocument` · `DocumentType` · `DocumentRepository` · `ApiDocumentRepository` · `DocumentFilePicker` · `documents_controller` + `documentListProvider` / `documentExpiryProvider` · list · detail · form · expiry report · `DocumentSourceSheet` (**11 files**, 3 layers) | ✅ Phase 10 |
| `features/onboarding/` — `OnboardingRecord` / checklist item · `OnboardingRepository` · `ApiOnboardingRepository` · `onboarding_controller` · directory · detail with the checklist (**6 files**, 3 layers) | ✅ Phase 10 |
| PermissionScope: `canViewDocuments` · `canCreateDocuments` · `canUpdateDocuments` · `canVerifyDocuments` · `canDeleteDocuments` · `canManageDocuments` · `canViewDocumentExpiry` · `canViewOnboarding` · `canManageOnboarding` · `canViewBankAccount` (+ derived helpers) · home doors **Documents** (`documents.view`) and **Onboarding** (`onboarding.view`) | ✅ Phase 10 |
| Router: **65 `GoRoute` entries** — Phase 10 adds 7: `/documents`, `/documents/new` and `/documents/expiring` **declared before** `/documents/:id`, `/documents/:id`, `/onboarding`, `/onboarding/:id`, and `documentForm()` reading `state.extra` defensively as a `Map` with `:id` parsed by `int.parse` | ✅ Phase 10 |
| `ApiClient.putMultipart()` — the twin of `postMultipart()`, multipart bodies dropping null fields | ✅ Phase 10 |
| Expiry chips that always carry words and an icon (never colour alone), built from the server's `expiry_state` / `days_until_expiry` | ✅ Phase 10 |
| Private file fetching by id (`DocumentRepository.file(int)` → `ApiClient.bytes`), opened through `pdfOpenerProvider` for a PDF and drawn from bytes for an image — **`file_url` is never passed to `Image.network`** | ✅ Phase 10 |
| Onboarding checklist *Attach…* tile handing `{typeCode, employeeId}` to `/documents/new` | ✅ Phase 10 |
| Harness `test/support/phase10.dart` — `ScriptedDocuments` · `ScriptedOnboarding` · `scopedPhase10(...)` · `phase10Router(...)` · JSON payload builders `documentRow` / `documentTypeRow` / `checklistItem` / `onboardingRow` parsed by `rows()` so a transition merges into the payload · re-exports from `phase4`/`phase9` | ✅ Phase 10 |
| **Phase 10 validation summary** — `dart format .` **clean** (246 files) · `flutter analyze` **clean (No issues found)** · `flutter test` **551 passed** (465 before Phase 10, **+86** across 10 new files) | ✅ Phase 10 |
| `features/training/` — `EmployeeTraining` · `TrainingProgram` · `TrainingCompliance` · `TrainingType` · `TrainingRepository` · `ApiTrainingRepository` · `training_controller` + `trainingListProvider` / `trainingExpiryProvider` / `trainingProgramsProvider` / `trainingProgramsPickerProvider` / `trainingTypesPickerProvider` · list · detail · enrol form · expiry report · compliance · catalogue · course form · `TrainingSheets` (**15 files**, 3 layers) | ✅ Phase 11 |
| `features/assets/` — `Asset` · `AssetAssignment` · `AssetType` · `AssetRepository` · `ApiAssetRepository` · `asset_controller` + `assetListProvider` / `assetHistoryProvider` / `assetTypesPickerProvider` · list · detail · form · hand-over log · `AssetSheets` (**11 files**, 3 layers) | ✅ Phase 11 |
| PermissionScope: `canViewTraining` · `canManageTraining` · `canCreatePrograms` · `canUpdatePrograms` · `canEditEnrolments` · `canAssignTraining` · `canCompleteTraining` · `canViewTrainingCertificates` · `canViewTrainingExpiry` · `canViewAssets` · `canCreateAssets` · `canUpdateAssets` · `canManageAssets` · `canAssignAssets` · `canReturnAssets` · `canViewAssetHistory` (**16 getters**) · home doors **Training** (`Icons.school_outlined`, `training.view`) and **Assets** (`Icons.inventory_2_outlined`, `assets.view`), each drawn on its own grant | ✅ Phase 11 |
| Router: **78 `GoRoute` entries** — Phase 11 adds 13: `/training/expiring`, `/training/compliance`, `/training/programs` and `/training/programs/new` **declared before** `/training/:id`, `/training/programs/:id`; and `/assets/history` and `/assets/:id/edit` **declared before** `/assets/:id`, so `history`, `programs` and `new` are never parsed as row ids | ✅ Phase 11 |
| `ApiClient.patch(path, {body})` — one caller: `PATCH /assets/{asset}/status` | ✅ Phase 11 |
| `Asset.costVisible` decided at parse time from `json.containsKey('purchase_cost')` — a withheld cost draws *Not shown to your role*, never `0.00` | ✅ Phase 11 |
| Certificate expiry and loan duration drawn from the server's own answer (`certificate_expiry_state`, `days_until_expiry`, `days_out`, `is_overdue`) — **never recomputed on the phone** | ✅ Phase 11 |
| Private certificate fetching by id (`TrainingRepository.file(int)` → `ApiClient.bytes`), a PDF handed to `pdfOpenerProvider` — **`certificate_file_url` is a route, not an image** | ✅ Phase 11 |
| Harness `test/support/phase11.dart` — `ScriptedTraining` · `ScriptedAssets` · `scopedPhase11(...)` · `phase11Router(...)` · JSON payload builders `trainingRow` / `programRow` / `trainingTypeRow` / `assetRow` / `assignmentRow` / `assetTypeRow` / `compliancePayload` · per-verb `errors` / `counters` / `last*` records · re-exports from `phase4`/`phase9` | ✅ Phase 11 |
| **Phase 11 validation summary** — `dart format .` **clean** (285 files) · `flutter analyze` **clean (No issues found)** · `flutter test` **654 passed** (551 before Phase 11, **+103** across 12 new files) | ✅ Phase 11 |
| Local database (Drift) + relational offline cache | ⬜ Not started — Phase 5 proved the queue does not need it (§10); revisit when a module is genuinely relational |
| Shared widgets under `core/widgets/` | ⬜ The list and form widgets live in `core/presentation/` today; the split is worth it once a second, differently-shaped widget set appears |
