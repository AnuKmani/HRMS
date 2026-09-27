import 'package:flutter/material.dart';

/// How much attention a status deserves on a row.
///
/// Five values rather than a colour per status: the colour is a *judgement*
/// ("has this gone badly?"), and letting each screen pick its own hue for
/// `rejected` would eventually produce two shades of red in two lists that
/// both mean the same thing.
enum StatusTone {
  /// Nothing decided yet — a draft, an open period.
  neutral,

  /// In flight.
  info,

  /// Finished the way it should have.
  positive,

  /// Needs somebody's attention soon.
  warning,

  /// Finished the other way.
  negative,
}

/// The trailing chip every status-bearing row in this app wears.
///
/// Deliberately not a `ChoiceChip`: these are read-only. A row's status is a
/// fact the server decided, and rendering it as something that looks
/// tappable invites a press that changes nothing.
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    this.tone = StatusTone.neutral,
  });

  final String label;

  final StatusTone tone;

  /// Material 3 ships no green container pair, and `secondaryContainer` —
  /// the usual stand-in — reads as "unrelated highlight" beside two reds and
  /// a grey in the same list. Approved and completed are the two outcomes a
  /// person scanning a long list is looking *for*, so they get a pair of
  /// their own rather than a hex value repeated at every call site.
  static const _positiveBackground = Color(0xFFD6F5E1);

  static const _positiveForeground = Color(0xFF136C39);

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final (background, foreground) = switch (tone) {
      StatusTone.neutral => (
        scheme.surfaceContainerHighest,
        scheme.onSurfaceVariant,
      ),
      StatusTone.info => (scheme.primaryContainer, scheme.onPrimaryContainer),
      StatusTone.positive => (_positiveBackground, _positiveForeground),
      StatusTone.warning => (
        scheme.tertiaryContainer,
        scheme.onTertiaryContainer,
      ),
      StatusTone.negative => (scheme.errorContainer, scheme.onErrorContainer),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall
            ?.copyWith(color: foreground, fontWeight: FontWeight.w600),
      ),
    );
  }
}
