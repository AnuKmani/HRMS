import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/attendance/data/device_location.dart';
import 'package:mobile/features/attendance/domain/location_fix.dart';
import 'package:mobile/features/employees/data/api_employees_repository.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/projects/data/api_projects_repository.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/site_assignments/quick_site_setup_screen.dart';
import 'package:mobile/features/site_assignments/site_assignments.dart';
import 'package:mobile/features/sites/data/api_sites_repository.dart';
import 'package:mobile/features/sites/domain/site.dart';

import '../../support/attendance.dart';
import '../../support/fakes.dart';
import '../../support/phase4.dart';

class FakeAssignments implements SiteAssignmentsRepository {
  final List<Map<String, Object?>> created = [];

  @override
  Future<PageResult<SiteAssignment>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async => const PageResult<SiteAssignment>(
    items: [],
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
    hasNext: false,
  );

  @override
  Future<SiteAssignment> create(Map<String, Object?> body) async {
    created.add(body);
    return SiteAssignment.fromJson({
      'id': 1,
      'employee_id': body['employee_id'],
      'site_id': body['site_id'],
      'project_id': body['project_id'],
      'assignment_type': body['assignment_type'],
      'start_date': body['start_date'],
      'status': 'active',
    });
  }

  @override
  Future<SiteAssignment> end(int id, {required String endDate}) =>
      throw UnimplementedError();
}

void main() {
  testWidgets('creates the project, the site at this GPS fix, and the '
      'assignment', (tester) async {
    useTallScreen(tester);

    final employees = ScriptedEmployees(
      idOf: (employee) => employee.id,
      items: [
        Employee.fromJson({
          'id': 5,
          'employee_code': 'UAT-010',
          'full_name': 'UAT Employee',
          'first_name': 'UAT',
          'last_name': 'Employee',
          'email': 'uat.employee@uat.local',
          'employment_status': 'active',
        }),
      ],
    );
    final projects = ScriptedProjects(idOf: (project) => project.id)
      ..onSave = (body) => Project.fromJson({
        'id': 70,
        'name': body['name'],
        'code': body['code'],
        'status': body['status'],
      });
    final sites = ScriptedSites(idOf: (site) => site.id)
      ..onSave = (body) => Site.fromJson({
        'id': 80,
        'name': body['name'],
        'code': body['code'],
        'project_id': body['project_id'],
        'latitude': body['latitude'],
        'longitude': body['longitude'],
        'geofence_radius': body['geofence_radius'],
        'status': body['status'],
      });
    final assignments = FakeAssignments();
    final location = ScriptedLocation()
      ..fix = const LocationFix(
        latitude: 25.2048493,
        longitude: 55.2707828,
        accuracy: 12,
      );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          permissionScopeProvider.overrideWithValue(
            PermissionScope(
              buildUser(
                permissions: const [
                  'projects.manage',
                  'sites.manage',
                  'assignments.manage',
                ],
              ),
            ),
          ),
          employeesRepositoryProvider.overrideWithValue(employees),
          projectsRepositoryProvider.overrideWithValue(projects),
          sitesRepositoryProvider.overrideWithValue(sites),
          siteAssignmentsRepositoryProvider.overrideWithValue(assignments),
          locationGatewayProvider.overrideWithValue(location),
        ],
        child: const MaterialApp(home: QuickSiteSetupScreen()),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('quick-employee')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('UAT Employee').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('quick-setup-run')));
    await tester.pumpAndSettle();

    expect(projects.lastBody?['name'], 'General');
    expect(projects.lastBody?['status'], 'active');

    expect(sites.lastBody?['project_id'], 70);
    expect(sites.lastBody?['name'], 'Main site');
    expect(sites.lastBody?['latitude'], 25.2048493);
    expect(sites.lastBody?['longitude'], 55.2707828);
    expect(sites.lastBody?['geofence_radius'], 200);

    expect(assignments.created.single['employee_id'], 5);
    expect(assignments.created.single['site_id'], 80);
    expect(assignments.created.single['project_id'], 70);
    expect(assignments.created.single['assignment_type'], 'primary');

    expect(find.text('All set'), findsOneWidget);
  });

  testWidgets('nothing is created without an employee', (tester) async {
    useTallScreen(tester);
    final sites = ScriptedSites(idOf: (site) => site.id);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          permissionScopeProvider.overrideWithValue(
            PermissionScope(
              buildUser(
                permissions: const ['sites.manage', 'assignments.manage'],
              ),
            ),
          ),
          sitesRepositoryProvider.overrideWithValue(sites),
          locationGatewayProvider.overrideWithValue(ScriptedLocation()),
        ],
        child: const MaterialApp(home: QuickSiteSetupScreen()),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('quick-setup-run')));
    await tester.pumpAndSettle();

    expect(find.text('Choose the employee.'), findsOneWidget);
    expect(sites.createCalls, 0);
  });
}
