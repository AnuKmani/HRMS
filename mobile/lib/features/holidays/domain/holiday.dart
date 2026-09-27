/// One day the calendar declared off — public, company-wide, or one site's.
///
/// Matches `HolidayResource`. Three scopes share one model because that is
/// how the table is built: `public` and `company` carry no site, `site`
/// requires one, and the uniqueness that matters is (date, type, site) —
/// two different sites may absolutely share a date, which is the whole
/// point of a site scope.
///
/// There is no recurrence here and none on the server: a holiday that
/// happens every year is a row per year, not a rule. A recurrence engine
/// would need exceptions, and exceptions need a UI, and none of that pays
/// for itself in a calendar with a few dozen days in it.
class Holiday {
  const Holiday({
    required this.id,
    required this.name,
    required this.date,
    required this.type,
    this.siteId,
    this.siteName,
    this.description,
    required this.status,
  });

  static const typePublic = 'public';
  static const typeCompany = 'company';
  static const typeSite = 'site';

  static const statusActive = 'active';
  static const statusInactive = 'inactive';

  final int id;
  final String name;

  /// `YYYY-MM-DD`.
  final String date;

  final String type;
  final int? siteId;
  final String? siteName;
  final String? description;
  final String status;

  bool get isActive => status == statusActive;

  bool get needsSite => type == typeSite;

  String get typeLabel => switch (type) {
    typeSite => 'Site',
    typeCompany => 'Company',
    _ => 'Public',
  };

  /// The scope as a sentence, because "Company · Block A" and "Public" are
  /// two different answers to "does this apply to me?" and a row that only
  /// showed the type would hide the half of it that matters.
  String get scopeLabel => type == typeSite
      ? 'Site${siteName == null ? '' : ' · $siteName'}'
      : typeLabel;

  factory Holiday.fromJson(Map<String, dynamic> json) => Holiday(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    date: json['date'] as String? ?? '',
    type: json['type'] as String? ?? typePublic,
    siteId: _int(json['site_id']),
    siteName: _nestedName(json['site']),
    description: json['description'] as String?,
    status: json['status'] as String? ?? statusActive,
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
