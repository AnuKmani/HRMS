import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/page_result.dart';
import '../network/api_exception.dart';

/// What the *most recent* load of a list did.
///
/// Deliberately four values rather than a boolean pair: `loading` and
/// `failed` at the same time is a real and distinct moment (a refresh in
/// flight while the previous attempt's error is still on screen), and
/// collapsing it would make "is this a spinner or a retry button?" a
/// guesswork of ordering.
enum ListStatus {
  /// Nothing has been requested yet.
  initial,

  /// The first page is in flight.
  loading,

  /// The last load succeeded. [ListState.items] may still be empty — that is
  /// "there is nothing to show", which is a different screen from "we do not
  /// know yet".
  ready,

  /// The last load failed and there is nothing on screen to fall back on.
  failure,
}

class ListState<T> {
  const ListState({
    this.status = ListStatus.initial,
    this.items = const [],
    this.message = '',
    this.query = const <String, Object?>{},
    this.page = 1,
    this.lastPage = 1,
    this.perPage = 0,
    this.total = 0,
    this.loadingMore = false,
  });

  final ListStatus status;

  /// Everything fetched so far. A `loadMore` appends; anything else replaces.
  final List<T> items;

  /// The server's sentence for the failure in [status], or `''`.
  ///
  /// Always the envelope's own message — never an exception's `toString()` —
  /// because the backend writes these to be shown to a person.
  final String message;

  /// The filters this list was last fetched with, echoed back so a screen can
  /// show what it is currently filtering by without keeping a second copy.
  final Map<String, Object?> query;

  final int page;
  final int lastPage;
  final int perPage;
  final int total;

  /// A page after the first is in flight.
  final bool loadingMore;

  bool get isInitial => status == ListStatus.initial;

  bool get isLoading => status == ListStatus.loading;

  bool get isReady => status == ListStatus.ready;

  bool get hasFailed => status == ListStatus.failure;

  /// Nothing on screen yet — the caller should draw a spinner rather than an
  /// empty-state message, because the honest answer is still pending.
  bool get isBlank => items.isEmpty && (isInitial || isLoading);

  /// Loaded successfully and found nothing. A real, showable result.
  bool get isEmpty => items.isEmpty && isReady;

  /// Loaded, failed, and with nothing to show instead of the error.
  bool get showError => items.isEmpty && hasFailed;

  /// A failure worth a banner on top of a list that still has content —
  /// a refresh that failed must not throw away what the user was reading.
  bool get showErrorBanner => items.isNotEmpty && hasFailed;

  bool get hasNext => page < lastPage;

  ListState<T> copyWith({
    ListStatus? status,
    List<T>? items,
    String? message,
    Map<String, Object?>? query,
    int? page,
    int? lastPage,
    int? perPage,
    int? total,
    bool? loadingMore,
  }) =>
      ListState<T>(
        status: status ?? this.status,
        items: items ?? this.items,
        message: message ?? this.message,
        query: query ?? this.query,
        page: page ?? this.page,
        lastPage: lastPage ?? this.lastPage,
        perPage: perPage ?? this.perPage,
        total: total ?? this.total,
        loadingMore: loadingMore ?? this.loadingMore,
      );
}

/// The behaviour every list screen shares: fetch page one, ask for more,
/// change the filter, try again after a failure.
///
/// Subclasses supply exactly one thing — how to fetch — so that the five
/// module lists cannot drift apart on when they clear their items, whether a
/// failed refresh hides a list the user was reading, or what happens to a
/// response that arrives after the filter was already changed underneath it.
///
/// That last case is why [_generation] exists. Two requests for different
/// filters can be in flight at once, and the slower one must not overwrite
/// the faster one's results: the screen would then be showing one set of rows
/// under another set's filter description.
abstract class PagedListController<T> extends Notifier<ListState<T>> {
  /// Fetch one page from the API. Implemented per feature; everything else
  /// here is shared.
  Future<PageResult<T>> fetch({
    required int page,
    required Map<String, Object?> query,
  });

  /// The filters the list starts with. Rarely anything but `const {}`.
  Map<String, Object?> get initialQuery => const <String, Object?>{};

  /// Parameters every request carries regardless of what the user filtered
  /// by — merged in *under* [state.query], so a caller cannot override them.
  ///
  /// The picker subclasses use it for `per_page`: a dropdown needs enough
  /// rows to be worth opening, while the list screens are happy with the
  /// server's default page size.
  Map<String, Object?> get baseQuery => const <String, Object?>{};

  /// Bumped by every start-over and by [build], so a response from a
  /// superseded request can be recognised and dropped.
  int _generation = 0;

  /// Set by [ref.onDispose]. A response that lands after the list was torn
  /// down must not touch state that no longer has a listener — and unlike a
  /// provider rebuild, disposal is not something a generation counter alone
  /// can tell apart from "a newer request is also in flight".
  bool _disposed = false;

  @override
  ListState<T> build() {
    _generation++;
    _disposed = false;

    ref.onDispose(() => _disposed = true);

    Future.microtask(reload);

    return ListState<T>(query: initialQuery);
  }

  /// Fetch page one again, keeping whatever is already on screen.
  ///
  /// Used for the retry button and for pull-to-refresh. Keeping the items is
  /// deliberate: a failed refresh should leave the previous results visible
  /// with an apology on top, not blank a list the user was in the middle of.
  Future<void> reload() => _load(reset: true);

  /// Alias that reads better at a call site that has never loaded at all.
  Future<void> retry() => reload();

  Future<void> loadMore() {
    if (!state.hasNext || state.loadingMore || state.isLoading) {
      return Future<void>.value();
    }

    return _load(reset: false);
  }

  /// Replace the filters and start over.
  ///
  /// The old items go immediately: keeping them would show rows that do not
  /// match the filters the header is already describing.
  void setQuery(Map<String, Object?> query) {
    state = state.copyWith(
      query: query,
      items: <T>[],
      status: ListStatus.loading,
      message: '',
      page: 1,
      lastPage: 1,
      total: 0,
      loadingMore: false,
    );

    unawaited(_load(reset: true));
  }

  /// Convenience for the search box: sets or clears `search` and leaves the
  /// other filters alone.
  void setSearch(String term) {
    final query = Map<String, Object?>.of(state.query);
    final trimmed = term.trim();

    if (trimmed.isEmpty) {
      query.remove('search');
    } else {
      query['search'] = trimmed;
    }

    setQuery(query);
  }

  /// Set or clear one named filter, keeping every other one the screen has
  /// already applied. Passing `null` or `''` removes the key rather than
  /// sending an empty value, because `status=` and no `status` at all are
  /// different questions to the API.
  void setFilter(String key, Object? value) {
    final query = Map<String, Object?>.of(state.query);

    if (value == null || value == '') {
      query.remove(key);
    } else {
      query[key] = value;
    }

    setQuery(query);
  }

  Future<void> _load({required bool reset}) async {
    final generation = reset ? ++_generation : _generation;

    state = state.copyWith(
      status: ListStatus.loading,
      message: '',
      loadingMore: !reset,
    );

    try {
      final result = await fetch(
        page: reset ? 1 : state.page + 1,
        query: <String, Object?>{...baseQuery, ...state.query},
      );

      if (_disposed || generation != _generation) return;

      state = state.copyWith(
        status: ListStatus.ready,
        items: reset
            ? result.items
            : <T>[...state.items, ...result.items],
        page: result.currentPage,
        lastPage: result.lastPage,
        perPage: result.perPage,
        total: result.total,
        message: '',
        loadingMore: false,
      );
    } catch (error) {
      if (_disposed || generation != _generation) return;

      state = state.copyWith(
        status: ListStatus.failure,
        message: _messageFor(error),
        loadingMore: false,
      );
    }
  }

  /// A sentence the list can show as-is.
  ///
  /// [ApiException] already carries the server's own wording; anything else
  /// is a programming error and gets copy written for a person rather than a
  /// stack trace that would mean nothing to them.
  static String _messageFor(Object error) => error is ApiException
      ? error.message
      : 'Something went wrong while loading this list. Please try again.';
}
