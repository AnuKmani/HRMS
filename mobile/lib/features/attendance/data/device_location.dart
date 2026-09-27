import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../domain/location_fix.dart';

/// The real device. Overridden in tests with a fake that has no radio.
final locationGatewayProvider = Provider<LocationGateway>(
  (ref) => const DeviceLocationGateway(),
);

/// Where the location permission stands, in the five states a person can
/// actually be in.
///
/// `notRequested` is a state of its own on purpose: "we have not asked yet"
/// and "they said no" need different words on screen, and collapsing both
/// into "denied" would tell a first-time user they had refused something
/// they were never offered.
enum LocationStatus {
  /// Never asked. The screen should offer the ask.
  notRequested,

  /// Granted — usable right now, foreground only.
  granted,

  /// Refused, and the platform will still show the prompt again.
  denied,

  /// Refused, and no prompt will ever be shown again. Only the system
  /// Settings screen can undo this, so that is the only action worth
  /// offering.
  permanentlyDenied,

  /// The permission is fine and the radio is off. Nothing can be fixed from
  /// inside the app except opening the location settings.
  serviceDisabled,

  /// The reading failed for a reason that is not a permission at all —
  /// indoors, no satellites, the receiver gave up.
  unavailable,
}

/// The answer, with the words to go with it.
class LocationCondition {
  const LocationCondition(this.status, this.message);

  final LocationStatus status;

  final String message;

  bool get isUsable => status == LocationStatus.granted;
}

/// Where the phone is, as far as the phone is willing to say.
///
/// An interface rather than a direct call to [Geolocator] so the whole
/// permission flow — all five states, plus the GPS being off — can be
/// exercised in a widget test where there is no radio to switch off.
abstract class LocationGateway {
  /// The current state, without prompting anyone.
  Future<LocationStatus> checkPermission();

  /// Asks, if the platform still allows asking.
  Future<LocationStatus> requestPermission();

  /// Whether the device's location service is on at all.
  Future<bool> isServiceEnabled();

  /// One reading, or a [LocationAcquisitionFailed] carrying why.
  Future<LocationFix> currentFix({
    Duration timeLimit = const Duration(seconds: 20),
  });

  /// The two settings screens, so "permanently denied" ends in an action
  /// rather than an apology.
  Future<void> openAppSettings();

  Future<void> openLocationSettings();
}

/// The reading could not be obtained, and this is a fact about the device
/// rather than about the person or the server.
class LocationAcquisitionFailed implements Exception {
  const LocationAcquisitionFailed(this.message);

  final String message;

  @override
  String toString() => 'LocationAcquisitionFailed: $message';
}

/// The real one, on top of `geolocator`.
///
/// Foreground-only throughout: `LocationPermission.always` is never
/// *requested*, because nothing here needs a position when the app is
/// closed, and asking for background location would change what this
/// product is. If a platform reports `always` because it was granted
/// elsewhere, it is still simply "granted".
class DeviceLocationGateway implements LocationGateway {
  const DeviceLocationGateway();

  @override
  Future<LocationStatus> checkPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return LocationStatus.serviceDisabled;
    }

    return _fromPermission(await Geolocator.checkPermission());
  }

  @override
  Future<LocationStatus> requestPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return LocationStatus.serviceDisabled;
    }

    return _fromPermission(await Geolocator.requestPermission());
  }

  @override
  Future<bool> isServiceEnabled() => Geolocator.isLocationServiceEnabled();

  @override
  Future<LocationFix> currentFix({
    Duration timeLimit = const Duration(seconds: 20),
  }) async {
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: timeLimit,
        ),
      );

      return LocationFix(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
      );
    } on TimeoutException {
      throw const LocationAcquisitionFailed(
        'The GPS did not answer in time. Step outside and try again.',
      );
    } catch (failure) {
      throw LocationAcquisitionFailed(_messageFor(failure));
    }
  }

  @override
  Future<void> openAppSettings() => Geolocator.openAppSettings();

  @override
  Future<void> openLocationSettings() => Geolocator.openLocationSettings();

  LocationStatus _fromPermission(LocationPermission permission) =>
      switch (permission) {
        LocationPermission.denied => LocationStatus.denied,
        LocationPermission.deniedForever => LocationStatus.permanentlyDenied,
        LocationPermission.whileInUse ||
        LocationPermission.always => LocationStatus.granted,
        // The platform would not say. Treated as "not granted yet" rather
        // than as granted: the safe reading of an unclear answer is that
        // we do not have it.
        LocationPermission.unableToDetermine => LocationStatus.denied,
      };

  String _messageFor(Object failure) {
    final text = failure.toString();

    if (text.contains('PermissionDenied')) {
      return 'Location permission was refused. Allow it in Settings to '
          'check in.';
    }

    if (text.contains('LocationServiceDisabled')) {
      return 'Location services are off. Turn on GPS and try again.';
    }

    return 'Could not get a location reading. Step outside and try again.';
  }
}
