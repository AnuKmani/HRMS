/// An employee, as `GET /employees` and `GET /employees/{id}` return it.
///
/// One class serves both projections on purpose: the list omits salary, date
/// of birth and the other private fields entirely (see
/// `EmployeeResource`), and a field that is simply absent parses to `null`
/// here rather than raising. That is also why `salary` is nullable while
/// `salaryVisible` is a separate flag — the API decides whether the caller
/// may see a figure, and a model that only carried `salary` would have no
/// way to tell "paid nothing" from "not allowed to know".
class Employee {
  const Employee({
    required this.id,
    required this.employeeCode,
    required this.firstName,
    required this.lastName,
    required this.fullName,
    this.middleName,
    this.email,
    this.phone,
    this.photoPath,
    this.departmentId,
    this.departmentName,
    this.designationId,
    this.designationName,
    required this.employmentType,
    required this.employmentStatus,
    this.joiningDate,
    this.reportingManagerId,
    this.reportingManagerName,
    this.primaryProjectId,
    this.primaryProjectName,
    this.primarySiteId,
    this.primarySiteName,
    this.userId,
    this.dateOfBirth,
    this.nationality,
    this.address,
    this.emergencyContactName,
    this.emergencyContactPhone,
    this.emergencyContactRelation,
    this.salary,
    this.salaryVisible = false,
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String employeeCode;
  final String firstName;
  final String? middleName;
  final String lastName;

  /// Server-side accessor over first/middle/last. Always non-empty there,
  /// but read defensively: a name is not worth crashing a list over.
  final String fullName;

  final String? email;
  final String? phone;
  final String? photoPath;

  final int? departmentId;
  final String? departmentName;
  final int? designationId;
  final String? designationName;

  /// One of `permanent`, `contract`, `probation`, `internship`, `part_time`.
  final String employmentType;

  /// One of `active`, `inactive`, `resigned`, `terminated`, `on_leave`.
  final String employmentStatus;

  /// `YYYY-MM-DD`, or null.
  final String? joiningDate;

  final int? reportingManagerId;
  final String? reportingManagerName;

  final int? primaryProjectId;
  final String? primaryProjectName;
  final int? primarySiteId;
  final String? primarySiteName;

  /* -------------------------------------------------- detail only */

  final int? userId;
  final String? dateOfBirth;
  final String? nationality;
  final String? address;
  final String? emergencyContactName;
  final String? emergencyContactPhone;
  final String? emergencyContactRelation;

  /// Null whenever the caller was not granted `employees.salary.view` — the
  /// key is omitted from the payload entirely in that case, not sent as zero.
  final double? salary;

  /// True only when the caller both holds `employees.salary.view` and holds
  /// `employees.view`; it is what a screen should gate the salary column on.
  final bool salaryVisible;

  final String? createdAt;
  final String? updatedAt;

  bool get isActive => employmentStatus == 'active';

  /// What a list row shows underneath the name.
  String get summary {
    final parts = <String>[employeeCode];

    if (designationName != null) parts.add(designationName!);
    if (departmentName != null) parts.add(departmentName!);

    return parts.join(' · ');
  }

  factory Employee.fromJson(Map<String, dynamic> json) => Employee(
        id: _int(json['id']) ?? 0,
        employeeCode: json['employee_code'] as String? ?? '',
        firstName: json['first_name'] as String? ?? '',
        middleName: json['middle_name'] as String?,
        lastName: json['last_name'] as String? ?? '',
        fullName: json['full_name'] as String? ?? '',
        email: json['email'] as String?,
        phone: json['phone'] as String?,
        photoPath: json['photo_path'] as String?,
        departmentId: _int(json['department_id']),
        departmentName: _nestedName(json['department']),
        designationId: _int(json['designation_id']),
        designationName: _nestedName(json['designation']),
        employmentType: json['employment_type'] as String? ?? 'permanent',
        employmentStatus: json['employment_status'] as String? ?? 'active',
        joiningDate: json['joining_date'] as String?,
        reportingManagerId: _int(json['reporting_manager_id']),
        reportingManagerName: _employeeName(json['reporting_manager']),
        primaryProjectId: _int(json['primary_project_id']),
        primaryProjectName: _nestedName(json['primary_project']),
        primarySiteId: _int(json['primary_site_id']),
        primarySiteName: _nestedName(json['primary_site']),
        userId: _int(json['user_id']),
        dateOfBirth: json['date_of_birth'] as String?,
        nationality: json['nationality'] as String?,
        address: json['address'] as String?,
        emergencyContactName: json['emergency_contact_name'] as String?,
        emergencyContactPhone: json['emergency_contact_phone'] as String?,
        emergencyContactRelation:
            json['emergency_contact_relation'] as String?,
        salary: _money(json['salary']),
        salaryVisible: json['salary_visible'] == true,
        createdAt: json['created_at'] as String?,
        updatedAt: json['updated_at'] as String?,
      );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

double? _money(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}

/// Reads an embedded `{id, name}` — the shape every relation in this API
/// uses. Anything else, including a `null`, yields no name rather than a
/// guess.
String? _nestedName(Object? value) {
  if (value is! Map) return null;

  final name = value['name'];
  return name is String ? name : null;
}

/// The reporting manager is the one relation that is *not* a two-field
/// reference: `EmployeeResource` is embedded whole, and it spells a person
/// `full_name`. Reading both keys means this survives either shape instead
/// of quietly rendering "no manager" for someone who has one.
String? _employeeName(Object? value) {
  if (value is! Map) return null;

  final full = value['full_name'];
  if (full is String) return full;

  final name = value['name'];
  return name is String ? name : null;
}
