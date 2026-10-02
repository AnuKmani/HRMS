/// A kind of training — the vocabulary behind a program, not an offering.
///
/// Mirrors `TrainingTypeResource`. Four columns and no policy, because
/// nothing here describes a person or a cohort: it is the list a form's
/// picker reads, and it is the same list for everybody who may open the
/// training screen at all.
///
/// **There is no hard-coded list of training types anywhere in this app.**
/// SAFETY_INDUCTION, HSE, WORKING_AT_HEIGHTS and the rest are seeded rows
/// an operator may rename, and this class reads whatever comes back. A
/// screen that switched on `code` to decide what to draw would put the
/// very thing the brief asked to be data back into the client.
class TrainingType {
  const TrainingType({
    required this.id,
    required this.code,
    required this.name,
    this.description,
    required this.status,
    required this.isActive,
  });

  static const statusActive = 'active';
  static const statusInactive = 'inactive';

  final int id;
  final String code;
  final String name;
  final String? description;

  /// Ships because a retired type still appears beside the courses filed
  /// under it — an operator has to be able to tell "this is not offered any
  /// more" from "this never existed" when reading last year's cohort.
  final String status;
  final bool isActive;

  factory TrainingType.fromJson(Map<String, dynamic> json) => TrainingType(
    id: _int(json['id']) ?? 0,
    code: json['code'] as String? ?? '',
    name: json['name'] as String? ?? '',
    description: json['description'] as String?,
    status: json['status'] as String? ?? statusInactive,
    isActive: json['is_active'] == true,
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
