import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/attendance/domain/location_fix.dart';

/// The advisory fence.
///
/// Every one of these is a *preview* of a decision the server will make
/// again with `GeofenceService`. The point of getting them right is that
/// the person at the gate is told the truth before they press anything —
/// not that the app becomes a second authority, which it is not and does
/// not pretend to be.
void main() {
  const siteLat = 12.9716;
  const siteLng = 77.5946;

  GeofenceVerdict assess({
    double? latitude = siteLat,
    double? longitude = siteLng,
    double? radius = 100,
    double fixLat = siteLat,
    double fixLng = siteLng,
    double accuracy = 9,
    double ceiling = 100,
  }) => LocalGeofence.assess(
    siteLatitude: latitude,
    siteLongitude: longitude,
    radiusMetres: radius,
    fix: LocationFix(latitude: fixLat, longitude: fixLng, accuracy: accuracy),
    maxAccuracyMetres: ceiling,
  );

  group('the formula', () {
    test('agrees with a known quarter of a degree of latitude', () {
      expect(
        LocalGeofence.distanceMetres(
          siteLat,
          siteLng,
          siteLat + 0.001,
          siteLng,
        ),
        closeTo(111.19, 0.5),
      );
    });

    test('is zero at the same point and symmetric either side', () {
      expect(
        LocalGeofence.distanceMetres(siteLat, siteLng, siteLat, siteLng),
        0,
      );

      final north = LocalGeofence.distanceMetres(
        siteLat,
        siteLng,
        siteLat + 0.002,
        siteLng,
      );
      final south = LocalGeofence.distanceMetres(
        siteLat,
        siteLng,
        siteLat - 0.002,
        siteLng,
      );

      expect(north, closeTo(south, 0.0001));
    });
  });

  group('verdicts', () {
    test('a point at the gate is inside and has its distance measured', () {
      // ~56 m out, well within a 100 m fence.
      final verdict = assess(fixLat: siteLat + 0.0005);

      expect(verdict.code, GeofenceVerdict.ok);
      expect(verdict.isWithin, isTrue);
      expect(verdict.isUsableReading, isTrue);
      expect(verdict.distanceMetres, closeTo(55.6, 1));
      expect(verdict.radiusMetres, 100);
      expect(verdict.message, contains('Within'));
    });

    test('a point down the road is outside, and says how far', () {
      // ~556 m out.
      final verdict = assess(fixLat: siteLat + 0.005);

      expect(verdict.code, GeofenceVerdict.outsideGeofence);
      expect(verdict.isWithin, isFalse);
      expect(verdict.isUsableReading, isTrue);
      expect(verdict.distanceMetres, closeTo(556, 2));
      expect(verdict.message, contains('556'));
      expect(verdict.message, contains('100'));
    });

    test(
      'the fence is as wide as the site says, not as wide as a constant',
      () {
        final narrow = assess(radius: 10, fixLat: siteLat + 0.0005);
        expect(narrow.isWithin, isFalse);

        final wide = assess(radius: 1000, fixLat: siteLat + 0.005);
        expect(wide.isWithin, isTrue);
        expect(wide.radiusMetres, 1000);
      },
    );

    test('the zero zero fix is unusable, not merely far away', () {
      final verdict = assess(fixLat: 0, fixLng: 0);

      expect(verdict.code, GeofenceVerdict.invalidCoordinates);
      expect(verdict.isUsableReading, isFalse);
      expect(verdict.distanceMetres, isNull);
      expect(verdict.message, contains('not usable'));
    });

    test('an out of range coordinate never reaches the haversine', () {
      final verdict = assess(fixLat: 95);

      expect(verdict.code, GeofenceVerdict.invalidCoordinates);
      expect(verdict.distanceMetres, isNull);
    });

    test('accuracy worse than the ceiling is rejected before distance', () {
      final verdict = assess(accuracy: 400, ceiling: 100, fixLat: 0);

      // The ceiling is checked first, so a hopeless reading is reported as
      // hopeless rather than as "you are 13 000 km away".
      expect(verdict.code, GeofenceVerdict.poorAccuracy);
      expect(verdict.isUsableReading, isFalse);
      expect(verdict.message, contains('400'));
      expect(verdict.message, contains('100'));
    });

    test('the ceiling is whatever the server configured, not a literal', () {
      expect(assess(accuracy: 400, ceiling: 500).code, GeofenceVerdict.ok);
      expect(assess(accuracy: 40, ceiling: 100).code, GeofenceVerdict.ok);
    });

    test('a site with no location says so instead of measuring nothing', () {
      final verdict = assess(latitude: null, longitude: null);

      expect(verdict.code, GeofenceVerdict.siteNotConfigured);
      expect(verdict.distanceMetres, isNull);
      expect(verdict.message, contains('no location on file'));
      // Still sendable — the server has its own answer.
      expect(verdict.isUsableReading, isTrue);
    });

    test('a site with no boundary on file cannot be measured against', () {
      final verdict = assess(radius: null);

      expect(verdict.code, GeofenceVerdict.siteNotConfigured);
      expect(verdict.distanceMetres, isNull);
      expect(verdict.message, contains('no boundary on file'));
      expect(verdict.isUsableReading, isTrue);
    });
  });

  group('LocationFix', () {
    test('knows whether it knows where it is', () {
      expect(
        const LocationFix(
          latitude: 12.9,
          longitude: 77.5,
          accuracy: 8,
        ).isUsable,
        isTrue,
      );
      expect(
        const LocationFix(latitude: 0, longitude: 0, accuracy: 8).isUsable,
        isFalse,
      );
      expect(
        const LocationFix(latitude: 91, longitude: 77, accuracy: 8).isUsable,
        isFalse,
      );
      expect(
        const LocationFix(
          latitude: 12.9,
          longitude: 77.5,
          accuracy: 0,
        ).isUsable,
        isFalse,
        reason:
            'An accuracy of zero is a receiver that did not know how '
            'wrong it was, not one that was perfect.',
      );
      expect(
        const LocationFix(
          latitude: 12.9,
          longitude: 77.5,
          accuracy: -1,
        ).isUsable,
        isFalse,
      );
    });
  });
}
