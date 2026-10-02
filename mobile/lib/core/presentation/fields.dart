import 'package:flutter/material.dart';

/// A form field whose label, required marker and error all live together.
///
/// Deliberately not a `TextFormField` inside a `Form`: this app's validation
/// comes from the server as a `{field: message}` map, and pushing that into
/// `Form.validate()` would mean mirroring every rule the backend already owns
/// on the client to keep the two in step. The field draws whatever error it
/// is given; the screen decides where the error came from.
class LabeledTextField extends StatelessWidget {
  const LabeledTextField({
    super.key,
    required this.label,
    required this.controller,
    this.errorText,
    this.isRequired = false,
    this.keyboardType,
    this.textInputAction,
    this.maxLines = 1,
    this.obscureText = false,
    this.enabled = true,
    this.hint,
    this.helper,
    this.autofillHints,
    this.onChanged,
  });

  final String label;
  final TextEditingController controller;
  final String? errorText;
  final bool isRequired;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final int maxLines;
  final bool obscureText;
  final bool enabled;

  /// Applied only while there is no [errorText] — the error is the more
  /// important of the two and they must not fight for the same line.
  final String? hint;

  final String? helper;

  final Iterable<String>? autofillHints;

  /// Told about every keystroke rather than only about a submitted form.
  ///
  /// Screens that keep a local draft need to know *while* a person types:
  /// a draft saved on blur is a draft that is already gone when the app is
  /// swiped away from underneath them. Left null by everything that only
  /// cares about the value when it is read.
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(_labelText, style: Theme.of(context).textTheme.labelLarge),
          const SizedBox(height: 6),
          TextField(
            controller: controller,
            enabled: enabled,
            maxLines: maxLines,
            obscureText: obscureText,
            keyboardType: keyboardType,
            textInputAction: textInputAction,
            autofillHints: autofillHints,
            onChanged: onChanged,
            decoration: InputDecoration(
              hintText: errorText == null ? hint : null,
              helperText: errorText == null ? helper : null,
              errorText: errorText,
              border: const OutlineInputBorder(),
              isDense: true,
            ),
          ),
        ],
      ),
    );
  }

  String get _labelText => isRequired ? '$label *' : label;
}

/// A date the server expects as `YYYY-MM-DD`.
///
/// The text is editable rather than read-only so a keyboard user can still
/// type a date in, and the calendar button is a shortcut rather than the only
/// way in — a control that only works one way is a control that breaks for
/// somebody.
class DateField extends StatelessWidget {
  const DateField({
    super.key,
    required this.label,
    required this.controller,
    this.errorText,
    this.isRequired = false,
    this.allowEmpty = false,
    this.onChanged,
    this.firstDate,
    this.lastDate,
    this.helper,
  });

  final String label;
  final TextEditingController controller;
  final String? errorText;
  final bool isRequired;

  /// When true the field may be cleared back to `null`, which is how the API
  /// is told "this has no value" rather than "this is an empty string".
  final bool allowEmpty;

  final ValueChanged<String>? onChanged;

  final DateTime? firstDate;
  final DateTime? lastDate;

  /// A note about *what the date means*, drawn under the box exactly where
  /// `LabeledTextField` draws its own.
  ///
  /// Present for the same reason `LabeledTextField` has one: several of the
  /// forms in this app hinge on a date that is a real choice rather than a
  /// required field — "leave this empty and the server works it out" is a
  /// sentence a person needs while the box is still on screen, and burying
  /// it in a doc comment nobody taps would leave them to discover the rule
  /// from a 422.
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
          TextField(
            controller: controller,
            readOnly: true,
            onTap: () => _pick(context),
            decoration: InputDecoration(
              hintText: 'YYYY-MM-DD',
              errorText: errorText,
              suffixIcon: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (allowEmpty && controller.text.isNotEmpty)
                    IconButton(
                      tooltip: 'Clear',
                      icon: const Icon(Icons.clear),
                      onPressed: () {
                        controller.clear();
                        onChanged?.call('');
                      },
                    ),
                  const Icon(Icons.calendar_today, size: 18),
                  const SizedBox(width: 12),
                ],
              ),
              border: const OutlineInputBorder(),
              isDense: true,
            ),
          ),
          if (helper != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(helper!, style: theme.textTheme.bodySmall),
            ),
        ],
      ),
    );
  }

  Future<void> _pick(BuildContext context) async {
    final current = DateTime.tryParse(controller.text);
    final today = DateTime.now();

    final picked = await showDatePicker(
      context: context,
      // A date with no value yet opens on today rather than refusing to open:
      // the alternative is a control that does nothing the first time.
      initialDate: current ?? today,
      firstDate: firstDate ?? DateTime(today.year - 60),
      lastDate: lastDate ?? DateTime(today.year + 60),
    );

    if (picked == null || !context.mounted) return;

    controller.text =
        '${picked.year.toString().padLeft(4, '0')}-'
        '${picked.month.toString().padLeft(2, '0')}-'
        '${picked.day.toString().padLeft(2, '0')}';
    onChanged?.call(controller.text);
  }
}
