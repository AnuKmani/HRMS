import '../../departments/domain/department.dart';

/// A designation — a job title inside a department.
///
/// Matches `DesignationResource`. `department` is only in the payload when
/// the endpoint loaded it, so it is read with `?? null` semantics: an absent
/// key and a `null` value mean the same thing to this model, because both say
/// only "this resource did not carry it" and neither claims there is no
/// department.
class Designation {
  const Designation({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    required this.status,
    this.departmentId,
    this.department,
    this.employeesCount,
    this.createdAt,
  });

  final int id;
  final String name;
  final String code;
  final String? description;
  final String status;

  final int? departmentId;

  /// The parent as a small embedded object, when the server sent one.
  final Department? department;

  final int? employeesCount;
  final String? createdAt;

  bool get isActive => status == 'active';

  /// What a list row shows under the title.
  String get summary {
    final parts = <String>[code];

    parts.add(department?.name ?? 'No department');

    if (employeesCount != null) {
      parts.add('$employeesCount ${employeesCount == 1 ? 'person' : 'people'}');
    }

    return parts.join(' · ');
  }

  factory Designation.fromJson(Map<String, dynamic> json) => Designation(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    code: json['code'] as String? ?? '',
    description: json['description'] as String?,
    status: json['status'] as String? ?? 'active',
    departmentId: _int(json['department_id']),
    department: json['department'] is Map<String, dynamic>
        ? Department.fromJson(json['department']! as Map<String, dynamic>)
        : null,
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
