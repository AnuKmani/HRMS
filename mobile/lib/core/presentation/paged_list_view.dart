import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'list_state.dart';

/// The four things a list screen can honestly be showing, in the order they
/// are decided.
///
/// Every module list in this app renders through here, which is the point:
/// "loading", "nothing matched", "we could not load it" and "here are the
/// rows" look the same everywhere because they are computed from one
/// [ListState] rather than re-invented five times — and a screen that gets a
/// state it has not drawn yet falls through to something sensible rather than
/// a blank body.
class PagedListView<T> extends ConsumerWidget {
  const PagedListView({
    super.key,
    required this.provider,
    required this.itemBuilder,
    required this.emptyMessage,
    this.searchHint,
    this.filter,
    this.emptyHint,
  });

  /// The feature's list controller. Subtypes are compatible with
  /// `NotifierProvider<PagedListController<T>, ListState<T>>` because Dart's
  /// generics are covariant, so this stays generic without the screen having
  /// to name the controller class.
  final NotifierProvider<PagedListController<T>, ListState<T>> provider;

  final Widget Function(BuildContext context, T item, int index) itemBuilder;

  /// Shown when the request succeeded and returned nothing. Written to say
  /// what the user can do about it, not merely that the array was empty.
  final String emptyMessage;

  /// A secondary line under [emptyMessage] — most useful for explaining a
  /// filter that is narrowing the result to zero.
  final String? emptyHint;

  /// Null hides the search box entirely (a list nobody would search).
  final String? searchHint;

  /// Screen-specific controls — status dropdowns, department filters — drawn
  /// between the search box and the results.
  final Widget? filter;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(provider);
    final controller = ref.read(provider.notifier);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (searchHint != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(
              key: const ValueKey('list-search'),
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: searchHint,
                prefixIcon: const Icon(Icons.search),
                isDense: true,
                border: const OutlineInputBorder(),
              ),
              onSubmitted: controller.setSearch,
            ),
          ),
        if (filter != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: filter!,
          ),
        Expanded(child: _body(context, ref, state, controller, theme)),
      ],
    );
  }

  Widget _body(
    BuildContext context,
    WidgetRef ref,
    ListState<T> state,
    PagedListController<T> controller,
    ThemeData theme,
  ) {
    // Nothing fetched yet, or a request in flight with nothing on screen: a
    // spinner is the only honest answer. Showing `emptyMessage` here would
    // tell a user "no records" a moment before the records arrive.
    if (state.isBlank) {
      return const Center(
        child: CircularProgressIndicator(key: ValueKey('list-loading')),
      );
    }

    if (state.showError) {
      return _ListError(
        message: state.message,
        onRetry: controller.reload,
      );
    }

    if (state.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.inbox_outlined, size: 48, color: theme.hintColor),
              const SizedBox(height: 12),
              Text(
                emptyMessage,
                key: const ValueKey('list-empty'),
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyLarge,
              ),
              if (emptyHint != null) ...[
                const SizedBox(height: 4),
                Text(
                  emptyHint!,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ],
          ),
        ),
      );
    }

    // Refresh failed but the previous page is still on screen: keep it, and
    // say so on top. Throwing away rows the user was reading because a
    // refresh could not reach the server would be a worse outcome than the
    // failure itself.
    return RefreshIndicator(
      onRefresh: controller.reload,
      child: ListView(
        key: const ValueKey('list-results'),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (state.showErrorBanner)
            _ListBanner(message: state.message, onRetry: controller.reload),
          if (state.isLoading) const LinearProgressIndicator(minHeight: 2),
          for (var index = 0; index < state.items.length; index++)
            itemBuilder(context, state.items[index], index),
          if (state.loadingMore)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(
                child: SizedBox(
                  width: 24,
                  height: 24,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            )
          else if (state.hasNext)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: TextButton(
                key: const ValueKey('list-load-more'),
                onPressed: controller.loadMore,
                child: Text(
                  'Load more (${state.total - state.items.length} remaining)',
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ListError extends StatelessWidget {
  const _ListError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 48, color: theme.colorScheme.error),
            const SizedBox(height: 12),
            Text(
              message,
              key: const ValueKey('list-error'),
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyLarge,
            ),
            const SizedBox(height: 16),
            OutlinedButton.icon(
              key: const ValueKey('list-retry'),
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('Try again'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ListBanner extends StatelessWidget {
  const _ListBanner({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Material(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                message,
                style: TextStyle(color: scheme.onErrorContainer),
              ),
            ),
            TextButton(
              onPressed: onRetry,
              child: const Text('Retry'),
            ),
          ],
        ),
      ),
    );
  }
}
