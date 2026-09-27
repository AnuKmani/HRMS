import 'dart:async';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/storage/device_identity.dart';
import '../data/api_attendance_repository.dart';
import '../data/client_event_id.dart';
import '../data/device_location.dart';
import '../data/offline_queue.dart';
import '../data/selfie_compressor.dart';
import '../domain/assigned_site.dart';
import '../domain/attendance_record.dart';
import '../domain/attendance_repository.dart';
import '../domain/location_fix.dart';
import '../domain/today_status.dart';

final attendanceControllerProvider =
    NotifierProvider<AttendanceController, AttendanceState>(
      AttendanceController.new,
    );

/// How the screen has loaded, and what it is doing right now.
enum AttendancePhase {
  /// The first `load()` has not finished. Nothing on screen is actionable —
  /// offering a CHECK IN button before knowing whether one is possible would
  /// be a button that sometimes does nothing.
  loading,

  /// [AttendanceState.today] is populated.
  ready,

  /// The request failed and nothing has replaced it yet.
  failed,
}

class AttendanceState {
  const AttendanceState({
    this.phase = AttendancePhase.loading,
    this.today,
    this.visits = const <SiteVisit>[],
    this.pending = const <OfflineEvent>[],
    this.error,
    this.submitting = false,
    this.submitError,
    this.notice,
    this.location = const LocationCondition(
      LocationStatus.notRequested,
      'Location has not been checked yet.',
    ),
    this.fix,
    this.selectedSiteId,
    this.syncing = false,
  });

  final AttendancePhase phase;
  final TodayStatus? today;
  final List<SiteVisit> visits;
  final List<OfflineEvent> pending;

  /// Load failure — nothing else on the screen can be trusted.
  final String? error;

  /// True while a check-in / check-out / visit request owns the flow.
  ///
  /// Every big button reads this and disables itself. It is the *only*
  /// guard against a double tap, and it is why the guard exists: the server
  /// would catch a second row anyway, but a person who taps a button that
  /// looks live should not have to wait for a 409 to find out it was not.
  final bool submitting;

  final String? submitError;

  /// A one-off success worth saying out loud — "Queued for sync",
  /// "Checked in" — cleared on the next action.
  final String? notice;

  final LocationCondition location;
  final LocationFix? fix;
  final int? selectedSiteId;

  final bool syncing;

  bool get isReady => phase == AttendancePhase.ready;

  bool get canSubmit => isReady && !submitting && !syncing;

  /// The site every action acts on.
  AssignedSite? get selectedSite {
    final today = this.today;
    if (today == null) return null;

    final wanted = selectedSiteId;
    if (wanted != null) {
      final match = today.siteById(wanted);
      if (match != null) return match;
    }

    return today.site;
  }

  SiteVisit? get openVisit {
    for (final visit in visits) {
      if (visit.isOpen) return visit;
    }
    return null;
  }

  /// How far off the advisory geofence is for the currently selected site.
  ///
  /// Advisory, and labelled as such on screen: the server recomputes this
  /// with the same formula and its answer is the one that decides.
  GeofenceVerdict? get geofence {
    final site = selectedSite;
    final fix = this.fix;

    if (site == null || fix == null) return null;

    return LocalGeofence.assess(
      siteLatitude: site.latitude,
      siteLongitude: site.longitude,
      radiusMetres: site.geofenceRadius,
      fix: fix,
      maxAccuracyMetres: today?.maxGpsAccuracyMetres ?? 100,
    );
  }

  AttendanceState copyWith({
    AttendancePhase? phase,
    TodayStatus? today,
    List<SiteVisit>? visits,
    List<OfflineEvent>? pending,
    Object? error = _unset,
    bool? submitting,
    Object? submitError = _unset,
    Object? notice = _unset,
    LocationCondition? location,
    Object? fix = _unset,
    Object? selectedSiteId = _unset,
    bool? syncing,
  }) => AttendanceState(
    phase: phase ?? this.phase,
    today: today ?? this.today,
    visits: visits ?? this.visits,
    pending: pending ?? this.pending,
    error: identical(error, _unset) ? this.error : error as String?,
    submitting: submitting ?? this.submitting,
    submitError: identical(submitError, _unset)
        ? this.submitError
        : submitError as String?,
    notice: identical(notice, _unset) ? this.notice : notice as String?,
    location: location ?? this.location,
    fix: identical(fix, _unset) ? this.fix : fix as LocationFix?,
    selectedSiteId: identical(selectedSiteId, _unset)
        ? this.selectedSiteId
        : selectedSiteId as int?,
    syncing: syncing ?? this.syncing,
  );
}

const Object _unset = Object();

/// Owns the check-in screen: what today looks like, where the phone is, and
/// the four things a person can do about it.
///
/// Three rules shape every method here:
///
///  * **one submit at a time.** [AttendanceState.submitting] gates every
///    action, so a double tap cannot produce two requests;
///  * **the server decides.** Nothing in this class computes a status; it
///    sends a reading and renders what comes back;
///  * **a network failure is not a rejection.** When the request never
///    reaches the server, the act is queued under a key generated *before*
///    the attempt, so a later retry is the same event rather than a second.
class AttendanceController extends Notifier<AttendanceState> {
  late AttendanceRepository _repository;
  late LocationGateway _location;
  late OfflineQueue _queue;
  late SelfieCompressor _compressor;
  late DeviceIdentity _device;

  @override
  AttendanceState build() {
    _repository = ref.watch(attendanceRepositoryProvider);
    _location = ref.watch(locationGatewayProvider);
    _queue = ref.watch(offlineQueueProvider);
    _compressor = ref.watch(selfieCompressorProvider);
    _device = ref.watch(deviceIdentityProvider);

    return const AttendanceState();
  }

  /// Loads today, the visits and the queue, then a location reading — in
  /// that order of importance, so a slow satellite lock never delays the
  /// screen.
  Future<void> load() async {
    state = state.copyWith(
      phase: AttendancePhase.loading,
      error: null,
      submitError: null,
      notice: null,
    );

    try {
      final today = await _repository.today();
      final visits = await _repository.siteVisitsToday();
      final pending = await _queue.all();

      state = state.copyWith(
        phase: AttendancePhase.ready,
        today: today,
        visits: visits,
        pending: pending,
        selectedSiteId: state.selectedSiteId ?? _defaultSiteId(today),
      );

      // Last, and never awaited into the phase: the screen is already
      // useful without a satellite lock.
      unawaited(refreshLocation());
    } on ApiException catch (failure) {
      state = state.copyWith(
        phase: AttendancePhase.failed,
        error: failure.message,
      );
    } catch (_) {
      state = state.copyWith(
        phase: AttendancePhase.failed,
        error: 'Something went wrong. Please try again.',
      );
    }
  }

  /// Re-reads what the server says without a full reload.
  Future<void> refresh() async {
    if (!state.isReady) return;

    try {
      state = state.copyWith(
        today: await _repository.today(),
        visits: await _repository.siteVisitsToday(),
        pending: await _queue.all(),
      );
    } on ApiException catch (failure) {
      state = state.copyWith(submitError: _explain(failure));
    } catch (_) {
      // A refresh that fails leaves the previous answer on screen. It is
      // stale rather than wrong, and telling someone their day vanished
      // because a refresh did is worse than saying nothing.
    }
  }

  /* ------------------------------------------------------------ location */

  /// Reads the current state without prompting anyone.
  Future<void> refreshLocation() => _resolveLocation(prompt: false);

  /// Asks — or, when asking is no longer possible, opens the right screen
  /// in Settings.
  Future<void> requestLocation() => _resolveLocation(prompt: true);

  Future<void> _resolveLocation({required bool prompt}) async {
    try {
      if (!await _location.isServiceEnabled()) {
        state = state.copyWith(
          location: const LocationCondition(
            LocationStatus.serviceDisabled,
            'GPS is switched off. Turn on location services to check in.',
          ),
          fix: null,
        );
        return;
      }

      var status = await _location.checkPermission();

      if (prompt) {
        if (status == LocationStatus.permanentlyDenied) {
          await _location.openAppSettings();
        } else if (status != LocationStatus.granted) {
          status = await _location.requestPermission();
        }
      }

      if (status != LocationStatus.granted) {
        state = state.copyWith(location: _condition(status), fix: null);
        return;
      }

      final fix = await _location.currentFix();

      state = state.copyWith(
        location: LocationCondition(
          LocationStatus.granted,
          'GPS ready · accuracy ±${fix.accuracy.round()} m',
        ),
        fix: fix,
      );
    } catch (failure) {
      state = state.copyWith(
        location: LocationCondition(
          LocationStatus.unavailable,
          failure is LocationAcquisitionFailed
              ? failure.message
              : 'Could not get a location reading. Try again.',
        ),
        fix: null,
      );
    }
  }

  LocationCondition _condition(LocationStatus status) => switch (status) {
    LocationStatus.granted => const LocationCondition(
      LocationStatus.granted,
      'GPS ready.',
    ),
    LocationStatus.notRequested => const LocationCondition(
      LocationStatus.notRequested,
      'This app has not asked for location yet.',
    ),
    LocationStatus.denied => const LocationCondition(
      LocationStatus.denied,
      'Location access is refused. Allow it to check in.',
    ),
    LocationStatus.permanentlyDenied => const LocationCondition(
      LocationStatus.permanentlyDenied,
      'Location access is blocked for this app. Open Settings to '
      'allow it, then come back.',
    ),
    LocationStatus.serviceDisabled => const LocationCondition(
      LocationStatus.serviceDisabled,
      'GPS is switched off. Turn on location services to check in.',
    ),
    LocationStatus.unavailable => const LocationCondition(
      LocationStatus.unavailable,
      'No location reading yet. Step outside and try again.',
    ),
  };

  void selectSite(int siteId) {
    state = state.copyWith(selectedSiteId: siteId, submitError: null);
  }

  void dismissFeedback() {
    state = state.copyWith(submitError: null, notice: null);
  }

  /* --------------------------------------------------------------- acts */

  /// Check in: compress the photograph, take a reading, submit.
  ///
  /// [rawSelfie] is what the camera produced. Compression happens here
  /// rather than in the capture sheet so a *queued* event is stored already
  /// compressed — the bytes that will eventually be uploaded are the bytes
  /// that were saved.
  Future<void> checkIn(Uint8List rawSelfie) async {
    if (!state.canSubmit) return;

    final site = state.selectedSite;
    if (site == null) {
      state = state.copyWith(
        submitError:
            'You have no site to check in at. Ask HR to post you '
            'to one.',
      );
      return;
    }

    state = state.copyWith(submitting: true, submitError: null, notice: null);

    // Generated before the request, not after it fails: if that first
    // attempt reached the server and only its response was lost, the queued
    // retry must present the same key or the day gets recorded twice.
    final clientEventId = newClientEventId();

    try {
      final bytes = await _compressor.compress(rawSelfie);
      final fix = await _fixForSubmission();

      if (fix == null) {
        state = state.copyWith(submitting: false);
        return;
      }

      await _repository.checkIn(
        CheckInSubmission(
          siteId: site.id,
          fix: fix,
          clientEventId: clientEventId,
          deviceReference: await _device.label(),
          selfieBytes: bytes,
        ),
      );

      state = state.copyWith(submitting: false, notice: 'Checked in.');
      await refresh();
    } on ApiException catch (failure) {
      await _failOrQueue(
        action: OfflineAction.checkIn,
        siteId: site.id,
        failure: failure,
        clientEventId: clientEventId,
        selfie: await _safeCompressed(rawSelfie),
      );
    } on SelfieRejected catch (failure) {
      state = state.copyWith(submitting: false, submitError: failure.message);
    } catch (_) {
      state = state.copyWith(
        submitting: false,
        submitError: 'Something went wrong. Please try again.',
      );
    }
  }

  Future<void> checkOut() async {
    if (!state.canSubmit) return;

    final siteId = state.selectedSite?.id ?? state.today?.attendance?.siteId;

    if (siteId == null) {
      state = state.copyWith(submitError: 'There is no site to check out of.');
      return;
    }

    state = state.copyWith(submitting: true, submitError: null, notice: null);

    final clientEventId = newClientEventId();

    try {
      final fix = await _fixForSubmission();

      if (fix == null) {
        state = state.copyWith(submitting: false);
        return;
      }

      await _repository.checkOut(
        CheckOutSubmission(
          siteId: siteId,
          fix: fix,
          clientEventId: clientEventId,
          deviceReference: await _device.label(),
        ),
      );

      state = state.copyWith(submitting: false, notice: 'Checked out.');
      await refresh();
    } on ApiException catch (failure) {
      await _failOrQueue(
        action: OfflineAction.checkOut,
        siteId: siteId,
        failure: failure,
        clientEventId: clientEventId,
      );
    } catch (_) {
      state = state.copyWith(
        submitting: false,
        submitError: 'Something went wrong. Please try again.',
      );
    }
  }

  Future<void> startVisit({required String purpose, String? remarks}) async {
    if (!state.canSubmit) return;

    // Checked here and not only in the dialog: the dialog is a courtesy,
    // the controller is the boundary, and a purpose of three spaces would
    // otherwise travel to the server as a reason to be at a site.
    final reason = purpose.trim();

    if (reason.isEmpty) {
      state = state.copyWith(submitError: 'A purpose is required.');
      return;
    }

    final notes = remarks?.trim();
    final note = notes == null || notes.isEmpty ? null : notes;

    final site = state.selectedSite;
    if (site == null) {
      state = state.copyWith(
        submitError: 'You have no site to visit. Ask HR to post you to one.',
      );
      return;
    }

    state = state.copyWith(submitting: true, submitError: null, notice: null);

    final clientEventId = newClientEventId();

    try {
      final fix = await _fixForSubmission();

      if (fix == null) {
        state = state.copyWith(submitting: false);
        return;
      }

      await _repository.startSiteVisit(
        SiteVisitStartSubmission(
          siteId: site.id,
          fix: fix,
          purpose: reason,
          remarks: note,
          clientEventId: clientEventId,
          deviceReference: await _device.label(),
        ),
      );

      state = state.copyWith(submitting: false, notice: 'Site visit started.');
      await refresh();
    } on ApiException catch (failure) {
      await _failOrQueue(
        action: OfflineAction.visitStart,
        siteId: site.id,
        failure: failure,
        clientEventId: clientEventId,
        purpose: reason,
        remarks: note,
      );
    } catch (_) {
      state = state.copyWith(
        submitting: false,
        submitError: 'Something went wrong. Please try again.',
      );
    }
  }

  Future<void> endVisit() async {
    if (!state.canSubmit) return;

    final visit = state.openVisit;
    if (visit == null) {
      state = state.copyWith(submitError: 'There is no open site visit.');
      return;
    }

    state = state.copyWith(submitting: true, submitError: null, notice: null);

    final clientEventId = newClientEventId();

    try {
      final fix = await _fixForSubmission();

      if (fix == null) {
        state = state.copyWith(submitting: false);
        return;
      }

      await _repository.endSiteVisit(
        SiteVisitEndSubmission(
          siteVisitId: visit.id,
          fix: fix,
          clientEventId: clientEventId,
        ),
      );

      state = state.copyWith(submitting: false, notice: 'Site visit ended.');
      await refresh();
    } on ApiException catch (failure) {
      await _failOrQueue(
        action: OfflineAction.visitEnd,
        siteId: visit.siteId,
        siteVisitId: visit.id,
        failure: failure,
        clientEventId: clientEventId,
      );
    } catch (_) {
      state = state.copyWith(
        submitting: false,
        submitError: 'Something went wrong. Please try again.',
      );
    }
  }

  /* ---------------------------------------------------------- the queue */

  /// Replays every queued event, oldest first.
  ///
  /// Manual, and that is the shipped step: an automatic synchroniser needs a
  /// connectivity signal and a backoff policy, and neither has been decided
  /// yet. What this guarantees instead is that replaying is *safe* — each
  /// row keeps the key it was queued with, so a retry that actually landed
  /// first is answered by the server with the original row rather than a
  /// twin.
  Future<void> syncPending() async {
    if (state.syncing || state.submitting) return;

    final queued = [...state.pending]
      ..sort((a, b) => a.capturedAt.compareTo(b.capturedAt));

    if (queued.isEmpty) return;

    state = state.copyWith(syncing: true, submitError: null, notice: null);

    var synced = 0;
    var failed = 0;
    var stopped = false;

    for (final event in queued) {
      try {
        await _replay(event);
        await _queue.confirm(event.clientEventId);
        synced++;
      } on ApiException catch (failure) {
        if (failure.statusCode == 0 || failure.isUnauthenticated) {
          // Still offline, or the session is gone — neither gets better by
          // working through the rest of the queue.
          stopped = true;
          break;
        }

        await _queue.markFailed(event.clientEventId, failure.message);
        failed++;
      } catch (_) {
        stopped = true;
        break;
      }
    }

    String? notice;

    if (synced > 0) {
      notice = '$synced event${synced == 1 ? '' : 's'} synced.';
    } else if (stopped) {
      notice = 'Still offline. Events stay queued until you have signal.';
    }

    state = state.copyWith(
      syncing: false,
      pending: await _queue.all(),
      notice: notice,
      submitError: failed > 0
          ? '$failed event${failed == 1 ? '' : 's'} could not be accepted. '
                'They stay queued so you can try again.'
          : null,
    );

    await refresh();
  }

  Future<void> _replay(OfflineEvent event) async {
    switch (event.action) {
      case OfflineAction.checkIn:
        final path = event.selfiePath;
        final selfie = path == null ? null : await _queue.selfies.read(path);

        if (selfie == null) {
          throw const ApiException(
            statusCode: 422,
            message:
                'The queued selfie is no longer on this device. Check '
                'in again from the site.',
          );
        }

        await _repository.checkIn(
          CheckInSubmission(
            siteId: event.siteId,
            fix: event.fix,
            clientEventId: event.clientEventId,
            deviceReference: event.deviceReference,
            selfieBytes: selfie,
            source: 'offline',
          ),
        );

      case OfflineAction.checkOut:
        await _repository.checkOut(
          CheckOutSubmission(
            siteId: event.siteId,
            fix: event.fix,
            clientEventId: event.clientEventId,
            deviceReference: event.deviceReference,
            source: 'offline',
          ),
        );

      case OfflineAction.visitStart:
        await _repository.startSiteVisit(
          SiteVisitStartSubmission(
            siteId: event.siteId,
            fix: event.fix,
            purpose: event.purpose ?? 'Site visit',
            remarks: event.remarks,
            clientEventId: event.clientEventId,
            deviceReference: event.deviceReference,
          ),
        );

      case OfflineAction.visitEnd:
        await _repository.endSiteVisit(
          SiteVisitEndSubmission(
            siteVisitId: event.siteVisitId ?? 0,
            fix: event.fix,
            remarks: event.remarks,
            clientEventId: event.clientEventId,
          ),
        );
    }
  }

  /* ------------------------------------------------------------ helpers */

  /// The site to preselect: the one the server considers current when it is
  /// among today's assignments, otherwise the first assignment, otherwise
  /// nothing.
  ///
  /// Chosen from `sites` rather than from `site` because the picker asserts
  /// that its value is one of its own items — and the two can legitimately
  /// differ, since `site` may come from an attendance row for a posting that
  /// has since ended.
  int? _defaultSiteId(TodayStatus today) {
    final current = today.site?.id;
    if (current != null && today.siteById(current) != null) return current;

    if (today.sites.isNotEmpty) return today.sites.first.id;

    return null;
  }

  /// A fresh reading, or null after saying why there is not one.
  ///
  /// The advisory geofence is deliberately *not* consulted here. The screen
  /// shows it; the server decides with it. Refusing to send because our own
  /// arithmetic disagreed would make this a second authority on a question
  /// that only has one.
  Future<LocationFix?> _fixForSubmission() async {
    final fix = state.fix;

    if (fix != null && fix.isUsable) return fix;

    try {
      final fresh = await _location.currentFix();

      state = state.copyWith(
        fix: fresh,
        location: LocationCondition(
          LocationStatus.granted,
          'GPS ready · accuracy ±${fresh.accuracy.round()} m',
        ),
      );

      return fresh;
    } catch (failure) {
      state = state.copyWith(
        submitError: failure is LocationAcquisitionFailed
            ? failure.message
            : 'Could not get a location reading. Try again.',
        location: const LocationCondition(
          LocationStatus.unavailable,
          'No location reading yet.',
        ),
        fix: null,
      );

      return null;
    }
  }

  /// A failure that never reached the server is queued; everything else is
  /// shown to the person, because a 403 or a 422 will be exactly the same
  /// answer on the third attempt.
  Future<void> _failOrQueue({
    required String action,
    required int siteId,
    required String clientEventId,
    required ApiException failure,
    Uint8List? selfie,
    int? siteVisitId,
    String? purpose,
    String? remarks,
  }) async {
    if (failure.statusCode != 0) {
      state = state.copyWith(submitting: false, submitError: _explain(failure));
      return;
    }

    final fix = state.fix;

    await _queue.enqueue(
      action: action,
      siteId: siteId,
      fix: fix ?? const LocationFix(latitude: 0, longitude: 0, accuracy: 0),
      deviceReference: await _device.label(),
      clientEventId: clientEventId,
      siteVisitId: siteVisitId,
      purpose: purpose,
      remarks: remarks,
      selfieBytes: selfie,
    );

    state = state.copyWith(
      submitting: false,
      pending: await _queue.all(),
      notice:
          'No connection — saved here and synced when you are back '
          'online.',
    );

    await refresh();
  }

  Future<Uint8List?> _safeCompressed(Uint8List raw) async {
    try {
      return await _compressor.compress(raw);
    } catch (_) {
      return null;
    }
  }

  /// What a failure actually said.
  ///
  /// A 422 arrives as `message: "The given data was invalid."` plus a map of
  /// field => reason, and the reason is the only half a person can act on:
  /// "You are 412 m away — the allowed radius is 100 m" lives under
  /// `errors.location`, while `message` would have told them nothing except
  /// that something was wrong. Every field error is equally worth showing,
  /// so the first one wins when there are several.
  static String _explain(ApiException failure) {
    final errors = failure.errors;
    if (errors.isNotEmpty) return errors.values.first;
    return failure.message;
  }
}
