import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/config/server_address.dart';
import 'package:mobile/core/storage/device_identity.dart';
import 'package:mobile/core/storage/token_store.dart';
import 'package:mobile/features/auth/auth_controller.dart';
import 'package:mobile/features/auth/auth_repository.dart';
import 'package:mobile/features/auth/login_screen.dart';

import '../support/fakes.dart';

/// A few frames, not pumpAndSettle: the sign-in screen keeps an
/// animation running that never settles.
Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 8; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  test('what a tester types becomes a full API address', () {
    expect(
      normaliseServerAddress('192.168.1.170'),
      'http://192.168.1.170:8000/api/v1',
    );
    expect(
      normaliseServerAddress(' 192.168.1.170:9000 '),
      'http://192.168.1.170:9000/api/v1',
    );
    expect(
      normaliseServerAddress('https://hr.example.com/api/v1/'),
      'https://hr.example.com/api/v1',
    );
    expect(
      serverAddressLabel('http://192.168.1.164:8000/api/v1'),
      '192.168.1.164:8000',
    );
  });

  Future<ProviderContainer> pumpLogin(
    WidgetTester tester, {
    required bool allowed,
    required MemoryServerAddressStore store,
    String? probeAnswer,
  }) async {
    final probed = <String>[];
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          authRepositoryProvider.overrideWithValue(
            FakeAuthRepository(user: buildUser()),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
          deviceIdentityProvider.overrideWithValue(
            const FixedDeviceIdentity('test-device'),
          ),
          serverSettingAllowedProvider.overrideWithValue(allowed),
          serverAddressStoreProvider.overrideWithValue(store),
          serverProbeProvider.overrideWithValue((url) async {
            probed.add(url);
            return probeAnswer;
          }),
        ],
        child: const MaterialApp(home: LoginScreen()),
      ),
    );
    final container = ProviderScope.containerOf(
      tester.element(find.byType(LoginScreen)),
      listen: false,
    );
    // As in the app: the sign-in form is only shown once the stored session
    // has been looked for and none found.
    await container.read(authControllerProvider.notifier).restoreSession();
    await settle(tester);
    return container;
  }

  testWidgets('a production build has no way to change the server', (
    tester,
  ) async {
    await pumpLogin(
      tester,
      allowed: false,
      store: MemoryServerAddressStore()..value = 'http://evil:8000/api/v1',
    );

    expect(find.byKey(const ValueKey('server-address')), findsNothing);
  });

  testWidgets('a test build saves a new laptop address on the phone', (
    tester,
  ) async {
    final store = MemoryServerAddressStore();
    final container = await pumpLogin(tester, allowed: true, store: store);

    await tester.ensureVisible(
      find.byKey(const ValueKey('server-address-change')),
    );
    await tester.tap(find.byKey(const ValueKey('server-address-change')));
    await settle(tester);
    await tester.enterText(
      find.byKey(const ValueKey('server-address-field')),
      '192.168.1.170',
    );
    await tester.tap(find.byKey(const ValueKey('server-address-test')));
    await settle(tester);
    expect(find.text('Connected to 192.168.1.170:8000.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('server-address-save')));
    await settle(tester);

    expect(store.value, 'http://192.168.1.170:8000/api/v1');
    expect(
      container.read(serverAddressProvider),
      'http://192.168.1.170:8000/api/v1',
    );
    expect(find.text('Server: 192.168.1.170:8000'), findsOneWidget);
  });

  testWidgets('Test says plainly when the laptop does not answer', (
    tester,
  ) async {
    await pumpLogin(
      tester,
      allowed: true,
      store: MemoryServerAddressStore(),
      probeAnswer: 'No answer from 192.168.1.164:8000.',
    );

    await tester.ensureVisible(
      find.byKey(const ValueKey('server-address-change')),
    );
    await tester.tap(find.byKey(const ValueKey('server-address-change')));
    await settle(tester);
    await tester.enterText(
      find.byKey(const ValueKey('server-address-field')),
      '192.168.1.164',
    );
    await tester.tap(find.byKey(const ValueKey('server-address-test')));
    await settle(tester);

    expect(find.text('No answer from 192.168.1.164:8000.'), findsOneWidget);
  });
}
