import 'package:flutter/material.dart';

import '../../../core/presentation/fields.dart';

/// One column of a repeatable row.
class ReportColumn {
  const ReportColumn({
    required this.id,
    required this.label,
    this.required = false,
    this.keyboardType,
    this.flex = 1,
    this.maxLines = 1,
    this.hint,
  });

  /// A stable, machine-ish name used to build the field's key
  /// (`manpower-0-category`). Never shown to anybody.
  final String id;

  final String label;

  final bool required;

  final TextInputType? keyboardType;

  final int flex;

  final int maxLines;

  final String? hint;
}

/// A list of rows the person can add to, edit and remove.
///
/// One widget for manpower, materials and equipment rather than three, for
/// the reason that matters: they are the *same* interaction — a label, a
/// handful of columns, a trash button, an add button — and three copies
/// would drift apart on the first change to how a row is validated or how
/// a removal is confirmed. What differs is only the column list, which is
/// already data.
///
/// Row contents are plain [TextEditingController]s owned by the calling
/// screen, because only the screen knows when they should be thrown away
/// (a successful save, a discarded draft) and a widget that owned them
/// would dispose them at the wrong moment on a rebuild.
///
/// Empty is not an error. Removing every row and saving means "none used
/// today", which is a legitimate answer and is represented by sending no
/// rows at all rather than by one blank one.
class RepeatableRowsField extends StatelessWidget {
  const RepeatableRowsField({
    super.key,
    required this.id,
    required this.label,
    required this.columns,
    required this.rows,
    required this.addRowLabel,
    required this.onAdd,
    required this.onRemove,
    this.isRequired = false,
    this.errorText,
    this.helper,
    this.emptyHint,
    this.busy = false,
    this.onRowChanged,
  });

  /// Used for the section's keys: `manpower-rows`, `manpower-add`,
  /// `manpower-remove-0`.
  final String id;

  final String label;

  final List<ReportColumn> columns;

  /// One [TextEditingController] per column, per row. Every row must have
  /// exactly `columns.length` controllers.
  final List<List<TextEditingController>> rows;

  final String addRowLabel;

  final VoidCallback onAdd;
  final ValueChanged<int> onRemove;

  final bool isRequired;

  final String? errorText;

  final String? helper;

  final String? emptyHint;

  final bool busy;

  /// Fires on every edit to any cell of any row.
  ///
  /// Without it the screen below these rows has no way to know that a
  /// row changed: a total derived from the rows would sit at its old
  /// value while the person typed the number it is derived from, and a
  /// draft deferred to "when something happens" would never be written
  /// at all, because typing into a cell is the one edit that does not
  /// otherwise tell anybody anything.
  final ValueChanged<String>? onRowChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(label, style: theme.textTheme.labelLarge),
              if (isRequired) ...[
                const SizedBox(width: 4),
                Text('*', style: theme.textTheme.labelLarge),
              ],
              const Spacer(),
              Text(
                '${rows.length} row${rows.length == 1 ? '' : 's'}',
                key: ValueKey('$id-count'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ],
          ),
          if (helper != null) ...[
            const SizedBox(height: 2),
            Text(helper!, style: theme.textTheme.bodySmall),
          ],
          const SizedBox(height: 8),
          if (rows.isEmpty)
            Padding(
              key: ValueKey('$id-empty'),
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                emptyHint ?? 'Nothing entered yet.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ),
          for (var index = 0; index < rows.length; index++)
            Padding(
              key: ValueKey('$id-row-$index'),
              padding: const EdgeInsets.only(bottom: 8),
              child: Card(
                margin: EdgeInsets.zero,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 4, 4, 0),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (var column = 0; column < columns.length; column++)
                        Expanded(
                          flex: columns[column].flex,
                          child: LabeledTextField(
                            key: ValueKey('$id-$index-${columns[column].id}'),
                            label: columns[column].label,
                            isRequired: columns[column].required,
                            keyboardType: columns[column].keyboardType,
                            maxLines: columns[column].maxLines,
                            hint: columns[column].hint,
                            enabled: !busy,
                            controller: rows[index][column],
                            onChanged: onRowChanged,
                          ),
                        ),
                      IconButton(
                        key: ValueKey('$id-remove-$index'),
                        tooltip: 'Remove row ${index + 1}',
                        onPressed: busy ? null : () => onRemove(index),
                        icon: const Icon(Icons.delete_outline),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          OutlinedButton.icon(
            key: ValueKey('$id-add'),
            onPressed: busy ? null : onAdd,
            icon: const Icon(Icons.add),
            label: Text(addRowLabel),
          ),
          if (errorText != null) ...[
            const SizedBox(height: 8),
            Text(
              errorText!,
              key: ValueKey('$id-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
