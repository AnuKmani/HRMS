import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/auth_models.dart';
import '../../features/auth/auth_controller.dart';

/// One place the app asks "may the signed-in user do this?".
///
/// **This is convenience, not authorization.** Every one of these answers is
/// derived from the permission list the server attached to the session, and
/// the server independently enforces the same rules behind `permission:`
/// middleware and policies on every route (see docs/SECURITY.md). Hiding a
/// button stops an honest user from tapping something that would only come
/// back as a 403; it is not, and is not meant to be, a boundary.
///
/// The class exists rather than scattering `user.can('employees.view')` across
/// widgets for two reasons: the strings are written once, so a typo in a
/// screen cannot silently render as "no permission"; and callers can ask for a
/// *group* ("anything on this module") without inventing their own
/// `||` chain each time.
class PermissionScope {
  const PermissionScope(this._user);

  /// Null while signed out — and every answer below fails closed to `false`.
  final AuthUser? _user;

  bool can(String permission) => _user?.can(permission) ?? false;

  bool canAny(Iterable<String> permissions) {
    for (final permission in permissions) {
      if (can(permission)) return true;
    }
    return false;
  }

  bool canAll(Iterable<String> permissions) {
    for (final permission in permissions) {
      if (!can(permission)) return false;
    }
    return true;
  }

  /* ------------------------------------------------------- employees */

  bool get canViewEmployees => can('employees.view');

  bool get canCreateEmployees => can('employees.create');

  bool get canUpdateEmployees => can('employees.update');

  bool get canDeleteEmployees => can('employees.delete');

  /// The third gate. Holding `employees.view` does **not** imply it: payroll
  /// figures are shown only to roles the seeders granted `employees.salary.view`
  /// to (Super Admin, HR Admin, Payroll Admin, Finance).
  bool get canViewSalary => can('employees.salary.view');

  bool get canEditEmployees => canCreateEmployees || canUpdateEmployees;

  /* --------------------------------------------------- departments */

  bool get canViewDepartments => can('departments.view');

  bool get canManageDepartments => can('departments.manage');

  /* --------------------------------------------------- designations */

  bool get canViewDesignations => can('designations.view');

  bool get canManageDesignations => can('designations.manage');

  /* ------------------------------------------------------- projects */

  bool get canViewProjects => can('projects.view');

  bool get canManageProjects => can('projects.manage');

  /* ---------------------------------------------------------- sites */

  bool get canViewSites => can('sites.view');

  bool get canManageSites => can('sites.manage');

  /* ---------------------------------------------------- assignments */

  bool get canViewAssignments => can('assignments.view');

  bool get canManageAssignments => can('assignments.manage');
}

/// Recomputed whenever the session changes, so a permission revoked by a
/// server-side role change reaches the UI on the next rebuild rather than
/// being cached from sign-in.
final permissionScopeProvider = Provider<PermissionScope>((ref) {
  final user = ref.watch(authControllerProvider.select((state) => state.user));

  return PermissionScope(user);
});
