import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/permissions/permission_scope.dart';

import '../../support/fakes.dart';

/// The strings the seeders actually grant, in the combinations the server
/// hands to each role.
///
/// Mirrored here rather than fetched so a change to the seeders that would
/// silently widen the app's buttons has to be mirrored too — a test that
/// re-derived its own fixtures from the code under test would prove nothing.
void main() {
  PermissionScope withPermissions(List<String> permissions) =>
      PermissionScope(buildUser(permissions: permissions));

  const hrAdmin = [
    'employees.view',
    'employees.create',
    'employees.update',
    'employees.delete',
    'employees.salary.view',
    'departments.view',
    'departments.manage',
    'designations.view',
    'designations.manage',
    'projects.view',
    'projects.manage',
    'sites.view',
    'sites.manage',
    'assignments.view',
    'assignments.manage',
  ];

  const hrExecutive = [
    'employees.view',
    'employees.create',
    'employees.update',
    'departments.view',
    'departments.manage',
    'designations.view',
    'designations.manage',
    'projects.view',
    'sites.view',
    'assignments.view',
    'assignments.manage',
  ];

  const projectManager = [
    'employees.view',
    'projects.view',
    'projects.manage',
    'sites.view',
    'assignments.view',
    'assignments.manage',
  ];

  test('an unsigned-out session fails closed', () {
    const scope = PermissionScope(null);

    expect(scope.can('employees.view'), isFalse);
    expect(scope.canViewEmployees, isFalse);
    // The one that matters most: "no session" must never read as "may see
    // payroll", however the permission list is spelled elsewhere.
    expect(scope.canViewSalary, isFalse);
    expect(
      scope.canAny(const ['employees.view', 'employees.salary.view']),
      isFalse,
    );
  });

  test('can / canAny / canAll behave the way the screens phrase them', () {
    final scope = withPermissions(['employees.view', 'projects.view']);

    expect(scope.can('employees.view'), isTrue);
    expect(scope.can('employees.create'), isFalse);
    expect(scope.canAny(['employees.create', 'projects.view']), isTrue);
    expect(scope.canAny(['employees.create', 'departments.view']), isFalse);
    expect(scope.canAll(['employees.view', 'projects.view']), isTrue);
    expect(scope.canAll(['employees.view', 'employees.create']), isFalse);
    expect(scope.canAll(const <String>[]), isTrue);
  });

  group('the salary gate', () {
    test('employees.view alone never implies employees.salary.view', () {
      final scope = withPermissions(const ['employees.view']);

      expect(scope.canViewEmployees, isTrue);
      expect(scope.canViewSalary, isFalse);
    });

    test('an HR executive reads the roster but not the payroll', () {
      final scope = withPermissions(hrExecutive);

      expect(scope.canViewEmployees, isTrue);
      expect(scope.canCreateEmployees, isTrue);
      expect(scope.canViewSalary, isFalse);
      // And no delete either — the seeder deliberately leaves it off.
      expect(scope.canDeleteEmployees, isFalse);
    });

    test('an HR admin may see salary', () {
      final scope = withPermissions(hrAdmin);

      expect(scope.canViewSalary, isTrue);
      expect(scope.canDeleteEmployees, isTrue);
    });
  });

  test('a project manager may not open the employee records', () {
    final scope = withPermissions(projectManager);

    expect(scope.canViewEmployees, isTrue);
    expect(scope.canCreateEmployees, isFalse);
    expect(scope.canViewProjects, isTrue);
    expect(scope.canManageProjects, isTrue);
    expect(scope.canViewSites, isTrue);
    expect(scope.canManageSites, isFalse);
    expect(scope.canViewDepartments, isFalse);
  });

  test('group helpers map onto the module navigation', () {
    final executive = withPermissions(hrExecutive);

    expect(executive.canViewEmployees, isTrue);
    expect(executive.canViewDepartments, isTrue);
    expect(executive.canViewDesignations, isTrue);
    expect(executive.canViewProjects, isTrue);
    expect(executive.canViewSites, isTrue);

    final auditor = withPermissions(const ['settings.view']);

    expect(auditor.canViewEmployees, isFalse);
    expect(auditor.canViewDepartments, isFalse);
    expect(auditor.canViewDesignations, isFalse);
    expect(auditor.canViewProjects, isFalse);
    expect(auditor.canViewSites, isFalse);
  });

  test('a typo in a permission string reads as denied, not as granted', () {
    final scope = withPermissions(const ['employees.view']);

    expect(scope.can('employees.view '), isFalse);
    expect(scope.can('employees.View'), isFalse);
    expect(scope.canViewAssignments, isFalse);
    expect(scope.canManageAssignments, isFalse);
  });
}
