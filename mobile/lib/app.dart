import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/presentation/app_theme.dart';
import 'core/router/app_router.dart';

class HrmsApp extends ConsumerWidget {
  const HrmsApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: 'HRMS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      routerConfig: router,
    );
  }
}
