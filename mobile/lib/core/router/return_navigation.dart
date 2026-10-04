import 'dart:async';
import 'dart:math';

import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

/// Leaving a form or a deleted record without losing the way back.
///
/// Every route in this app is top-level, so `context.go('/leave')` does not
/// mean "return to the list": it throws the whole stack away and leaves the
/// list as the only page — no back arrow, and no way back to the dashboard
/// short of signing out. That is what [returnTo] is for.
///
/// It lands on [location] as a **fresh** page — so a detail screen that read
/// its record once in `initState` shows the save that just happened — while
/// keeping everything that was underneath:
///
///  * if [location] is already in the stack (a form pushed from its list or
///    its detail screen), every page from that copy upwards is replaced by
///    the new one: `[home, list, detail, edit]` → `[home, list, detail]`;
///  * otherwise only the current page is replaced (a create form that ends
///    on the new record): `[home, list, new]` → `[home, list, detail]`;
///  * a page that somehow has nothing under it gets the dashboard put back
///    beneath it, so Back always has somewhere to go.
extension ReturnNavigation on BuildContext {
  void returnTo(String location) {
    final router = GoRouter.of(this);
    final target = router.configuration.findMatch(Uri.parse(location));

    if (target.isError) {
      router.go(location);
      return;
    }

    var stack = router.routerDelegate.currentConfiguration;
    final matches = stack.matches;

    // The deepest copy of the destination below the current page; failing
    // that, just the current page.
    var cut = matches.length - 1;
    for (var i = matches.length - 2; i >= 0; i--) {
      if (matches[i].matchedLocation == target.last.matchedLocation) {
        cut = i;
        break;
      }
    }

    for (final match in matches.sublist(cut).reversed) {
      // Anyone awaiting `push()` for a page we are discarding gets an answer
      // instead of a future that never completes.
      if (match is ImperativeRouteMatch && !match.completer.isCompleted) {
        match.complete();
      }
      stack = stack.remove(match);
    }

    if (stack.isEmpty) {
      final home = router.configuration.findMatch(Uri.parse('/home'));
      if (location == '/home' || home.isError) {
        router.go(location);
        return;
      }
      stack = home;
    }

    router.restore(
      stack.push(
        ImperativeRouteMatch(
          pageKey: ValueKey<String>('return-${_nextKey()}'),
          completer: Completer<Object?>(),
          matches: target,
        ),
      ),
    );
  }
}

final _random = Random();

String _nextKey() =>
    '${DateTime.now().microsecondsSinceEpoch}-${_random.nextInt(1 << 32)}';
