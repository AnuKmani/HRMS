import 'package:flutter/material.dart';

/// What a module draws when this session does not hold its read permission.
///
/// The point is not the message — it is that the branch exists at all. The
/// list controller behind these screens is never built in this state:
/// asking the API for something the session cannot have, only to paint the
/// answer, produces a request whose only two outcomes are a 403 and a lie
/// about what is stored.
///
/// The message names the *module*, not the permission string: `leave.view`
/// means nothing to the person reading it, and "leave" already tells them
/// which door was shut.
class NoPermission extends StatelessWidget {
  const NoPermission({super.key, required this.module});

  /// What is being withheld, as it reads on a tile — `employees`, `leave`.
  final String module;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      key: const ValueKey('no-permission'),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.lock_outline, size: 48, color: theme.hintColor),
            const SizedBox(height: 12),
            Text(
              'You do not have permission to view $module.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
          ],
        ),
      ),
    );
  }
}
