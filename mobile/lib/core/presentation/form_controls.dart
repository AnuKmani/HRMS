import 'package:flutter/material.dart';

/// One `value => label` pair for a [StatusField].
class StatusOption {
  const StatusOption(this.value, this.label);

  final String value;
  final String label;
}

/// The status picker shared by every master record.
///
/// Chips rather than a dropdown: these lists are two to five items long, all
/// of them are visible at once, and a dropdown's job — collapsing a long list
/// behind one tap — buys nothing here while hiding the options a person would
/// otherwise be choosing between.
class StatusField extends StatelessWidget {
  const StatusField({
    super.key,
    required this.label,
    required this.options,
    required this.value,
    required this.onChanged,
    this.errorText,
    this.isRequired = false,
    this.helper,
  });

  final String label;
  final List<StatusOption> options;
  final String value;
  final ValueChanged<String> onChanged;
  final String? errorText;
  final bool isRequired;
  final String? helper;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            isRequired ? '$label *' : label,
            style: theme.textTheme.labelLarge,
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final option in options)
                ChoiceChip(
                  key: ValueKey('status-${option.value}'),
                  label: Text(option.label),
                  selected: value == option.value,
                  onSelected: (_) => onChanged(option.value),
                ),
            ],
          ),
          if (errorText != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                errorText!,
                key: const ValueKey('status-error'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            )
          else if (helper != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(helper!, style: theme.textTheme.bodySmall),
            ),
        ],
      ),
    );
  }
}

/// The single line a form shows when something went wrong before or during a
/// save.
///
/// A banner rather than a toast for two reasons: a failure needs to stay on
/// screen while the user corrects it, and a test can only assert reliably on
/// something that was rendered rather than something that animated in and
/// out. A forbidden response gets its own key so a screen can tell "you may
/// not do this" apart from "this form is wrong" without parsing prose.
class FormBanner extends StatelessWidget {
  const FormBanner({super.key, required this.message, this.forbidden = false});

  final String message;

  /// True when the API answered 403 rather than rejecting an input.
  final bool forbidden;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      key: ValueKey(forbidden ? 'form-forbidden' : 'form-error'),
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            forbidden ? Icons.lock_outline : Icons.error_outline,
            size: 20,
            color: scheme.onErrorContainer,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: TextStyle(color: scheme.onErrorContainer),
            ),
          ),
        ],
      ),
    );
  }
}

/// A status dropdown for a list header.
///
/// The "all" option is spelled as an empty string rather than `null`: a
/// `DropdownButton` treats a null `value` as "nothing selected" and then
/// cannot say which row it should show, which is a different statement from
/// "no filter applied". Keeping both meanings as ordinary strings removes the
/// ambiguity.
class StatusFilter extends StatelessWidget {
  const StatusFilter({
    super.key,
    required this.value,
    required this.options,
    required this.onChanged,
    this.allLabel = 'All statuses',
  });

  /// The active filter, or `null` for no filter.
  final String? value;

  final List<StatusOption> options;
  final ValueChanged<String?> onChanged;
  final String allLabel;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonHideUnderline(
      child: DropdownButton<String>(
        key: const ValueKey('status-filter'),
        isExpanded: true,
        value: value ?? '',
        items: [
          DropdownMenuItem<String>(value: '', child: Text(allLabel)),
          for (final option in options)
            DropdownMenuItem<String>(
              value: option.value,
              child: Text(option.label),
            ),
        ],
        onChanged: onChanged,
      ),
    );
  }
}
