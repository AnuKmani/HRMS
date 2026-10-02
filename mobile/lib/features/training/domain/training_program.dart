import 'training_type.dart';

/// One configurable training program the company runs.
///
/// Mirrors `TrainingProgramResource`. Every configurable fact is in here
/// because every one of them is something a form or a report has to draw —
/// provider, duration, whether a certificate comes out of it and how long
/// that certificate lasts. A payload that hid them would leave the screen
/// hard-coding the very thing the brief asked to be data.
///
/// **`certificateValidityDays` is days and nothing else, and `null` means
/// "never lapses".** It is never `0`: a zero-day certificate would expire
/// the moment it was issued, which is a different statement from one that
/// does not expire at all, and the two are one character apart in a payload
/// that has to be read by a person deciding which to type.
class TrainingProgram {
  const TrainingProgram({
    required this.id,
    required this.trainingTypeId,
    this.trainingType,
    required this.code,
    required this.name,
    this.description,
    this.provider,
    this.durationDays,
    required this.certificateRequired,
    this.certificateValidityDays,
    required this.status,
    required this.isActive,
    this.createdAt,
  });

  static const statusActive = 'active';
  static const statusRetired = 'retired';

  final int id;
  final int trainingTypeId;
  final TrainingType? trainingType;

  final String code;
  final String name;
  final String? description;
  final String? provider;
  final int? durationDays;

  final bool certificateRequired;
  final int? certificateValidityDays;

  final String status;
  final bool isActive;

  final String? createdAt;

  String get typeName => trainingType?.name ?? '';

  /// "Certificate valid for 365 days" / "Certificate never expires" / "No
  /// certificate".
  ///
  /// Spelled out rather than shown as a number with a unit, because the
  /// absence of a validity is a real answer a completion form needs to see
  /// and `null` rendered as blank looks like a field nobody filled in.
  String get certificateLabel {
    if (!certificateRequired) return 'No certificate';

    final days = certificateValidityDays;
    if (days == null) return 'Certificate never expires';

    return days == 1
        ? 'Certificate valid for 1 day'
        : 'Certificate valid for $days days';
  }

  /// "1 day" / "2 days" / "Duration not tracked".
  String get durationLabel {
    final days = durationDays;
    if (days == null) return 'Duration not tracked';

    return days == 1 ? '1 day' : '$days days';
  }

  String get statusText => switch (status) {
    statusActive => 'Active',
    statusRetired => 'Retired',
    _ => status,
  };

  factory TrainingProgram.fromJson(Map<String, dynamic> json) =>
      TrainingProgram(
        id: _int(json['id']) ?? 0,
        trainingTypeId: _int(json['training_type_id']) ?? 0,
        trainingType: json['training_type'] is Map<String, dynamic>
            ? TrainingType.fromJson(
                json['training_type'] as Map<String, dynamic>,
              )
            : null,
        code: json['code'] as String? ?? '',
        name: json['name'] as String? ?? '',
        description: json['description'] as String?,
        provider: json['provider'] as String?,
        durationDays: _int(json['duration_days']),
        certificateRequired: json['certificate_required'] == true,
        certificateValidityDays: _int(json['certificate_validity_days']),
        status: json['status'] as String? ?? statusActive,
        isActive: json['is_active'] == true,
        createdAt: json['created_at'] as String?,
      );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
