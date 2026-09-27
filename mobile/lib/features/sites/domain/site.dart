/// A construction site.
///
/// Matches `SiteResource`. Coordinates and the geofence radius arrive as
/// `decimal` casts — strings on the wire — and are parsed to doubles here so
/// a screen never has to know that. A site with no geofence configured sends
/// all three as `null`, which this model keeps as all three `null` rather
/// than filling in zeros: a zero radius is a real value and would mean
/// "nobody may check in from anywhere", which is not the same as "unset".
class Site {
  const Site({
    required this.id,
    required this.name,
    required this.code,
    this.address,
    this.projectId,
    this.projectName,
    this.latitude,
    this.longitude,
    this.geofenceRadius,
    this.siteManagerId,
    this.siteManagerName,
    this.siteSupervisorId,
    this.siteSupervisorName,
    this.shiftId,
    this.shiftName,
    this.workingHoursSettingId,
    required this.status,
    this.createdAt,
  });

  final int id;
  final String name;
  final String code;
  final String? address;

  final int? projectId;
  final String? projectName;

  final double? latitude;
  final double? longitude;

  /// Metres. Read from the column, never derived from a constant — two sites
  /// of the same project routinely sit hundreds of kilometres apart.
  final double? geofenceRadius;

  final int? siteManagerId;
  final String? siteManagerName;
  final int? siteSupervisorId;
  final String? siteSupervisorName;

  final int? shiftId;
  final String? shiftName;
  final int? workingHoursSettingId;

  final String? status;
  final String? createdAt;

  bool get isActive => status == 'active';

  /// True only when all three arrived together — which is also the rule the
  /// API enforces before writing them, so a half-filled geofence on screen
  /// would mean the payload itself was wrong.
  bool get hasGeofence =>
      latitude != null && longitude != null && geofenceRadius != null;

  /// What a list row shows underneath the title.
  String get summary {
    final parts = <String>[code];

    parts.add(projectName ?? 'No project');

    return parts.join(' · ');
  }

  factory Site.fromJson(Map<String, dynamic> json) => Site(
        id: _int(json['id']) ?? 0,
        name: json['name'] as String? ?? '',
        code: json['code'] as String? ?? '',
        address: json['address'] as String?,
        projectId: _int(json['project_id']),
        projectName: _nested(json['project'], 'name'),
        latitude: _decimal(json['latitude']),
        longitude: _decimal(json['longitude']),
        geofenceRadius: _decimal(json['geofence_radius']),
        siteManagerId: _int(json['site_manager_id']),
        siteManagerName: _nested(json['site_manager'], 'full_name'),
        siteSupervisorId: _int(json['site_supervisor_id']),
        siteSupervisorName: _nested(json['site_supervisor'], 'full_name'),
        shiftId: _int(json['shift_id']),
        shiftName: _nested(json['shift'], 'name'),
        workingHoursSettingId: _int(json['working_hours_setting_id']),
        status: json['status'] as String?,
        createdAt: json['created_at'] as String?,
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

String? _nested(Object? value, String key) {
  if (value is! Map) return null;

  final read = value[key];
  return read is String ? read : null;
}
