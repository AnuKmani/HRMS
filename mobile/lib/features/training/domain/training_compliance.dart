import 'training_program.dart';

/// `GET /training-compliance` — one screen's worth of answers.
///
/// Mirrors the compliance payload. Three things about it are worth knowing
/// before a screen draws them:
///
///  - **the counts are scoped before they are summed.** A project manager
///    who may read only their own rows gets their own numbers. This client
///    does not recompute any of them from the rows it happens to have
///    loaded: a total derived in Dart would be a total about the page on
///    screen rather than about what the reader may see, and the two are
///    different numbers for everybody who is not an operator.
///
///  - **the catalogue is complete while the counts are narrow.** A program
///    nobody has sat is still listed, with zero hanging off it — an
///    operator needs to see the courses they run — so a program showing
///    `enrollmentsCount == 0` means "no visible enrolments", not "this
///    course does not exist".
///
///  - **the certificate buckets come from today.** `expired` is filled from
///    the date whether or not the scheduler has written the status, so a
///    lagging cron cannot make this report optimistic.
class TrainingCompliance {
  const TrainingCompliance({
    required this.generatedAt,
    required this.warningDays,
    required this.programs,
    required this.enrollmentsByStatus,
    required this.certificates,
    required this.totalEnrollments,
    required this.totalCertificates,
  });

  final String generatedAt;
  final int warningDays;

  /// The whole catalogue, each row carrying the two counts beside it.
  final List<ComplianceProgram> programs;

  /// Every status key the vocabulary defines, zero-filled, so a screen can
  /// draw all seven rows without inventing the ones that are absent.
  final Map<String, int> enrollmentsByStatus;

  /// `valid` / `expiring_soon` / `expired` / `none`, zero-filled.
  final Map<String, int> certificates;

  final int totalEnrollments;
  final int totalCertificates;

  int countFor(String status) => enrollmentsByStatus[status] ?? 0;

  int get validCertificates => certificates['valid'] ?? 0;

  int get expiringCertificates => certificates['expiring_soon'] ?? 0;

  int get expiredCertificates => certificates['expired'] ?? 0;

  int get certificatesWithoutExpiry => certificates['none'] ?? 0;

  factory TrainingCompliance.fromJson(Map<String, dynamic> json) {
    return TrainingCompliance(
      generatedAt: json['generated_at'] as String? ?? '',
      warningDays: _int(json['warning_days']) ?? 0,
      programs: _rows(json['programs'])
          .map(ComplianceProgram.fromJson)
          .toList(),
      enrollmentsByStatus: _counts(json['enrollments_by_status']),
      certificates: _counts(json['certificates']),
      totalEnrollments: _count(json['totals'], 'enrollments'),
      totalCertificates: _count(json['totals'], 'certificates'),
    );
  }
}

/// A program from the catalogue, with the two counts the report hangs on it.
class ComplianceProgram {
  const ComplianceProgram({
    required this.program,
    required this.enrollmentsCount,
    required this.activeEnrollmentsCount,
  });

  final TrainingProgram program;
  final int enrollmentsCount;
  final int activeEnrollmentsCount;

  factory ComplianceProgram.fromJson(Map<String, dynamic> json) {
    // The counts sit *beside* the program's own payload rather than inside
    // it, so the program is parsed from the same map with those two keys
    // ignored — one shape in, one shape out, and no second parser to drift.
    return ComplianceProgram(
      program: TrainingProgram.fromJson(json),
      enrollmentsCount: _int(json['enrollments_count']) ?? 0,
      activeEnrollmentsCount: _int(json['active_enrollments_count']) ?? 0,
    );
  }
}

List<Map<String, dynamic>> _rows(Object? value) {
  if (value is! List) return const <Map<String, dynamic>>[];

  return value.whereType<Map<String, dynamic>>().toList();
}

Map<String, int> _counts(Object? value) {
  if (value is! Map) return const <String, int>{};

  return <String, int>{
    for (final entry in value.entries)
      if (entry.key is String) entry.key as String: _int(entry.value) ?? 0,
  };
}

int _count(Object? totals, String key) {
  if (totals is! Map) return 0;
  return _int(totals[key]) ?? 0;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
