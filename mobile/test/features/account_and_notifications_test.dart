import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/storage/token_store.dart';
import 'package:mobile/features/account/account_repository.dart';
import 'package:mobile/features/account/change_password_screen.dart';
import 'package:mobile/features/account/forgot_password_screen.dart';
import 'package:mobile/features/account/sessions_screen.dart';
import 'package:mobile/features/auth/auth_repository.dart';
import 'package:mobile/core/storage/device_identity.dart';
import 'package:mobile/features/dashboard/my_summary.dart';
import 'package:mobile/features/home/home_screen.dart';
import 'package:mobile/features/notifications/data/notifications_repository.dart';
import 'package:mobile/features/notifications/domain/app_notification.dart';
import 'package:mobile/features/notifications/presentation/notifications_screen.dart';

import '../support/fakes.dart';

class FakeNotifications implements NotificationsRepository {
  FakeNotifications(this.rows);

  final List<Map<String, dynamic>> rows;
  final List<int> marked = [];
  int markAllCalls = 0;

  int get _unread => rows.where((row) => row['read'] != true).length;

  @override
  Future<PageResult<AppNotification>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final items = [
      for (final row in rows)
        if (query['unread'] != '1' || row['read'] != true)
          AppNotification.fromJson(row),
    ];

    return PageResult<AppNotification>(
      items: items,
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: items.length,
      hasNext: false,
    );
  }

  @override
  Future<int> unreadCount() async => _unread;

  @override
  Future<int> markRead(int id) async {
    marked.add(id);
    rows.firstWhere((row) => row['id'] == id)['read'] = true;
    return _unread;
  }

  @override
  Future<int> markAllRead() async {
    markAllCalls++;
    for (final row in rows) {
      row['read'] = true;
    }
    return _unread;
  }
}

class FakeAccount implements AccountRepository {
  Object? changeError;
  final List<Map<String, String>> changes = [];
  final List<String> resets = [];

  @override
  Future<String> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    changes.add({
      'current_password': currentPassword,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    final error = changeError;
    if (error != null) throw error;
    return 'Password updated. Other devices have been signed out.';
  }

  @override
  Future<String> requestPasswordReset(String email) async {
    resets.add(email);
    return 'If that email address is registered, a password reset link has been sent.';
  }

  final List<DeviceSession> devices = [
    DeviceSession(
      id: 1,
      device: 'Samsung SM-S918B',
      isCurrent: true,
      lastUsedAt: DateTime.utc(2026, 10, 4, 8),
    ),
    const DeviceSession(id: 2, device: 'Old tablet', isCurrent: false),
  ];
  final List<int> revoked = [];

  @override
  Future<List<DeviceSession>> sessions() async => List.of(devices);

  @override
  Future<String> revokeSession(int id) async {
    revoked.add(id);
    devices.removeWhere((device) => device.id == id);
    return 'Session revoked.';
  }
}

class FakeSummary implements MySummaryRepository {
  FakeSummary(this.json);

  final Map<String, dynamic> json;
  int calls = 0;

  @override
  Future<MySummary> fetch() async {
    calls++;
    return MySummary.fromJson(json);
  }
}

Map<String, dynamic> notificationRow(
  int id, {
  bool read = false,
  String? route,
}) => {
  'id': id,
  'type': 'leave.decided',
  'title': 'Notification $id',
  'body': 'Body $id',
  'data': {'route': ?route},
  'read': read,
  'created_at': '2026-10-01T09:30:00+00:00',
};

Future<void> advance(WidgetTester tester, {int frames = 8}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  late FakeAuthRepository auth;
  late InMemoryTokenStore tokenStore;
  late FakeNotifications notifications;
  late FakeAccount account;
  late FakeSummary summary;

  setUp(() {
    auth = FakeAuthRepository(user: buildUser(name: 'Grace Hopper'));
    tokenStore = InMemoryTokenStore();
    notifications = FakeNotifications([
      notificationRow(1, route: '/holidays'),
      notificationRow(2, read: true),
      notificationRow(3),
    ]);
    account = FakeAccount();
    summary = FakeSummary({
      'role': 'employee',
      'blocks': {
        'attendance': {'checked_in_at': null, 'checked_out_at': null},
        'leave': {'pending': 2, 'approved_upcoming': 0},
        'notifications': {'unread': 2},
      },
    });
  });

  Future<void> pumpApp(WidgetTester tester, {bool signedIn = true}) async {
    if (signedIn) tokenStore.value = 'stored-token';

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          authRepositoryProvider.overrideWithValue(auth),
          tokenStoreProvider.overrideWithValue(tokenStore),
          deviceIdentityProvider.overrideWithValue(
            const FixedDeviceIdentity('test-device'),
          ),
          notificationsRepositoryProvider.overrideWithValue(notifications),
          accountRepositoryProvider.overrideWithValue(account),
          mySummaryRepositoryProvider.overrideWithValue(summary),
        ],
        child: const HrmsApp(),
      ),
    );
    await advance(tester);
  }

  group('dashboard', () {
    testWidgets('the bell shows the server\'s unread count', (tester) async {
      await pumpApp(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('open-notifications')),
          matching: find.text('2'),
        ),
        findsOneWidget,
      );
    });
  });

  group('summary', () {
    testWidgets('draws only the blocks the server sent, and opens them', (
      tester,
    ) async {
      auth.user = buildUser(
        permissions: const ['dashboard.view', 'leave.view'],
      );
      await pumpApp(tester);

      expect(find.byKey(const ValueKey('my-summary')), findsOneWidget);
      expect(find.text('Not checked in'), findsOneWidget);
      expect(find.text('2'), findsWidgets);
      expect(find.byKey(const ValueKey('summary-payslip')), findsNothing);
      expect(
        find.byKey(const ValueKey('summary-leave-available')),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('summary-leave-pending')));
      await advance(tester);
      expect(find.byType(HomeScreen), findsNothing);
    });

    testWidgets('is not asked for without dashboard.view', (tester) async {
      await pumpApp(tester);

      expect(summary.calls, 0);
      expect(find.byKey(const ValueKey('my-summary')), findsNothing);
    });
  });

  group('notifications', () {
    testWidgets('opening an unread one marks it read and follows its route', (
      tester,
    ) async {
      await pumpApp(tester);
      await tester.tap(find.byKey(const ValueKey('open-notifications')));
      await advance(tester);

      expect(find.byType(NotificationsScreen), findsOneWidget);
      expect(find.text('Notification 1'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('notification-row-1')));
      await advance(tester);

      expect(notifications.marked, [1]);
      expect(find.text('Holidays'), findsWidgets);

      // Back through the inbox to the dashboard, whose bell has caught up.
      await tester.pageBack();
      await advance(tester);
      await tester.pageBack();
      await advance(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('open-notifications')),
          matching: find.text('1'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a read one is not marked again', (tester) async {
      await pumpApp(tester);
      await tester.tap(find.byKey(const ValueKey('open-notifications')));
      await advance(tester);

      await tester.tap(find.byKey(const ValueKey('notification-row-2')));
      await advance(tester);

      expect(notifications.marked, isEmpty);
    });

    testWidgets('mark all as read clears the badge', (tester) async {
      await pumpApp(tester);
      await tester.tap(find.byKey(const ValueKey('open-notifications')));
      await advance(tester);

      await tester.tap(find.byKey(const ValueKey('mark-all-read')));
      await advance(tester);
      await tester.pageBack();
      await advance(tester);

      expect(notifications.markAllCalls, 1);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('open-notifications')),
          matching: find.byType(Text),
        ),
        findsNothing,
      );
    });

    test('only in-app routes are kept from the payload', () {
      AppNotification parse(Object? route) => AppNotification.fromJson({
        'id': 1,
        'title': 't',
        'body': 'b',
        'read': false,
        'data': {'route': route},
      });

      expect(parse('/leave/4').route, '/leave/4');
      expect(parse('https://example.com').route, isNull);
      expect(parse('//example.com/x').route, isNull);
      expect(parse(7).route, isNull);
    });
  });

  group('change password', () {
    Future<void> open(WidgetTester tester) async {
      await pumpApp(tester);
      await tester.tap(find.byKey(const ValueKey('account-menu')));
      await advance(tester);
      await tester.tap(find.byKey(const ValueKey('change-password')));
      await advance(tester);
      expect(find.byType(ChangePasswordScreen), findsOneWidget);
    }

    Future<void> fill(WidgetTester tester, String a, String b, String c) async {
      final fields = find.byType(TextField);
      await tester.enterText(fields.at(0), a);
      await tester.enterText(fields.at(1), b);
      await tester.enterText(fields.at(2), c);
      await tester.tap(find.byKey(const ValueKey('save-password')));
      await advance(tester);
    }

    testWidgets('mismatched new passwords never reach the server', (
      tester,
    ) async {
      await open(tester);
      await fill(tester, 'old-pass-1', 'new-pass-1', 'new-pass-2');

      expect(account.changes, isEmpty);
      expect(find.text('The two new passwords do not match.'), findsOneWidget);
    });

    testWidgets('the server\'s field errors are shown under the field', (
      tester,
    ) async {
      account.changeError = const ApiException(
        statusCode: 422,
        message: 'The given data was invalid.',
        errors: {'current_password': 'The current password is incorrect.'},
      );
      await open(tester);
      await fill(tester, 'wrong-1', 'new-pass-1', 'new-pass-1');

      expect(find.text('The current password is incorrect.'), findsOneWidget);
      expect(find.byType(ChangePasswordScreen), findsOneWidget);
    });

    testWidgets('a success returns to the dashboard and says so', (
      tester,
    ) async {
      await open(tester);
      await fill(tester, 'old-pass-1', 'new-pass-1', 'new-pass-1');

      expect(account.changes.single, {
        'current_password': 'old-pass-1',
        'password': 'new-pass-1',
        'password_confirmation': 'new-pass-1',
      });
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(
        find.text('Password updated. Other devices have been signed out.'),
        findsOneWidget,
      );
    });
  });

  group('forgot password', () {
    testWidgets('is reachable signed out and repeats the server\'s answer', (
      tester,
    ) async {
      await pumpApp(tester, signedIn: false);

      await tester.tap(find.byKey(const ValueKey('forgot-password')));
      await advance(tester);
      expect(find.byType(ForgotPasswordScreen), findsOneWidget);

      await tester.enterText(find.byType(TextField), ' someone@example.com ');
      await tester.tap(find.byKey(const ValueKey('send-reset-link')));
      await advance(tester);

      expect(account.resets, ['someone@example.com']);
      expect(
        find.text(
          'If that email address is registered, a password reset link has been sent.',
        ),
        findsOneWidget,
      );

      await tester.pageBack();
      await advance(tester);
      expect(find.byKey(const ValueKey('forgot-password')), findsOneWidget);
    });
  });

  group('signed-in devices', () {
    testWidgets('lists devices, and only other devices can be signed out', (
      tester,
    ) async {
      await pumpApp(tester);
      await tester.tap(find.byKey(const ValueKey('account-menu')));
      await advance(tester);
      await tester.tap(find.byKey(const ValueKey('signed-in-devices')));
      await advance(tester);

      expect(find.byType(SessionsScreen), findsOneWidget);
      expect(find.text('This device'), findsOneWidget);
      expect(find.byKey(const ValueKey('revoke-session-1')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('revoke-session-2')));
      await advance(tester);
      await tester.tap(find.byKey(const ValueKey('confirm-revoke')));
      await advance(tester);

      expect(account.revoked, [2]);
      expect(find.text('Old tablet'), findsNothing);
      expect(find.text('Session revoked.'), findsOneWidget);
    });
  });

  testWidgets('a notification arriving while the dashboard is open alerts', (
    tester,
  ) async {
    await pumpApp(tester);
    expect(find.byKey(const ValueKey('open-notifications')), findsOneWidget);

    // HR approves the leave somewhere else; the next poll sees it.
    notifications.rows.add(notificationRow(4, route: '/leave/9'));
    await tester.pump(const Duration(seconds: 31));
    await advance(tester);

    expect(
      find.byKey(const ValueKey('new-notification-alert')),
      findsOneWidget,
    );
    expect(find.text('You have a new notification'), findsOneWidget);

    await tester.tap(find.text('VIEW'));
    await advance(tester);
    expect(find.text('Notification 4'), findsOneWidget);
  });

  testWidgets('the bell rests while the app is in the background', (
    tester,
  ) async {
    await pumpApp(tester);

    // The app goes to the background; a notification arrives meanwhile.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    notifications.rows.add(notificationRow(5));
    await tester.pump(const Duration(seconds: 65));
    await advance(tester);
    expect(find.byKey(const ValueKey('new-notification-alert')), findsNothing);

    // Back on screen: one check straight away.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await advance(tester);
    expect(
      find.byKey(const ValueKey('new-notification-alert')),
      findsOneWidget,
    );
  });
}
