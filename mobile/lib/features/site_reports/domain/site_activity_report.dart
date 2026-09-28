import 'site_report_photo.dart';

/// One person's account of one site-day.
///
/// Matches `SiteActivityReportResource`. The field worth stating twice is
/// [employeeId]: the server derives it from the bearer token and never reads
/// it from the payload, so this model *parses* it for display and no screen
/// ever puts it back into a request. A form that offered "whose report is
/// this?" would be offering a lie the API would silently ignore.
class SiteActivityReport {
  const SiteActivityReport({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.projectId,
    this.projectName,
    required this.siteId,
    this.siteName,
    required this.reportDate,
    required this.workCategory,
    required this.workPerformed,
    required this.progressPercentage,
    this.manpower,
    this.materialsUsed,
    this.equipmentUsed,
    this.issues,
    this.safetyIssues,
    this.remarks,
    this.latitude,
    this.longitude,
    this.gpsAccuracy,
    required this.status,
    this.submittedAt,
    this.createdAt,
    this.updatedAt,
    this.hasGpsFix = false,
    this.isDraft = true,
    this.isEditable = true,
    this.photos = const <SiteReportPhoto>[],
    this.photoCount = 0,
  });

  static const statusDraft = 'draft';
  static const statusSubmitted = 'submitted';

  static const statuses = [statusDraft, statusSubmitted];

  /// The endpoint a photograph id has to be read through.
  static const photoBasePath = '/site-activity-reports';

  final int id;
  final int employeeId;
  final String? employeeName;

  final int projectId;
  final String? projectName;
  final int siteId;
  final String? siteName;

  final String reportDate;
  final String workCategory;
  final String workPerformed;
  final int progressPercentage;

  /// Free text here, and only here. The daily report normalises manpower,
  /// materials and equipment into rows because *that* document has to be
  /// read by somebody who was not there; this one is a note written between
  /// two pours, and a note does not maintain an inventory.
  final String? manpower;
  final String? materialsUsed;
  final String? equipmentUsed;

  final String? issues;
  final String? safetyIssues;
  final String? remarks;

  final double? latitude;
  final double? longitude;
  final double? gpsAccuracy;

  final String status;
  final String? submittedAt;
  final String? createdAt;
  final String? updatedAt;

  final bool hasGpsFix;
  final bool isDraft;
  final bool isEditable;

  final List<SiteReportPhoto> photos;
  final int photoCount;

  /// How many frames the report holds, whether or not this response
  /// carried them. The list endpoint sends the count and not the rows, so a
  /// list can show a camera badge without downloading a gallery.
  int get attachedPhotoCount => photos.isEmpty ? photoCount : photos.length;

  bool get isSubmitted => status == statusSubmitted;

  String get statusLabel => isSubmitted ? 'Submitted' : 'Draft';

  /// The bytes route for one of this report's photographs.
  String photoPath(int photoId) =>
      SiteReportPhoto.pathFor(photoBasePath, id, photoId);

  /// Whether GPS was good enough for the server to have accepted a
  /// submission â€” the same 100 m ceiling attendance uses, reported rather
  /// than derived, because the server's answer is the one that decides.
  bool get hasUsableGps =>
      hasGpsFix && latitude != null && longitude != null && gpsAccuracy != null;

  factory SiteActivityReport.fromJson(Map<String, dynamic> json) =>
      SiteActivityReport(
        id: _int(json['id']) ?? 0,
        employeeId: _int(json['employee_id']) ?? 0,
        employeeName: _nestedName(json['employee']),
        projectId: _int(json['project_id']) ?? 0,
        projectName: _nestedName(json['project']),
        siteId: _int(json['site_id']) ?? 0,
        siteName: _nestedName(json['site']),
        reportDate: json['report_date'] as String? ?? '',
        workCategory: json['work_category'] as String? ?? '',
        workPerformed: json['work_performed'] as String? ?? '',
        progressPercentage: _int(json['progress_percentage']) ?? 0,
        manpower: json['manpower'] as String?,
        materialsUsed: json['materials_used'] as String?,
        equipmentUsed: json['equipment_used'] as String?,
        issues: json['issues'] as String?,
        safetyIssues: json['safety_issues'] as String?,
        remarks: json['remarks'] as String?,
        latitude: _decimal(json['latitude']),
        longitude: _decimal(json['longitude']),
        gpsAccuracy: _decimal(json['gps_accuracy']),
        status: json['status'] as String? ?? statusDraft,
        submittedAt: json['submitted_at'] as String?,
        createdAt: json['created_at'] as String?,
        updatedAt: json['updated_at'] as String?,
        hasGpsFix: json['has_gps_fix'] == true,
        isDraft: json['is_draft'] == true,
        isEditable: json['is_editable'] == true,
        photos: photosFrom(json['photos']),
        photoCount: _int(json['photo_count']) ?? 0,
      );
}

String? _nestedName(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final name = raw['name'];
    if (name is String && name.isNotEmpty) return name;
  }

  return null;
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
