/// A department — the top of the organisation's org chart.
///
/// Matches `DepartmentResource`. The two `*_count` values come back only when
/// the endpoint was asked for them (`withCount`), so they are nullable here
/// rather than assumed to be zero: an absent count and a real count of zero
/// are different facts and a UI that merged them would be lying about one.
class Department {
  const Department({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    required this.status,
    this.designationsCount,
    this.employeesCount,
    this.createdAt,
  });

  final int id;
  final String name;
  final String code;
  final String? description;

  /// `active` or `inactive`.
  final String status;

  final int? designationsCount;
  final int? employeesCount;
  final String? createdAt;

  bool get isActive => status == 'active';

  /// A one-line summary for a list row: the code, plus whichever counts the
  /// endpoint actually sent.
  String get summary {
    final parts = <String>[code];

    if (employeesCount != null) {
      parts.add('$employeesCount ${employeesCount == 1 ? 'employee' : 'employees'}');
    }

    if (designationsCount != null) {
      parts.add('$designationsCount ${designationsCount == 1 ? 'designation' : 'designations'}');
    }

    return parts.join(' · ');
  }

  factory Department.fromJson(Map<String, dynamic> json) => Department(
        id: _int(json['id']) ?? 0,
        name: json['name'] as String? ?? '',
        code: json['code'] as String? ?? '',
        description: json['description'] as String?,
        status: json['status'] as String? ?? 'active',
        designationsCount: _int(json['designations_count']),
        employeesCount: _int(json['employees_count']),
        createdAt: json['created_at'] as String?,
      );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
