# Flutter Guide

> **Status:** Phase 1 — Foundation. **Flutter is not installed yet and no Flutter project
> exists yet.** This guide explains the concepts and patterns the app will use, written
> for someone who knows PHP/Laravel but is new to Flutter/Dart.

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
**Where it belongs:** `features/attendance/presentation/attendance_screen.dart`.

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

**Where it belongs:** `features/attendance/data/repositories/attendance_repository.dart`.

**Why this matters for offline:** when you add offline support you change *this class*.
The screen and the provider stay untouched.

---

## 6. Dio + interceptors = the API client

**What it is:** an HTTP client with pluggable middleware (like Laravel middleware, but
for outgoing requests).

```dart
class AuthInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await secureStorage.read(key: 'auth_token');
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      // token expired → attempt refresh or force re-login
    }
    handler.next(err);
  }
}
```

**What it does:**
- attaches the Sanctum token to every request
- catches `401` centrally (one place, not 50 screens)
- normalises `422` validation errors into a map the form can consume
- applies timeouts and retry policy

**Where it belongs:** `core/network/`.

---

## 7. The response envelope — one parser for everything

Laravel returns `{success, message, data, errors}`. Parse it **once**:

```dart
class ApiResult<T> {
  final bool success;
  final String message;
  final T? data;
  final Map<String, List<String>>? errors;
}
```

Every repository returns either a typed `T` or a `Failure`. Screens never see raw JSON.

**Failure types** (`core/errors/`):

| Type | Trigger | User sees |
|---|---|---|
| `NetworkFailure` | no internet / timeout | "No connection. Your record is saved and will sync." |
| `AuthFailure` | 401 | force re-login |
| `PermissionFailure` | 403 | "You don't have access to this." |
| `ValidationFailure` | 422 | field-level messages |
| `RateLimitFailure` | 429 | "Too many attempts. Try again shortly." |
| `ServerFailure` | 500 | generic "Something went wrong" |
| `NotFoundFailure` | 404 | empty / not-found state |

Never show a raw exception to a user. Log it for developers instead.

---

## 8. Secure storage vs preferences

| Data | Storage |
|---|---|
| Auth token | **`flutter_secure_storage`** (Keychain / Keystore) |
| User profile, cached lists | Drift (SQLite) |
| Non-sensitive flags (theme, last tab) | `shared_preferences` |
| Queued attendance + selfies | Drift + app-private directory |

**Never** store a password or token in `shared_preferences` — it is written as plain
XML/plist on disk.

---

## 9. GoRouter = declarative navigation

```dart
final router = GoRouter(
  initialPath: '/login',
  redirect: (context, state) {
    final auth = container.read(authProvider);
    final loggedIn = auth.isAuthenticated;
    final atLogin = state.matchedLocation == '/login';

    if (!loggedIn && !atLogin) return '/login';
    if (loggedIn && atLogin)   return '/dashboard';
    return null;                    // no change
  },
  routes: [
    GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
    GoRoute(path: '/dashboard', builder: (_, __) => const DashboardScreen()),
    StatefulShellRoute.indexedStack(       // bottom navigation shell
      builder: (_, __, shell) => HomeShell(shell: shell),
      branches: [ /* attendance, leave, payroll, more */ ],
    ),
  ],
);
```

**What it is:** URL-based routing with a central `redirect` — equivalent to Laravel
route middleware, but for the client.
**Why:** auth guards and role-based redirection live in one place instead of scattered
`Navigator.push` calls.

**Role-based menus:** the shell builds its navigation items from the permissions the
server returned at login. Hiding a menu is *convenience* — Laravel still enforces access.

---

## 10. Offline queue design

```
Check-in happens with no signal
        ↓
Compress selfie (~200 KB) → app-private storage
        ↓
Insert into Drift `pending_actions`
   { local_uuid, type, payload_json, selfie_path,
     gps_lat, gps_lng, accuracy, client_timestamp, status='pending' }
        ↓
UI shows "Pending Sync" badge
        ↓
Connectivity returns (or app reopens) → upload batch
        ↓
Laravel re-validates: geofence, duplicate, timing
   ├─ accepted → server returns attendance_id → status='synced'
   └─ rejected → status='rejected' + reason → shown to the employee
```

**Why Drift and not simple key-value storage:** attendance is relational and needs
transactions plus a **unique constraint on `local_uuid`** so a retry can never duplicate.

---

## 11. Permissions in the UI

```dart
// At login the server returns the permission list; store it in auth state.
final canManage = ref.watch(authProvider.select(
  (s) => s.permissions.contains('attendance.manage'),
));

if (canManage) ...[ /* HR-only controls */ ],
```

**Remember:** this only controls *visibility*. The API enforces the real rule and
returns `403` if the app is bypassed.

---

## 12. Project structure

```
mobile/lib/
├── core/
│   ├── config/       app config, environment
│   ├── constants/    API routes, storage keys
│   ├── errors/       Failure types, message mapping
│   ├── network/      Dio client, interceptors, ApiResult
│   ├── storage/      secure storage, Drift database
│   ├── utils/        date, distance (haversine), validators
│   └── widgets/      LoadingView, ErrorView, EmptyState, buttons
│
├── features/
│   └── attendance/
│       ├── data/
│       │   ├── models/
│       │   ├── repositories/
│       │   └── sources/       AttendanceApi, AttendanceLocalDb
│       ├── providers/         Riverpod providers
│       ├── application/       use-cases (when logic is complex)
│       └── presentation/      screens + widgets
│
└── main.dart
```

---

## 13. Running the app

```bash
cd mobile
flutter pub get          # install dependencies (like composer install)
flutter analyze          # static analysis — must be clean
flutter test             # run tests
flutter run              # build + install on a connected device/emulator
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
| Format code | `dart format lib` |
| Analyze | `flutter analyze` |
| Test coverage | `flutter test --coverage` |
| Clean build | `flutter clean` (use when builds behave oddly) |

---

## 15. Common mistakes to avoid

| ❌ Don't | ✅ Do instead |
|---|---|
| Call Dio directly inside a `build()` method | Call a Repository via a provider |
| Validate geofence only in Flutter | Always let Laravel make the final decision |
| Store tokens in `shared_preferences` | Use `flutter_secure_storage` |
| Show raw exceptions to users | Map to friendly messages, log the details |
| Skip loading/error/empty states | Use the shared widgets from `core/widgets/` |
| Hard-code site coordinates or radii | Read them from the API |
| Rebuild everything for a small change | Watch only the specific provider you need |

---

## 16. Implementation Status

| Item | Status |
|---|---|
| This guide (concepts + patterns) | ✅ Written |
| Flutter SDK install (on `F:`) | ⬜ Phase 1b |
| Flutter project skeleton | ⬜ Phase 1b |
| Feature implementation | ⬜ Phase 3 onward |
