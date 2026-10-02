import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/presentation/fields.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/domain/employee.dart';
import '../../employees/presentation/employees_controller.dart';
import '../domain/asset.dart';

/// The three acts this module has, each as its own sheet.
///
/// Three sheets rather than one with a mode, for the same reason the API has
/// three endpoints: **handing something out, taking it back and moving its
/// status are different acts with different permissions and different
/// refusals.** A single form that decided which one it was performing from a
/// dropdown would be a place where the two could disagree, and the refusal
/// that came back would be about an act nobody thought they were performing.
///
/// Each returns the payload or `null` for "do not send anything" — the
/// screen underneath owns the request, the busy flag and the reload.

Future<Map<String, Object?>?> showAssetAssignSheet({
  required BuildContext context,
}) => showModalBottomSheet<Map<String, Object?>>(
  context: context,
  isScrollControlled: true,
  builder: (context) => const _AssignSheet(),
);

Future<Map<String, Object?>?> showAssetReturnSheet({
  required BuildContext context,
}) => showModalBottomSheet<Map<String, Object?>>(
  context: context,
  isScrollControlled: true,
  builder: (context) => const _ReturnSheet(),
);

Future<Map<String, Object?>?> showAssetStatusSheet({
  required BuildContext context,
  required List<String> targets,
  required String current,
}) => showModalBottomSheet<Map<String, Object?>>(
  context: context,
  isScrollControlled: true,
  builder: (context) => _StatusSheet(targets: targets, current: current),
);

String _today() {
  final now = DateTime.now();
  return '${now.year.toString().padLeft(4, '0')}-'
      '${now.month.toString().padLeft(2, '0')}-'
      '${now.day.toString().padLeft(2, '0')}';
}

Widget _sheet(
  BuildContext context, {
  required String title,
  required List<Widget> children,
}) {
  final theme = Theme.of(context);

  return SafeArea(
    child: Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(title, style: theme.textTheme.titleMedium)),
              IconButton(
                tooltip: 'Close',
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ...children,
        ],
      ),
    ),
  );
}

/// One dropdown, one shape, for all three sheets — and a `value` of `null`
/// (or `''`) draws the placeholder rather than a blank box. That is not
/// cosmetics: a return *has* to record what came back, and a condition that
/// silently defaults to "good" would be this form claiming to have seen an
/// asset it never saw. The assign sheet offers a starting point because
/// "the condition going out" is usually the condition it was already in;
/// the return sheet offers none, because nothing about a hand-back is
/// known until somebody types it.
Widget _conditionField({
  required Key key,
  required String label,
  required String? value,
  required ValueChanged<String?> onChanged,
  bool placeholder = false,
}) => Column(
  crossAxisAlignment: CrossAxisAlignment.start,
  children: [
    Text(label, style: _labelStyle),
    const SizedBox(height: 6),
    InputDecorator(
      decoration: const InputDecoration(
        border: OutlineInputBorder(),
        isDense: true,
        contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          key: key,
          isExpanded: true,
          value: value == null || value.isEmpty
              ? (placeholder ? '' : null)
              : value,
          items: [
            if (placeholder)
              const DropdownMenuItem<String>(
                value: '',
                child: Text('Choose a condition'),
              ),
            for (final (code, text) in const [
              (Asset.conditionNew, 'New'),
              (Asset.conditionGood, 'Good'),
              (Asset.conditionFair, 'Fair'),
              (Asset.conditionPoor, 'Poor'),
            ])
              DropdownMenuItem<String>(value: code, child: Text(text)),
          ],
          onChanged: onChanged,
        ),
      ),
    ),
  ],
);

/// A label in the shape `LabeledTextField` draws its own, so a dropdown and
/// a text field stacked in one sheet do not read as two different kinds of
/// form.
const _labelStyle = TextStyle(fontSize: 13, fontWeight: FontWeight.w500);

class _AssignSheet extends ConsumerStatefulWidget {
  const _AssignSheet();

  @override
  ConsumerState<_AssignSheet> createState() => _AssignSheetState();
}

class _AssignSheetState extends ConsumerState<_AssignSheet> {
  late final TextEditingController _date;
  late final TextEditingController _expected;
  late final TextEditingController _remarks;

  int? _employeeId;
  String? _employeeName;
  String _condition = Asset.conditionNew;
  String? _error;

  @override
  void initState() {
    super.initState();
    _date = TextEditingController(text: _today());
    _expected = TextEditingController();
    _remarks = TextEditingController();
  }

  @override
  void dispose() {
    _date.dispose();
    _expected.dispose();
    _remarks.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _sheet(
      context,
      title: 'Hand this asset to somebody',
      children: [
        if (_error != null)
          Padding(
            key: const ValueKey('assign-error'),
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        RemotePickerField<Employee>(
          key: const ValueKey('assign-employee'),
          label: 'Who is taking it',
          provider: employeesPickerProvider,
          idOf: (employee) => employee.id,
          labelOf: (employee) => employee.fullName,
          value: _employeeId,
          selectedLabel: _employeeName,
          isRequired: true,
          searchHint: 'Search people',
          onChanged: (id) => setState(() {
            _employeeId = id;
            _employeeName = null;
            _error = null;
          }),
        ),
        DateField(
          key: const ValueKey('assign-date'),
          label: 'Handed over on',
          controller: _date,
          isRequired: true,
        ),
        DateField(
          key: const ValueKey('assign-expected-return'),
          label: 'Expected back on',
          controller: _expected,
          allowEmpty: true,
          helper:
              'Leave empty for an open-ended loan — it is not "late", '
              'it simply has no deadline.',
        ),
        _conditionField(
          key: const ValueKey('assign-condition'),
          label: 'Condition going out',
          value: _condition,
          onChanged: (value) =>
              setState(() => _condition = value ?? Asset.conditionNew),
        ),
        const SizedBox(height: 8),
        LabeledTextField(
          key: const ValueKey('assign-remarks'),
          label: 'Remarks',
          controller: _remarks,
          maxLines: 3,
          hint: 'Optional',
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('confirm-assign'),
          onPressed: () {
            if (_employeeId == null) {
              setState(() => _error = 'Choose who is taking it.');
              return;
            }

            final handOver = DateTime.tryParse(_date.text.trim());
            final expected = DateTime.tryParse(_expected.text.trim());

            // The server's own `after_or_equal` rule, caught here so a
            // person is told while the sheet is still open — because after
            // it pops there is nowhere for a field error to go but a
            // banner. Everything else is the server's.
            if (handOver != null &&
                expected != null &&
                expected.isBefore(handOver)) {
              setState(
                () => _error =
                    'The expected return date cannot be before the '
                    'hand-over date.',
              );
              return;
            }

            final remarks = _remarks.text.trim();
            final expectedText = _expected.text.trim();

            Navigator.of(context).pop(<String, Object?>{
              'employee_id': _employeeId,
              'assigned_date': _date.text.trim(),
              if (expectedText.isNotEmpty) 'expected_return_date': expectedText,
              'assigned_condition': _condition,
              if (remarks.isNotEmpty) 'remarks': remarks,
            });
          },
          child: const Text('Hand over'),
        ),
      ],
    );
  }
}

class _ReturnSheet extends StatefulWidget {
  const _ReturnSheet();

  @override
  State<_ReturnSheet> createState() => _ReturnSheetState();
}

class _ReturnSheetState extends State<_ReturnSheet> {
  late final TextEditingController _date;
  late final TextEditingController _remarks;

  /// Nullable on purpose — see [_conditionField]. Nothing about a hand-back
  /// is known until somebody looks, and a default here would be this sheet
  /// claiming to have seen the damage.
  String? _condition;
  String? _error;

  @override
  void initState() {
    super.initState();
    _date = TextEditingController(text: _today());
    _remarks = TextEditingController();
  }

  @override
  void dispose() {
    _date.dispose();
    _remarks.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _sheet(
      context,
      title: 'Take this asset back',
      children: [
        if (_error != null)
          Padding(
            key: const ValueKey('return-error'),
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        Text(
          'What came back *is* the asset’s condition now — and if it came '
          'back poor it goes to maintenance rather than back on the shelf.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        DateField(
          key: const ValueKey('return-date'),
          label: 'Returned on',
          controller: _date,
          isRequired: true,
        ),
        _conditionField(
          key: const ValueKey('return-condition'),
          label: 'Condition coming back *',
          value: _condition,
          placeholder: true,
          onChanged: (value) => setState(() {
            _condition = value;
            _error = null;
          }),
        ),
        const SizedBox(height: 8),
        LabeledTextField(
          key: const ValueKey('return-remarks'),
          label: 'Remarks',
          controller: _remarks,
          maxLines: 3,
          hint: 'Optional',
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const ValueKey('confirm-return'),
          onPressed: () {
            final condition = _condition;

            if (condition == null || condition.isEmpty) {
              setState(() => _error = 'Say what condition it came back in.');
              return;
            }

            final remarks = _remarks.text.trim();

            Navigator.of(context).pop(<String, Object?>{
              'returned_date': _date.text.trim(),
              'returned_condition': condition,
              if (remarks.isNotEmpty) 'remarks': remarks,
            });
          },
          child: const Text('Take back'),
        ),
      ],
    );
  }
}

class _StatusSheet extends StatefulWidget {
  const _StatusSheet({required this.targets, required this.current});

  final List<String> targets;
  final String current;

  @override
  State<_StatusSheet> createState() => _StatusSheetState();
}

class _StatusSheetState extends State<_StatusSheet> {
  late final TextEditingController _notes;

  /// One legal move means there is no choice to make, so the sheet offers
  /// that move rather than a blank that has to be filled in first — and it
  /// is *the* legal move, because this list was derived from the same
  /// transition map the server enforces. Several moves means a real choice,
  /// and a real choice starts at `''` so nothing is decided on the reader's
  /// behalf by whichever target happened to be first.
  late String _target;

  @override
  void initState() {
    super.initState();
    _notes = TextEditingController();
    _target = widget.targets.length == 1 ? widget.targets.first : '';
  }

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return _sheet(
      context,
      title: 'Change status',
      children: [
        Text(
          'From ${_label(widget.current)}. The register only offers the '
          'moves the transition map allows, and the server re-checks every '
          'one of them under a lock.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        if (widget.targets.isEmpty)
          Text(
            'There is no status this asset can move to. Ask for it to be '
            'returned first, or write it off from somewhere it is not '
            'currently held.',
            key: const ValueKey('status-no-targets'),
            style: theme.textTheme.bodyMedium,
          )
        else ...[
          Text('Move to', style: theme.textTheme.labelLarge),
          const SizedBox(height: 6),
          InputDecorator(
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              contentPadding: EdgeInsets.symmetric(
                horizontal: 12,
                vertical: 12,
              ),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<String>(
                key: const ValueKey('status-target'),
                isExpanded: true,
                value: _target,
                items: [
                  const DropdownMenuItem<String>(
                    value: '',
                    child: Text('Choose a status'),
                  ),
                  for (final target in widget.targets)
                    DropdownMenuItem<String>(
                      value: target,
                      child: Text(_label(target)),
                    ),
                ],
                onChanged: (value) => setState(() => _target = value ?? ''),
              ),
            ),
          ),
          const SizedBox(height: 12),
          LabeledTextField(
            key: const ValueKey('status-notes'),
            label: 'Why',
            controller: _notes,
            maxLines: 3,
            hint: 'Optional',
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const ValueKey('confirm-status'),
            onPressed: () {
              if (_target.isEmpty) return;

              final notes = _notes.text.trim();

              Navigator.of(context).pop(<String, Object?>{
                'status': _target,
                if (notes.isNotEmpty) 'notes': notes,
              });
            },
            child: const Text('Change status'),
          ),
        ],
      ],
    );
  }

  static String _label(String status) => switch (status) {
    Asset.statusAvailable => 'Available',
    Asset.statusAssigned => 'Assigned',
    Asset.statusMaintenance => 'In maintenance',
    Asset.statusDamaged => 'Damaged',
    Asset.statusLost => 'Lost',
    Asset.statusRetired => 'Retired',
    _ => status,
  };
}
