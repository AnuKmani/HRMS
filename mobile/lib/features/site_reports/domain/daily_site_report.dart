import 'site_report_photo.dart';

/// The official record of one site-day.
///
/// Matches `DailySiteReportResource`. Three things distinguish it from the
/// activity report and are worth carrying in the model rather than only in
/// the screen:
///
///  - [createdBy] is the author. There is no `employee_id` here at all â€”
///    this document is prepared by whoever prepares it, not a record of
///    somebody's presence.
///  - [totalManpower] arrives as stored. The server derived it from the rows
///    at write time and keeps it there, so a row edited by hand cannot
///    silently change a document somebody already read. The *display* total
///    is [displayTotalManpower], which prefers the rows when they are in the
///    response and falls back to the stored figure otherwise.
///  - it is unique per site per date. Nothing in this model can express that
///    constraint; it is the server's job, and a 422 naming `report_date` is
///    the only thing a form needs to know about it.
class DailySiteReport {
  const DailySiteReport({
    required this.id,
    required this.createdBy,
    this.creatorName,
    required this.projectId,
    this.projectName,
    required this.siteId,
    this.siteName,
    required this.reportDate,
    required this.totalManpower,
    this.workPlanned = '',
    this.workCompleted = '',
    this.safetyObservations,
    this.delays,
    this.issues,
    this.remarks,
    required this.status,
    this.submittedAt,
    this.approvedAt,
    this.createdAt,
    this.updatedAt,
    this.isDraft = true,
    this.isEditable = true,
    this.manpower = const <ManpowerRow>[],
    this.materials = const <MaterialRow>[],
    this.equipment = const <EquipmentRow>[],
    this.photos = const <SiteReportPhoto>[],
  });

  static const statusDraft = 'draft';
  static const statusSubmitted = 'submitted';

  static const statuses = [statusDraft, statusSubmitted];

  /// The endpoint a photograph id has to be read through.
  static const photoBasePath = '/daily-site-reports';

  final int id;
  final int createdBy;
  final String? creatorName;

  final int projectId;
  final String? projectName;
  final int siteId;
  final String? siteName;

  final String reportDate;
  final int totalManpower;
  final String workPlanned;
  final String workCompleted;
  final String? safetyObservations;
  final String? delays;
  final String? issues;
  final String? remarks;

  final String status;
  final String? submittedAt;
  final String? approvedAt;
  final String? createdAt;
  final String? updatedAt;

  final bool isDraft;
  final bool isEditable;

  /// The four child sets. Each is empty both for "this report has none" and
  /// for "this response did not carry them" â€” the list endpoint omits all
  /// four so a page costs one query â€” which is a distinction only [photos]'
  /// companion `photo_count` has to make, and none of the five screens here
  /// needs to.
  final List<ManpowerRow> manpower;
  final List<MaterialRow> materials;
  final List<EquipmentRow> equipment;
  final List<SiteReportPhoto> photos;

  bool get isSubmitted => status == statusSubmitted;

  String get statusLabel => isSubmitted ? 'Submitted' : 'Draft';

  /// The head count the document should show.
  ///
  /// The rows win when they are in the response, because those are the
  /// numbers a reader can add up for themselves; the stored figure stands
  /// when they are not, and equals them because DailySiteReportService
  /// derives it rather than believing a payload. A total that disagreed with
  /// the table under it would be the one number in the document nobody could
  /// reproduce.
  int get displayTotalManpower => manpower.isEmpty
      ? totalManpower
      : manpower.fold<int>(0, (sum, row) => sum + row.count);

  /// Whether a narrative section has anything in it â€” used to say "Not
  /// recorded" rather than to leave a heading with nothing under it, which
  /// reads as an omission instead of an answer.
  bool get hasNarrative =>
      (safetyObservations?.isNotEmpty ?? false) ||
      (delays?.isNotEmpty ?? false) ||
      (issues?.isNotEmpty ?? false) ||
      (remarks?.isNotEmpty ?? false);

  String photoPath(int photoId) =>
      SiteReportPhoto.pathFor(photoBasePath, id, photoId);

  factory DailySiteReport.fromJson(Map<String, dynamic> json) =>
      DailySiteReport(
        id: _int(json['id']) ?? 0,
        createdBy: _int(json['created_by']) ?? 0,
        creatorName: _creatorName(json['creator']),
        projectId: _int(json['project_id']) ?? 0,
        projectName: _nestedName(json['project']),
        siteId: _int(json['site_id']) ?? 0,
        siteName: _nestedName(json['site']),
        reportDate: json['report_date'] as String? ?? '',
        totalManpower: _int(json['total_manpower']) ?? 0,
        workPlanned: json['work_planned'] as String? ?? '',
        workCompleted: json['work_completed'] as String? ?? '',
        safetyObservations: json['safety_observations'] as String?,
        delays: json['delays'] as String?,
        issues: json['issues'] as String?,
        remarks: json['remarks'] as String?,
        status: json['status'] as String? ?? statusDraft,
        submittedAt: json['submitted_at'] as String?,
        approvedAt: json['approved_at'] as String?,
        createdAt: json['created_at'] as String?,
        updatedAt: json['updated_at'] as String?,
        isDraft: json['is_draft'] == true,
        isEditable: json['is_editable'] == true,
        manpower: _rows(json['manpower'], ManpowerRow.fromJson),
        materials: _rows(json['materials'], MaterialRow.fromJson),
        equipment: _rows(json['equipment'], EquipmentRow.fromJson),
        photos: photosFrom(json['photos']),
      );
}

/// One workforce category: a counted label, and nothing more.
///
/// The category is free text on the wire and here â€” no enum, no lookup, no
/// `status`. The vocabulary differs between civil, MEP and finishing trades
/// and a list nobody can extend starts lying the first time a new one
/// arrives, so the rows are data rather than configuration.
class ManpowerRow {
  const ManpowerRow({
    required this.category,
    required this.count,
    this.sortOrder = 0,
  });

  final String category;
  final int count;
  final int sortOrder;

  factory ManpowerRow.fromJson(Map<String, dynamic> json) => ManpowerRow(
    category: json['category'] as String? ?? '',
    count: _int(json['count']) ?? 0,
    sortOrder: _int(json['sort_order']) ?? 0,
  );
}

/// One line of what was bought or used â€” not an inventory item.
///
/// There is deliberately no stock level, no cost, no supplier and no
/// asset tag: this phase records what a site-day consumed, and the moment
/// this row grows an on-hand quantity it has become a module nobody asked
/// for.
class MaterialRow {
  const MaterialRow({
    required this.name,
    this.quantity,
    this.unit,
    this.remarks,
    this.sortOrder = 0,
  });

  final String name;
  final double? quantity;
  final String? unit;
  final String? remarks;
  final int sortOrder;

  /// How the quantity reads on a screen: `3`, never `3.000`.
  String get quantityLabel => _number(quantity);

  factory MaterialRow.fromJson(Map<String, dynamic> json) => MaterialRow(
    name: json['material_name'] as String? ?? '',
    quantity: _decimal(json['quantity']),
    unit: json['unit'] as String?,
    remarks: json['remarks'] as String?,
    sortOrder: _int(json['sort_order']) ?? 0,
  );
}

/// One piece of plant on site that day.
///
/// [operatingHours] is nullable rather than zero: a concrete pump parked all
/// day has no meter reading, and a column that insisted on a number would
/// record `0` for both "ran nothing" and "nobody looked".
class EquipmentRow {
  const EquipmentRow({
    required this.name,
    this.quantity,
    this.operatingHours,
    this.condition,
    this.remarks,
    this.sortOrder = 0,
  });

  final String name;
  final double? quantity;
  final double? operatingHours;
  final String? condition;
  final String? remarks;
  final int sortOrder;

  /// Same spelling as a material's, so `2 pump` and `20 cement` do not
  /// disagree about what a whole number looks like.
  String get quantityLabel => _number(quantity);

  factory EquipmentRow.fromJson(Map<String, dynamic> json) => EquipmentRow(
    name: json['equipment_name'] as String? ?? '',
    quantity: _decimal(json['quantity']),
    operatingHours: _decimal(json['operating_hours']),
    condition: json['condition'] as String?,
    remarks: json['remarks'] as String?,
    sortOrder: _int(json['sort_order']) ?? 0,
  );
}

List<T> _rows<T>(Object? raw, T Function(Map<String, dynamic>) parse) {
  if (raw is! List) return List<T>.empty(growable: false);

  return raw
      .whereType<Map<String, dynamic>>()
      .map(parse)
      .toList(growable: false);
}

String? _nestedName(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final name = raw['name'];
    if (name is String && name.isNotEmpty) return name;
  }

  return null;
}

String? _creatorName(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final name = raw['full_name'] ?? raw['name'];
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

/// A whole number without a decimal tail, and a fraction with one.
///
/// The server casts `quantity` to three decimal places, so a naive
/// `toString()` on the parsed value prints `2.000` beside `2` — and a
/// document where the same quantity is spelled two ways is a document a
/// reader stops trusting.
String _number(double? value) {
  if (value == null) return '';

  return value == value.roundToDouble()
      ? value.round().toString()
      : value.toString();
}
