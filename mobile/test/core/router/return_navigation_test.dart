import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/router/return_navigation.dart';

/// A flat route table shaped like the app's: every path top-level, so the
/// stack is whatever was pushed — the situation `context.go` used to wreck.
GoRouter _router() {
  Widget page(GoRouterState state) => Scaffold(
    appBar: AppBar(title: Text(state.uri.path)),
    body: Builder(
      builder: (context) => TextButton(
        key: const ValueKey('return'),
        onPressed: () {
          final target = state.uri.queryParameters['to'];
          if (target != null) context.returnTo(target);
        },
        child: const Text('return'),
      ),
    ),
  );

  return GoRouter(
    initialLocation: '/home',
    routes: [
      for (final path in [
        '/home',
        '/leave',
        '/leave/new',
        '/leave/:id',
        '/leave/:id/edit',
      ])
        GoRoute(path: path, builder: (_, state) => page(state)),
    ],
  );
}

List<String> _stack(GoRouter router) => [
  for (final match in router.routerDelegate.currentConfiguration.matches)
    match.matchedLocation,
];

Future<GoRouter> _pump(WidgetTester tester) async {
  final router = _router();
  await tester.pumpWidget(MaterialApp.router(routerConfig: router));
  await tester.pumpAndSettle();
  return router;
}

Future<void> _tapReturn(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('return')).last);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('a create form returns to its list with the dashboard below', (
    tester,
  ) async {
    final router = await _pump(tester);
    router.push('/leave');
    await tester.pumpAndSettle();
    router.push('/leave/new?to=/leave');
    await tester.pumpAndSettle();

    await _tapReturn(tester);

    expect(_stack(router), ['/home', '/leave']);
    expect(find.byType(BackButton), findsOneWidget);
  });

  testWidgets('an edit form returns to a fresh copy of its detail page', (
    tester,
  ) async {
    final router = await _pump(tester);
    router.push('/leave');
    await tester.pumpAndSettle();
    router.push('/leave/5');
    await tester.pumpAndSettle();
    final before = router.routerDelegate.currentConfiguration.last.pageKey;
    router.push('/leave/5/edit?to=/leave/5');
    await tester.pumpAndSettle();

    await _tapReturn(tester);

    expect(_stack(router), ['/home', '/leave', '/leave/5']);
    expect(
      router.routerDelegate.currentConfiguration.last.pageKey,
      isNot(before),
      reason: 'the detail page must be rebuilt so it re-reads the record',
    );
  });

  testWidgets('a create form that ends on the new record replaces itself', (
    tester,
  ) async {
    final router = await _pump(tester);
    router.push('/leave');
    await tester.pumpAndSettle();
    router.push('/leave/new?to=/leave/9');
    await tester.pumpAndSettle();

    await _tapReturn(tester);

    expect(_stack(router), ['/home', '/leave', '/leave/9']);
  });

  testWidgets('a page with nothing below gets the dashboard put back', (
    tester,
  ) async {
    final router = await _pump(tester);
    router.go('/leave/new?to=/leave');
    await tester.pumpAndSettle();

    await _tapReturn(tester);

    expect(_stack(router), ['/home', '/leave']);
    expect(find.byType(BackButton), findsOneWidget);
  });

  testWidgets('whoever awaited the discarded push gets an answer', (
    tester,
  ) async {
    final router = await _pump(tester);
    router.push('/leave');
    await tester.pumpAndSettle();
    var completed = false;
    router.push<Object?>('/leave/new?to=/leave').then((_) => completed = true);
    await tester.pumpAndSettle();

    await _tapReturn(tester);

    expect(completed, isTrue);
  });
}
