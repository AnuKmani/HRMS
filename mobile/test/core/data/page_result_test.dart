import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/departments/domain/department.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/sites/domain/site.dart';

ApiEnvelope envelope(Object? data) =>
    ApiEnvelope(message: 'ok', data: data);

void main() {
  group('the list envelope', () {
    test('reads items and every piece of metadata the screens use', () {
      final page = PageResult<int>.fromEnvelope(
        envelope({
          'items': [
            {'value': 1},
            {'value': 2},
          ],
          'meta': {
            'current_page': 3,
            'last_page': 5,
            'per_page': 2,
            'total': 9,
            'has_next': true,
          },
        }),
        (json) => json['value']! as int,
      );

      expect(page.items, [1, 2]);
      expect(page.currentPage, 3);
      expect(page.lastPage, 5);
      expect(page.perPage, 2);
      expect(page.total, 9);
      expect(page.hasNext, isTrue);
    });

    test('derives has_next when the server does not send it', () {
      // `has_next` is a courtesy the API adds; `PaginatedResponse` may be
      // talking to an older build. Falling back to comparing the page
      // numbers means the load-more button does not disappear for a reason
      // nobody can see.
      final page = PageResult<int>.fromEnvelope(
        envelope({
          'items': <Object>[],
          'meta': {'current_page': 1, 'last_page': 4, 'total': 60},
        }),
        (json) => 0,
      );

      expect(page.hasNext, isTrue);
      expect(page.isEmpty, isTrue);
    });

    test('has nothing left to load on the final page', () {
      final page = PageResult<int>.fromEnvelope(
        envelope({
          'items': <Object>[],
          'meta': {'current_page': 4, 'last_page': 4},
        }),
        (json) => 0,
      );

      expect(page.hasNext, isFalse);
    });

    test('refuses a payload it does not understand', () {
      // An empty page and an unreadable one are different answers, and only
      // one of them may be drawn as "there is nothing here".
      expect(
        () => PageResult<int>.fromEnvelope(
          envelope(['not', 'an', 'object']),
          (json) => 0,
        ),
        throwsA(isA<ApiException>()),
      );
    });

    test('refuses a meta block that is missing', () {
      expect(
        () => PageResult<int>.fromEnvelope(
          envelope({
            'items': <Object>[],
          }),
          (json) => 0,
        ),
        throwsA(isA<ApiException>()),
      );
    });

    test('refuses an item that is not an object', () {
      expect(
        () => PageResult<int>.fromEnvelope(
          envelope({
            'items': ['oops'],
            'meta': <String, Object>{'current_page': 1, 'last_page': 1},
          }),
          (json) => 0,
        ),
        throwsA(isA<ApiException>()),
      );
    });

    test('coerces page numbers that arrived as strings', () {
      final page = PageResult<int>.fromEnvelope(
        envelope({
          'items': <Object>[],
          'meta': {
            'current_page': '2',
            'last_page': '7',
            'total': '40',
          },
        }),
        (json) => 0,
      );

      expect(page.currentPage, 2);
      expect(page.lastPage, 7);
      expect(page.total, 40);
      expect(page.hasNext, isTrue);
    });
  });

  group('Employee.fromJson', () {
    test('a list projection carries no salary at all', () {
      // This is the whole point of the projection: the key is absent rather
      // than null, so a client cannot mistake "not shown" for "paid zero".
      final employee = Employee.fromJson(const {
        'id': 12,
        'employee_code': 'EMP-1001',
        'first_name': 'Asha',
        'last_name': 'Nair',
        'full_name': 'Asha Nair',
        'employment_type': 'permanent',
        'employment_status': 'active',
        'department': {'id': 3, 'name': 'Human Resources'},
        'designation': null,
      });

      expect(employee.salary, isNull);
      expect(employee.salaryVisible, isFalse);
      expect(employee.departmentName, 'Human Resources');
      expect(employee.designationId, isNull);
      expect(employee.designationName, isNull);
      expect(employee.fullName, 'Asha Nair');
    });

    test('a detail projection reads a salary only when it was allowed', () {
      final employee = Employee.fromJson(const {
        'id': 12,
        'employee_code': 'EMP-1001',
        'first_name': 'Asha',
        'last_name': 'Nair',
        'full_name': 'Asha Nair',
        'employment_type': 'contract',
        'employment_status': 'on_leave',
        'salary': '150000.50',
        'salary_visible': true,
        'reporting_manager': {'id': 4, 'full_name': 'Ravi Kumar'},
      });

      expect(employee.salary, 150000.50);
      expect(employee.salaryVisible, isTrue);
      expect(employee.reportingManagerName, 'Ravi Kumar');
    });

    test('an embedded relation with no name yields no name', () {
      final employee = Employee.fromJson(const {
        'id': 1,
        'employee_code': 'EMP-1',
        'first_name': 'A',
        'last_name': 'B',
        'full_name': 'A B',
        'employment_type': 'permanent',
        'employment_status': 'active',
        'primary_site': {'id': 9},
      });

      expect(employee.primarySiteName, isNull);
    });
  });

  group('Site.fromJson', () {
    test('reads decimal strings and keeps an unset geofence unset', () {
      final site = Site.fromJson(const {
        'id': 5,
        'name': 'Block C',
        'code': 'SITE-04',
        'project_id': 2,
        'project': {'id': 2, 'name': 'Riverside', 'code': 'RT-01'},
        'latitude': '12.9715995',
        'longitude': '77.5945667',
        'geofence_radius': '120.00',
        'status': 'active',
      });

      expect(site.latitude, closeTo(12.9715995, 1e-9));
      expect(site.longitude, closeTo(77.5945667, 1e-9));
      expect(site.geofenceRadius, 120.0);
      expect(site.hasGeofence, isTrue);
      expect(site.projectName, 'Riverside');
    });

    test('a site with no geofence reports none rather than zeros', () {
      final site = Site.fromJson(const {
        'id': 6,
        'name': 'Yard',
        'code': 'SITE-05',
        'latitude': null,
        'longitude': null,
        'geofence_radius': null,
        'status': 'inactive',
      });

      expect(site.hasGeofence, isFalse);
      expect(site.geofenceRadius, isNull);
      expect(site.isActive, isFalse);
    });
  });

  group('the other models', () {
    test('a department counts it was never given stay unknown', () {
      final department = Department.fromJson(const {
        'id': 1,
        'name': 'Human Resources',
        'code': 'HR',
        'status': 'active',
      });

      expect(department.employeesCount, isNull);
      expect(department.designationsCount, isNull);
      // The summary says the code and nothing else — printing "0 employees"
      // here would be inventing a number the endpoint never sent.
      expect(department.summary, 'HR');
    });

    test('a project reads the manager out of the embedded resource', () {
      final project = Project.fromJson(const {
        'id': 4,
        'name': 'Riverside Tower',
        'code': 'RT-01',
        'status': 'planned',
        'project_manager_id': 21,
        'project_manager': {'id': 21, 'full_name': 'Meera Iyer'},
        'start_date': '2026-01-05',
        'end_date': null,
      });

      expect(project.projectManagerName, 'Meera Iyer');
      expect(project.isOngoing, isFalse);
      expect(project.dateRange, 'From 2026-01-05');
      expect(project.sitesCount, isNull);
    });
  });
}
