import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/projects/presentation/project_detail_screen.dart';
import 'package:mobile/features/projects/presentation/projects_list_screen.dart';

import '../../support/phase4.dart';

Project marina({List<Map<String, dynamic>> events = const []}) =>
    Project.fromJson({
      'id': 1,
      'name': 'Marina Tower',
      'code': 'MT-1',
      'status': 'handing_over',
      'project_manager_id': 5,
      'project_manager': {'full_name': 'Ravi Menon'},
      'upcoming_events': [
        {
          'id': 11,
          'type': 'dcd_inspection',
          'label': 'DCD inspection',
          'event_date': '2099-10-10',
        },
        {
          'id': 12,
          'type': 'civil_defence',
          'label': 'Civil Defence inspection',
          'event_date': '2099-10-20',
        },
      ],
      'events': events,
    });

void main() {
  testWidgets('HR sees status, project manager and the next inspection', (
    tester,
  ) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view'],
        projects: ScriptedProjects(idOf: (p) => p.id, items: [marina()]),
        child: const MaterialApp(home: ProjectsListScreen()),
      ),
    );
    await advance(tester);

    expect(find.text('Marina Tower'), findsOneWidget);
    expect(find.text('MT-1 · PM: Ravi Menon'), findsOneWidget);
    expect(find.text('Handing over'), findsOneWidget);
    expect(find.text('DCD inspection · 10 Oct 2099 (+1 more)'), findsOneWidget);
    // HR reads the list; it does not add projects.
    expect(find.byKey(const ValueKey('add-project')), findsNothing);
  });

  testWidgets('the status filter offers Ongoing, Handing over, Completed', (
    tester,
  ) async {
    expect(projectStatusLabel('active'), 'Ongoing');
    expect(projectStatusLabel('handing_over'), 'Handing over');
    expect(projectStatusLabel('completed'), 'Completed');
  });

  testWidgets('HR reads the dates on the project page but cannot book one', (
    tester,
  ) async {
    useTallScreen(tester);
    final project = marina(
      events: [
        {
          'id': 10,
          'type': 'dcd_inspection',
          'label': 'DCD inspection',
          'event_date': '2020-01-01',
        },
        {
          'id': 11,
          'type': 'dcd_inspection',
          'label': 'DCD inspection',
          'event_date': '2099-10-10',
        },
      ],
    );
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view'],
        projects: ScriptedProjects(idOf: (p) => p.id, items: [project]),
        child: const MaterialApp(home: ProjectDetailScreen(projectId: 1)),
      ),
    );
    await advance(tester);

    expect(find.text('Inspections & key dates'), findsOneWidget);
    expect(find.byKey(const ValueKey('event-10')), findsOneWidget);
    expect(find.byKey(const ValueKey('event-11')), findsOneWidget);
    expect(find.text('1 Jan 2020 · done'), findsOneWidget);
    expect(find.byKey(const ValueKey('event-add')), findsNothing);
  });

  testWidgets('the project manager books a Civil Defence inspection', (
    tester,
  ) async {
    useTallScreen(tester);
    final projects = ScriptedProjects(idOf: (p) => p.id, items: [marina()]);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view', 'projects.manage'],
        projects: projects,
        child: const MaterialApp(home: ProjectDetailScreen(projectId: 1)),
      ),
    );
    await advance(tester);

    await tester.tap(find.byKey(const ValueKey('event-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('event-type')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Civil Defence inspection').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('event-date')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('event-save')));
    await tester.pumpAndSettle();

    expect(projects.addedEvents, hasLength(1));
    expect(projects.addedEvents.single['type'], 'civil_defence');
    expect(projects.addedEvents.single['event_date'], isA<String>());
  });
}
