import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/projects/presentation/project_detail_screen.dart';
import 'package:mobile/features/projects/presentation/projects_list_screen.dart';

import '../../support/phase4.dart';

Project heights({bool canEdit = true}) => Project.fromJson({
  'id': 1,
  'name': 'Marina Heights',
  'code': 'PRJ-0001',
  'status': 'active',
  'main_contractor': 'ALEC',
  'created_by_name': 'Ravi Menon',
  'can_edit': canEdit,
  'progress_percent': 46,
  'expected_percent': 75,
  'behind_schedule': true,
  'stages': [
    {'id': 1, 'name': 'Foundation', 'weight': 40, 'percent_complete': 100},
    {'id': 2, 'name': 'Structure', 'weight': 60, 'percent_complete': 10},
  ],
  'subcontractors': [
    {'id': 7, 'name': 'Cool Air LLC', 'trade': 'HVAC', 'status': 'active'},
    {
      'id': 8,
      'name': 'Old Tiles',
      'status': 'removed',
      'removed_on': '2026-09-20',
      'removed_reason': 'Delayed works',
    },
  ],
  'team': [
    {'employee_id': 3, 'name': 'Anil Kumar', 'role': 'site_engineer'},
  ],
  'payment_milestones': [
    {
      'id': 4,
      'name': 'Foundation complete',
      'trigger_percent': 40,
      'status': 'reached',
    },
  ],
  'documents': [
    {'id': 9, 'title': 'Ground floor plan', 'category': 'drawing'},
  ],
});

void main() {
  testWidgets('the list shows progress and the behind-schedule flag', (
    tester,
  ) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view'],
        projects: ScriptedProjects(idOf: (p) => p.id, items: [heights()]),
        child: const MaterialApp(home: ProjectsListScreen()),
      ),
    );
    await advance(tester);

    expect(find.byKey(const ValueKey('project-progress-1')), findsOneWidget);
    expect(find.text('46%'), findsOneWidget);
    expect(find.byKey(const ValueKey('behind-schedule')), findsOneWidget);
  });

  testWidgets('the creator sees progress, contractors, team and can edit', (
    tester,
  ) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view', 'projects.manage'],
        projects: ScriptedProjects(idOf: (p) => p.id, items: [heights()]),
        child: const MaterialApp(home: ProjectDetailScreen(projectId: 1)),
      ),
    );
    await advance(tester);

    expect(find.text('46%'), findsOneWidget);
    expect(find.text('Expected by today: 75%'), findsOneWidget);
    expect(find.text('Main contractor: ALEC'), findsOneWidget);
    expect(find.text('Cool Air LLC'), findsOneWidget);
    expect(find.text('Removed (1)'), findsOneWidget);
    expect(find.text('Anil Kumar'), findsOneWidget);
    expect(find.text('Foundation complete'), findsOneWidget);
    expect(find.text('Ground floor plan'), findsOneWidget);
    expect(find.byKey(const ValueKey('sub-add')), findsOneWidget);
    expect(find.byKey(const ValueKey('stages-edit')), findsOneWidget);
    expect(find.byKey(const ValueKey('detail-edit')), findsOneWidget);
  });

  testWidgets('another project manager reads it but cannot change it', (
    tester,
  ) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view', 'projects.manage'],
        projects: ScriptedProjects(
          idOf: (p) => p.id,
          items: [heights(canEdit: false)],
        ),
        child: const MaterialApp(home: ProjectDetailScreen(projectId: 1)),
      ),
    );
    await advance(tester);

    expect(find.text('Cool Air LLC'), findsOneWidget);
    expect(find.byKey(const ValueKey('detail-edit')), findsNothing);
    expect(find.byKey(const ValueKey('sub-add')), findsNothing);
    expect(find.byKey(const ValueKey('stages-edit')), findsNothing);
    expect(find.byKey(const ValueKey('event-add')), findsNothing);
    expect(find.text('Only Ravi Menon can change it.'), findsOneWidget);
  });
}
