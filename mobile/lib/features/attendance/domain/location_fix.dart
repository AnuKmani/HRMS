import 'dart:math' as math;

/// One GPS reading and nothing else.
///
/// The backend re-validates every one of these before it believes a word of
/// them (see `GeofenceService`), so what lives here is *advisory*: enough to
/// tell the person standing at the gate whether the server is likely to
/// accept the check-in, and never the thing that decides it.
class LocationFix {
  const LocationFix({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
  });

  final double latitude;

  final double longitude;

  /// Metres, 68% confidence as the platform reports it. Zero or negative
  /// means the receiver did not actually know how wrong it might be, which
  /// is worse than a large number rather than better.
  final double accuracy;

  /// Latitude and longitude inside their legal ranges, and not the (0, 0)
  /// sentinel an uninitialised receiver so often reports as if it were a
  /// point in the Atlantic.
  bool get hasCoordinates {
    final plausible = latitude.abs() <= 90 && longitude.abs() <= 180;
    final notTheSentinel = !(latitude == 0 && longitude == 0);

    return plausible && notTheSentinel;
  }

  /// Whether this reading is worth sending at all.
  ///
  /// A fix with no coordinates, or one whose accuracy is not a positive
  /// number, fails here — before it can be shown to a user as a distance.
  bool get isUsable => hasCoordinates && accuracy > 0;

  @override
  String toString() =>
      'LocationFix($latitude, $longitude ±${accuracy.toStringAsFixed(0)}m)';
}

/// What the app can say about a fix and a site before the server has spoken.
///
/// [code] is the vocabulary the backend uses for the same four outcomes, so
/// a message written here and a message written there never describe the
/// same rejection differently.
class GeofenceVerdict {
  const GeofenceVerdict({
    required this.code,
    required this.distanceMetres,
    required this.radiusMetres,
    required this.message,
  });

  static const String ok = 'ok';
  static const String invalidCoordinates = 'invalid_coordinates';
  static const String poorAccuracy = 'poor_accuracy';
  static const String siteNotConfigured = 'site_not_configured';
  static const String outsideGeofence = 'outside_geofence';

  final String code;

  /// Null when no distance could honestly be computed.
  final double? distanceMetres;

  final double radiusMetres;

  final String message;

  bool get isWithin => code == ok;

  /// Roughly: is this reading good enough to send?
  ///
  /// Deliberately **not** the same question as [isWithin]. A reading far
  /// from the site is still a *usable* reading — the person may simply be
  /// at the wrong gate — whereas a reading with no coordinates is garbage
  /// and should be fixed rather than submitted.
  bool get isUsableReading =>
      code != invalidCoordinates && code != poorAccuracy;
}

/// The same haversine the backend runs, for the same purpose: showing a
/// number while the phone is still in the person's hand.
///
/// Two independent implementations of one formula is a cost, and it is paid
/// on purpose. The alternative — asking the server for every distance the
/// screen draws — turns a display into a network dependency, and it would
/// still be the server's answer that decides, here or there. The number on
/// the phone is labelled advisory everywhere it appears.
class LocalGeofence {
  const LocalGeofence._();

  /// Metres between two points, matching `App\Support\Geo::distanceMetres`.
  static double distanceMetres(
    double fromLat,
    double fromLng,
    double toLat,
    double toLng,
  ) {
    const earthRadius = 6371000.0;

    final dLat = _radians(toLat - fromLat);
    final dLng = _radians(toLng - fromLng);

    final a =
        math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(_radians(fromLat)) *
            math.cos(_radians(toLat)) *
            math.sin(dLng / 2) *
            math.sin(dLng / 2);

    return earthRadius * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
  }

  /// The advisory answer for one site and one reading.
  ///
  /// [maxAccuracyMetres] mirrors `hrms.attendance.max_gps_accuracy_metres`,
  /// handed down from `GET /attendance/today` where it belongs: the ceiling
  /// is configuration, not a number this widget should invent.
  static GeofenceVerdict assess({
    required double? siteLatitude,
    required double? siteLongitude,
    required double? radiusMetres,
    required LocationFix fix,
    double maxAccuracyMetres = 100,
  }) {
    if (!fix.hasCoordinates) {
      return const GeofenceVerdict(
        code: GeofenceVerdict.invalidCoordinates,
        distanceMetres: null,
        radiusMetres: 0,
        message:
            'This location reading is not usable. Move into the open '
            'and try again.',
      );
    }

    if (fix.accuracy > maxAccuracyMetres) {
      return GeofenceVerdict(
        code: GeofenceVerdict.poorAccuracy,
        distanceMetres: null,
        radiusMetres: radiusMetres ?? 0,
        message:
            'GPS accuracy is ±${fix.accuracy.round()} m — the limit is '
            '${maxAccuracyMetres.round()} m. Move into the open and try again.',
      );
    }

    if (siteLatitude == null || siteLongitude == null) {
      return const GeofenceVerdict(
        code: GeofenceVerdict.siteNotConfigured,
        distanceMetres: null,
        radiusMetres: 0,
        message:
            'This site has no location on file, so it cannot be '
            'checked in at. Please ask HR.',
      );
    }

    if (radiusMetres == null) {
      // Coordinates without a boundary: there is nothing to measure against.
      // Still *usable* — `site_not_configured` is not one of the two codes
      // that mean "this reading is garbage" — because the server owns the
      // decision and has its own default.
      return const GeofenceVerdict(
        code: GeofenceVerdict.siteNotConfigured,
        distanceMetres: null,
        radiusMetres: 0,
        message:
            'This site has no boundary on file, so a distance cannot '
            'be shown. Your check-in is still checked by the server.',
      );
    }

    final radius = radiusMetres;
    final distance = distanceMetres(
      fix.latitude,
      fix.longitude,
      siteLatitude,
      siteLongitude,
    );

    if (distance > radius) {
      return GeofenceVerdict(
        code: GeofenceVerdict.outsideGeofence,
        distanceMetres: distance,
        radiusMetres: radius,
        message:
            'You are ${distance.round()} m away — the allowed radius is '
            '${radius.round()} m. Walk to the site and try again.',
      );
    }

    return GeofenceVerdict(
      code: GeofenceVerdict.ok,
      distanceMetres: distance,
      radiusMetres: radius,
      message: 'Within the ${radius.round()} m site boundary.',
    );
  }

  static double _radians(double degrees) => degrees * math.pi / 180;
}
