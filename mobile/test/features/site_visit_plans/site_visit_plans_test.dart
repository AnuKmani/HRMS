import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/projects/data/api_projects_repository.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/site_visit_plans/site_visit_plans.dart';
import 'package:mobile/features/site_visit_plans/site_visit_plans_screens.dart';
import 'package:mobile/features/sites/data/api_sites_repository.dart';
import 'package:mobile/features/sites/domain/site.dart';

import '../../support/fakes.dart';
import '../../support/phase4.dart';

SiteVisitPlan plan(
  int id, {
  String status = 'planned',
  bool mine = false,
  bool byMe = false,
}) => SiteVisitPlan(
  id: id,
  visitDate: '2099-10-15',
  status: status,
  purpose: 'Slab inspection',
  projectName: 'Marina Tower',
  siteName: 'Block A',
  employeeName: 'Ravi Kumar',
  assignedByName: 'Priya PM',
  isMine: mine,
  assignedByMe: byMe,
);

class FakePlans implements SiteVisitPlansRepository {
  FakePlans(this.plans);

  List<SiteVisitPlan> plans;
  final List<Map<String, Object?>> created = [];
  final List<int> completed = [];
  final List<int> cancelled = [];

  @override
  Future<List<SiteVisitPlan>> list({String? scope, String? status}) async =>
      plans
          .where((p) => scope != 'mine' || p.isMine)
          .where(
            (p) => status == null || status == 'upcoming'
                ? (status == null || p.status == 'planned')
                : p.status == status,
          )
          .toList();

  @override
  Future<List<SiteVisitPlan>> create(Map<String, Object?> body) async {
    created.add(body);
    final ids = body['employee_ids']! as List;
    return [for (final id in ids) plan(100 + (id as int), byMe: true)];
  }

  @override
  Future<SiteVisitPlan> complete(int id, {String? note}) async {
    completed.add(id);
    plans = [
      for (final p in plans)
        p.id == id ? plan(id, status: 'completed', mine: true) : p,
    ];
    return plans.firstWhere((p) => p.id == id);
  }

  @override
  Future<SiteVisitPlan> cancel(int id) async {
    cancelled.add(id);
    return plan(id, status: 'cancelled', byMe: true);
  }

  @override
  Future<List<PlanAssignee>> assignees() async => const [
    PlanAssignee(id: 1, name: 'Ravi Kumar', role: 'Site Engineer'),
    PlanAssignee(id: 2, name: 'Anu Joseph', role: 'Site Engineer'),
  ];
}

Widget scoped(Widget child, FakePlans plans, List<String> permissions) =>
    ProviderScope(
      overrides: [
        permissionScopeProvider.overrideWithValue(
          PermissionScope(buildUser(permissions: permissions)),
        ),
        siteVisitPlansRepositoryProvider.overrideWithValue(plans),
        projectsRepositoryProvider.overrideWithValue(
          ScriptedProjects(
            idOf: (p) => p.id,
            items: [
              const Project(
                id: 7,
                name: 'Marina Tower',
                code: 'MT',
                status: 'active',
              ),
            ],
          ),
        ),
        sitesRepositoryProvider.overrideWithValue(
          ScriptedSites(
            idOf: (s) => s.id,
            items: [
              const Site(
                id: 3,
                name: 'Block A',
                code: 'BA',
                projectId: 7,
                status: 'active',
              ),
            ],
          ),
        ),
      ],
      child: MaterialApp(home: child),
    );

void main() {
  testWidgets('the project manager plans one visit for two engineers', (
    tester,
  ) async {
    useTallScreen(tester);
    final plans = FakePlans([]);
    await tester.pumpWidget(
      scoped(const SiteVisitPlansScreen(), plans, const [
        'site_visit_plans.manage',
      ]),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('plan-visits')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('plan-person-1')));
    await tester.tap(find.byKey(const ValueKey('plan-person-2')));
    await tester.tap(find.byKey(const ValueKey('plan-project')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Marina Tower').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('plan-date')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('plan-purpose')),
      'Slab inspection',
    );
    await tester.pump();

    expect(find.text('Plan for 2 engineers'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('plan-save')));
    await tester.pumpAndSettle();

    expect(plans.created, hasLength(1));
    expect(plans.created.single['employee_ids'], [1, 2]);
    expect(plans.created.single['project_id'], 7);
    expect(plans.created.single['purpose'], 'Slab inspection');
    expect(
      find.text('2 visits planned. The engineers have been told.'),
      findsOneWidget,
    );
  });

  testWidgets('both sides see the status; the engineer marks it completed', (
    tester,
  ) async {
    useTallScreen(tester);
    final plans = FakePlans([plan(1, mine: true)]);
    await tester.pumpWidget(
      scoped(const SiteVisitPlansScreen(), plans, const [
        'site_visit_plans.view',
      ]),
    );
    await tester.pumpAndSettle();

    expect(
      (tester.widget<Chip>(find.byKey(const ValueKey('plan-status-1'))).label
              as Text)
          .data,
      'Planned',
    );
    expect(find.text('Block A · Marina Tower'), findsOneWidget);
    expect(find.byKey(const ValueKey('plan-visits')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('plan-complete-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('plan-complete-confirm')));
    await tester.pumpAndSettle();

    expect(plans.completed, [1]);
    final chip = tester.widget<Chip>(
      find.byKey(const ValueKey('plan-status-1')),
    );
    expect((chip.label as Text).data, 'Completed');
  });

  testWidgets('the planner sees Missed and can cancel what is still planned', (
    tester,
  ) async {
    useTallScreen(tester);
    final plans = FakePlans([
      plan(1, status: 'missed', byMe: true),
      plan(2, byMe: true),
    ]);
    await tester.pumpWidget(
      scoped(const SiteVisitPlansScreen(), plans, const [
        'site_visit_plans.manage',
      ]),
    );
    await tester.pumpAndSettle();

    final chip = tester.widget<Chip>(
      find.byKey(const ValueKey('plan-status-1')),
    );
    expect((chip.label as Text).data, 'Missed');
    expect(find.text('Engineer: Ravi Kumar'), findsNWidgets(2));
    expect(find.byKey(const ValueKey('plan-cancel-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('plan-complete-2')), findsNothing);
  });

  testWidgets("the engineer's dashboard card lists what is next", (
    tester,
  ) async {
    final plans = FakePlans([plan(1, mine: true), plan(2, mine: true)]);
    await tester.pumpWidget(
      scoped(
        Scaffold(body: MyPlannedVisitsCard(onOpenAll: () {})),
        plans,
        const ['site_visit_plans.view'],
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('my-plan-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('my-plan-2')), findsOneWidget);
  });
}
