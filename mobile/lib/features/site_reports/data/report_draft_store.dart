import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Where a half-finished report lives while nobody is looking.
///
/// **This is not offline sync, and nothing here should be described as
/// though it were.** The queue under `features/attendance` holds events the
/// server has not seen and will replay; a draft is a convenience that exists
/// only on this phone, is overwritten by the next keystroke worth keeping,
/// and is forgotten the moment a report is actually filed. A report that
/// fails to send is *not* shelved locally for later — it is shown as a
/// failure, because quietly filing away a document a site supervisor
/// believes was submitted is worse than an honest error.
///
/// Two rules make that boundary legible rather than a promise in a comment:
///
///  - only the **create** forms keep a draft. Editing a server-side draft
///    already has a durable copy; a second, local one would mean two
///    records that disagree after the first save.
///  - a restored draft says so on screen and offers "Start over", so the
///    text a person reads is never quietly older than they remember.
abstract class ReportDraftStore {
  /// The saved draft for [slot], or null when there is none.
  Future<Map<String, Object?>?> read(String slot);

  /// Records [draft] under [slot]. Debounced: visible to [read] at once,
  /// written through to storage after a short pause.
  Future<void> write(String slot, Map<String, Object?> draft);

  /// Forgets [slot], cancelling any write still waiting out its debounce —
  /// so "the report was filed, drop the local copy" cannot be undone by a
  /// timer that was already in flight.
  Future<void> clear(String slot);

  /// Runs any pending write now. Screens do not need it: the debounce
  /// exists to avoid one storage write per keystroke, not to defer work
  /// past the moment anybody cares. Tests do.
  Future<void> flush();
}

/// The slot names. Create-only by design; see the class note above.
const String activityDraftSlot = 'activity:new';

const String dailyDraftSlot = 'daily:new';

final reportDraftStoreProvider = Provider<ReportDraftStore>((ref) {
  final store = SharedPreferencesReportDraftStore();
  ref.onDispose(store.dispose);

  return store;
});

/// [ReportDraftStore] over [SharedPreferences].
///
/// Drafts are one JSON document under one key rather than one key per
/// field: the two slots are tiny, and a schema that gains a field should
/// not have to remember to clean up its own leftovers first.
class SharedPreferencesReportDraftStore implements ReportDraftStore {
  SharedPreferencesReportDraftStore([this._storage]);

  static const storageKey = 'site_reports.drafts.v1';

  /// One write per pause rather than one per keystroke. Long enough that a
  /// person typing a sentence costs a single round-trip to the store,
  /// short enough that an app killed between two taps keeps most of the
  /// sentence.
  static const debounce = Duration(milliseconds: 600);

  final SharedPreferences? _storage;
  SharedPreferences? _resolved;
  Timer? _timer;

  final Map<String, Map<String, Object?>> _cache =
      <String, Map<String, Object?>>{};

  bool _hydrated = false;

  Future<SharedPreferences> get _prefs async =>
      _resolved ??= _storage ?? await SharedPreferences.getInstance();

  Future<void> _hydrate() async {
    if (_hydrated) return;

    final raw = (await _prefs).getString(storageKey);

    if (raw != null) {
      try {
        final decoded = jsonDecode(raw);

        if (decoded is Map<String, dynamic>) {
          decoded.forEach((slot, value) {
            if (value is Map<String, dynamic>) {
              _cache[slot] = Map<String, Object?>.from(value);
            }
          });
        }
      } catch (_) {
        // A draft is not worth an error state. Unreadable means "none",
        // and the next save writes a clean document over the top.
        _cache.clear();
      }
    }

    _hydrated = true;
  }

  @override
  Future<Map<String, Object?>?> read(String slot) async {
    await _hydrate();

    final draft = _cache[slot];

    return draft == null ? null : Map<String, Object?>.unmodifiable(draft);
  }

  @override
  Future<void> write(String slot, Map<String, Object?> draft) async {
    await _hydrate();

    _cache[slot] = draft;

    _timer?.cancel();
    _timer = Timer(debounce, () {
      _timer = null;
      unawaited(_flushCache());
    });
  }

  @override
  Future<void> clear(String slot) async {
    await _hydrate();

    _timer?.cancel();
    _timer = null;

    if (_cache.remove(slot) == null) return;

    await _flushCache();
  }

  @override
  Future<void> flush() async {
    await _hydrate();

    _timer?.cancel();
    _timer = null;

    await _flushCache();
  }

  void dispose() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _flushCache() async {
    final prefs = await _prefs;

    if (_cache.isEmpty) {
      await prefs.remove(storageKey);
      return;
    }

    await prefs.setString(storageKey, jsonEncode(_cache));
  }
}
