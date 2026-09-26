/// The employee record attached to a login, or null for an account that has
/// no HR record yet (an administrator who has not been onboarded).
///
/// Matches `EmployeeBriefResource` on the server: enough to greet someone by
/// their real name and say which team they sit in. Salary, date of birth and
/// contact details are deliberately absent from that resource, so they cannot
/// appear here either — see docs/API_DOCUMENTATION.md §"GET /auth/me".
class EmployeeBrief {
  const EmployeeBrief({
    required this.id,
    required this.employeeCode,
    required this.fullName,
    this.photoPath,
    this.department,
    this.designation,
    required this.employmentType,
    required this.employmentStatus,
  });

  final int id;
  final String employeeCode;

  /// Display name. Server-side this is an accessor over first/middle/last
  /// name rather than a column, and always returns a non-empty string.
  final String fullName;

  final String? photoPath;

  /// Null when the employee has no department assigned yet.
  final String? department;

  /// Null when the employee has no designation assigned yet.
  final String? designation;

  final String employmentType;
  final String employmentStatus;

  factory EmployeeBrief.fromJson(Map<String, dynamic> json) => EmployeeBrief(
        id: json['id'] as int,
        employeeCode: json['employee_code'] as String,
        fullName: json['full_name'] as String,
        photoPath: json['photo_path'] as String?,
        department: json['department'] as String?,
        designation: json['designation'] as String?,
        employmentType: json['employment_type'] as String,
        employmentStatus: json['employment_status'] as String,
      );
}

/// The signed-in user, matching `UserResource`.
///
/// Carries who they are, what they may do, and which HR record backs them —
/// and nothing else. The bearer token is *not* part of this object: the
/// server returns it exactly once from the login endpoint and cannot return
/// it again, because it only stores a hash of it.
class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.status,
    required this.roles,
    required this.permissions,
    this.employee,
  });

  final int id;
  final String name;
  final String email;

  /// `active` or `deactivated`. A deactivated account cannot sign in, and one
  /// signed in before deactivation loses its session on the next request that
  /// reaches a permission check.
  final String status;

  /// Role and permission names, already flattened and sorted by the server.
  ///
  /// These exist so the UI can hide what the user cannot use. They are not a
  /// boundary: every real check happens behind a `permission:` middleware on
  /// the route (see docs/SECURITY.md §"Authorization").
  final List<String> roles;
  final List<String> permissions;

  final EmployeeBrief? employee;

  bool get isActive => status == 'active';

  bool can(String permission) => permissions.contains(permission);

  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
        id: json['id'] as int,
        name: json['name'] as String,
        email: json['email'] as String,
        status: json['status'] as String? ?? 'active',
        roles: _stringList(json['roles']),
        permissions: _stringList(json['permissions']),
        employee: json['employee'] is Map<String, dynamic>
            ? EmployeeBrief.fromJson(json['employee']! as Map<String, dynamic>)
            : null,
      );
}

/// A successful POST /auth/login.
class LoginResult {
  const LoginResult({
    required this.token,
    required this.tokenType,
    required this.expiresAt,
    required this.user,
  });

  /// The only time this value exists outside the device: the server keeps a
  /// SHA-256 hash of it and cannot reproduce the original.
  final String token;

  final String tokenType;

  /// Null for a token that never expires, which is what the API issues today.
  final DateTime? expiresAt;

  final AuthUser user;
}

/// Defensive list coercion: the server always sends arrays here, but a shape
/// change should degrade to "no permissions" — which fails closed — rather
/// than throw and leave the app unable to even render the sign-in screen.
List<String> _stringList(Object? value) {
  if (value is! List) return const <String>[];

  return <String>[for (final item in value) if (item is String) item];
}
