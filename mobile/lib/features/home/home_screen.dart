import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_controller.dart';
import '../auth/auth_models.dart';

/// Where a signed-in user lands.
///
/// A placeholder on purpose. The dashboard widgets it will eventually host —
/// attendance, leave balances, payroll — are all Phase 4 work behind their
/// own permission gates, and inventing them here would mean drawing UI the
/// API does not serve yet.
///
/// What this screen *does* prove is the part Phase 3 owns: the user was
/// restored or signed in, the router agreed, and the name shown came from
/// `GET /auth/me` rather than anything the form remembered.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final user = auth.user;
    final subtitle = _subtitle(user?.employee);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('HRMS'),
        actions: [
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: () =>
                ref.read(authControllerProvider.notifier).logout(),
          ),
        ],
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Welcome back', style: theme.textTheme.titleMedium),
              const SizedBox(height: 4),
              Text(
                user?.name ?? '',
                textAlign: TextAlign.center,
                style: theme.textTheme.headlineSmall,
              ),
              if (subtitle != null) ...[
                const SizedBox(height: 8),
                Text(subtitle, style: theme.textTheme.bodyMedium),
              ],
              const SizedBox(height: 40),
              Text(
                'The dashboard arrives with Phase 4.\n'
                'Signed in as ${user?.email ?? ''}',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }

  String? _subtitle(EmployeeBrief? employee) {
    if (employee == null) return null;

    final parts = <String>[
      if (employee.designation?.isNotEmpty ?? false) employee.designation!,
      if (employee.department?.isNotEmpty ?? false) employee.department!,
    ];

    return parts.isEmpty ? null : parts.join(' · ');
  }
}
