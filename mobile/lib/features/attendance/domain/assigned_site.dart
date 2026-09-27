/// A site this employee is currently allowed to record attendance against.
///
/// The subset of `SiteResource` the check-in screen actually uses, plus the
/// geofence figures the server sends down so the advisory distance can be
/// drawn before anything is submitted.
class AssignedSite {
  const AssignedSite({
    required this.id,
    required this.name,
    required this.code,
    this.projectId,
    this.projectName,
    this.latitude,
    this.longitude,
    this.geofenceRadius,
  });

  final int id;
  final String name;
  final String code;
  final int? projectId;
  final String? projectName;
  final double? latitude;
  final double? longitude;

  /// Metres, already defaulted server-side when the row has none — the
  /// client never invents one of its own.
  final double? geofenceRadius;

  bool get hasCoordinates => latitude != null && longitude != null;

  factory AssignedSite.fromJson(Map<String, dynamic> json) => AssignedSite(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    code: json['code'] as String? ?? '',
    projectId: _int(json['project_id']),
    projectName: json['project_name'] as String?,
    latitude: _decimal(json['latitude']),
    longitude: _decimal(json['longitude']),
    geofenceRadius: _decimal(json['geofence_radius']),
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

double? _decimal(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}
