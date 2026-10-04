import 'package:flutter/material.dart';

/// The app's look, in one place.
///
/// A vivid indigo base with soft, rounded surfaces. Each module on the
/// dashboard carries its own accent from [ModuleColors] so the eye can find
/// "Leave" or "Payroll" by colour before reading a word.
abstract final class AppTheme {
  static const Color brand = Color(0xFF4F46E5); // indigo
  static const Color brandDeep = Color(0xFF3730A3);
  static const Color accent = Color(0xFF06B6D4); // cyan

  /// The dashboard header's gradient.
  static const LinearGradient headerGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF4F46E5), Color(0xFF7C3AED), Color(0xFFDB2777)],
  );

  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: brand,
      primary: brand,
      secondary: accent,
      surface: Colors.white,
    );

    final rounded = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: const Color(0xFFF5F6FB),
      appBarTheme: AppBarTheme(
        backgroundColor: const Color(0xFFF5F6FB),
        foregroundColor: const Color(0xFF1E1B4B),
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: const TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w700,
          color: Color(0xFF1E1B4B),
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: Color(0xFFE6E8F2)),
        ),
      ),
      listTileTheme: const ListTileThemeData(
        contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: Color(0xFFD9DCEA)),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          shape: rounded,
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          textStyle: const TextStyle(
            fontWeight: FontWeight.w700,
            letterSpacing: 0.4,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(shape: rounded),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: brand,
        foregroundColor: Colors.white,
        shape: rounded,
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        side: BorderSide.none,
        backgroundColor: const Color(0xFFEEF0FF),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    );
  }
}

/// One accent per module family, used for the dashboard tiles.
abstract final class ModuleColors {
  static const Color attendance = Color(0xFF10B981); // emerald
  static const Color leave = Color(0xFF0EA5E9); // sky
  static const Color time = Color(0xFF8B5CF6); // violet
  static const Color people = Color(0xFF6366F1); // indigo
  static const Color org = Color(0xFF14B8A6); // teal
  static const Color projects = Color(0xFFF59E0B); // amber
  static const Color sites = Color(0xFFF97316); // orange
  static const Color reports = Color(0xFFEAB308); // yellow
  static const Color pay = Color(0xFF22C55E); // green
  static const Color money = Color(0xFFEC4899); // pink
  static const Color compliance = Color(0xFFEF4444); // red
  static const Color learning = Color(0xFF3B82F6); // blue
  static const Color assets = Color(0xFF64748B); // slate
  static const Color calendar = Color(0xFFA855F7); // purple
}
