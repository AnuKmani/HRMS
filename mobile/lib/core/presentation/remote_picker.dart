import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'list_state.dart';

/// A dropdown whose options come from an endpoint rather than a constant.
///
/// A `DropdownButton` with every row the server has would be the wrong shape
/// for employees or sites: neither list is small, and a form should not pay
/// for a thousand options to offer one. This opens a searchable sheet instead,
/// so the field costs one tap and no rows until the user asks for them.
///
/// The selection is expressed as an id, while the *label* shown beside it may
/// come from the caller ([selectedLabel]) — a form editing an existing record
/// already knows the name of whoever it points at, and should not have to
/// re-download a list to draw one word.
class RemotePickerField<T> extends ConsumerWidget {
  const RemotePickerField({
    super.key,
    required this.label,
    required this.provider,
    required this.idOf,
    required this.labelOf,
    required this.value,
    required this.onChanged,
    this.selectedLabel,
    this.errorText,
    this.isRequired = false,
    this.hint = 'Not set',
    this.searchHint,
    this.sheetTitle,
    this.helper,
  });

  final String label;

  final NotifierProvider<PagedListController<T>, ListState<T>> provider;

  final int Function(T item) idOf;
  final String Function(T item) labelOf;

  final int? value;

  /// The label for [value] when it is not (or not yet) in the loaded page.
  final String? selectedLabel;

  final ValueChanged<int?> onChanged;

  final String? errorText;
  final bool isRequired;
  final String hint;
  final String? searchHint;
  final String? sheetTitle;

  /// A line under the control explaining what it is for — the same
  /// courtesy `LabeledTextField` gives, and for the same reason: a field
  /// whose consequence is invisible (a site that has to belong to a chosen
  /// project) is a field people fill in wrong and only learn about from the
  /// refusal.
  final String? helper;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final state = ref.watch(provider);

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
          InkWell(
            onTap: () => _openSheet(context, ref),
            borderRadius: BorderRadius.circular(4),
            child: InputDecorator(
              decoration: InputDecoration(
                errorText: errorText,
                helperText: errorText == null ? helper : null,
                suffixIcon: const Icon(Icons.arrow_drop_down),
                border: const OutlineInputBorder(),
                isDense: true,
              ),
              child: Text(
                _display(state),
                key: const ValueKey('picker-value'),
                style: value == null ? theme.textTheme.bodyMedium : null,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _display(ListState<T> state) {
    if (value == null) return hint;

    for (final item in state.items) {
      if (idOf(item) == value) return labelOf(item);
    }

    return selectedLabel ?? '#$value';
  }

  Future<void> _openSheet(BuildContext context, WidgetRef ref) async {
    final controller = ref.read(provider.notifier);
    final state = ref.read(provider);

    // A fresh opening starts from the whole set — otherwise a search the user
    // ran three forms ago silently narrows this one, with no visible sign of
    // why half the options are missing.
    if (state.query.isNotEmpty) {
      controller.setQuery(const <String, Object?>{});
    } else if (state.items.isEmpty) {
      unawaited(controller.reload());
    }

    final picked = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _PickerSheet<T>(
        provider: provider,
        idOf: idOf,
        labelOf: labelOf,
        current: value,
        searchHint: searchHint,
        title: sheetTitle ?? label,
      ),
    );

    if (!context.mounted) return;

    if (picked != null) onChanged(picked);
  }
}

class _PickerSheet<T> extends ConsumerStatefulWidget {
  const _PickerSheet({
    required this.provider,
    required this.idOf,
    required this.labelOf,
    required this.current,
    required this.title,
    this.searchHint,
  });

  final NotifierProvider<PagedListController<T>, ListState<T>> provider;
  final int Function(T item) idOf;
  final String Function(T item) labelOf;
  final int? current;
  final String title;
  final String? searchHint;

  @override
  ConsumerState<_PickerSheet<T>> createState() => _PickerSheetState<T>();
}

class _PickerSheetState<T> extends ConsumerState<_PickerSheet<T>> {
  Timer? _debounce;
  late final TextEditingController _search;

  @override
  void initState() {
    super.initState();
    _search = TextEditingController();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// Debounced so one keystroke per row of a long term does not become a
  /// request per keystroke against a database.
  void _onSearchChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (!mounted) return;
      ref.read(widget.provider.notifier).setSearch(value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(widget.provider);
    final controller = ref.read(widget.provider.notifier);
    final theme = Theme.of(context);

    return SafeArea(
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.65,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      widget.title,
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  IconButton(
                    tooltip: 'Close',
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
            ),
            if (widget.searchHint != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: TextField(
                  controller: _search,
                  onChanged: _onSearchChanged,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: widget.searchHint,
                    prefixIcon: const Icon(Icons.search),
                    isDense: true,
                    border: const OutlineInputBorder(),
                  ),
                ),
              ),
            Expanded(child: _results(state, controller, theme)),
          ],
        ),
      ),
    );
  }

  Widget _results(
    ListState<T> state,
    PagedListController<T> controller,
    ThemeData theme,
  ) {
    if (state.isBlank) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.showError) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(state.message, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: controller.reload,
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    if (state.isEmpty) {
      return Center(
        child: Text('No matches.', style: theme.textTheme.bodyMedium),
      );
    }

    return ListView(
      children: [
        for (final item in state.items)
          ListTile(
            selected: widget.idOf(item) == widget.current,
            title: Text(widget.labelOf(item)),
            trailing: widget.idOf(item) == widget.current
                ? const Icon(Icons.check)
                : null,
            onTap: () => Navigator.of(context).pop(widget.idOf(item)),
          ),
        if (state.hasNext)
          ListTile(
            title: const Center(child: Text('Load more')),
            onTap: controller.loadMore,
          ),
      ],
    );
  }
}
