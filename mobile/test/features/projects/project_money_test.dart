import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/projects/domain/project_delivery.dart';

void main() {
  test('money is grouped in thousands with the currency', () {
    expect(money(1250000), 'AED 1,250,000');
    expect(money(950), 'AED 950');
    expect(money(null), '—');
  });

  test('a system knows its value, billing and billed share', () {
    final s = ProjectStage.fromJson({
      'id': 1,
      'name': 'HVAC',
      'weight': 60,
      'percent_complete': 50,
      'contract_value': 600000,
      'billed_amount': 300000,
    });
    expect(s.contractValue, 600000);
    expect(s.billedPercent, 50);
  });

  test('a project says who may coordinate, reassign and see money', () {
    final p = Project.fromJson({
      'id': 1,
      'name': 'Tower',
      'code': 'PRJ-0001',
      'status': 'active',
      'can_coordinate': true,
      'can_reassign': true,
      'can_see_money': true,
      'subcontractors': [
        {
          'id': 3,
          'name': 'Cool Air',
          'status': 'active',
          'contract_value': 200000,
          'billed_amount': 50000,
          'project_stage_id': 1,
        },
      ],
      'team': [
        {'employee_id': 9, 'name': 'Riya', 'role': 'coordinator'},
      ],
    });
    expect(p.canCoordinate && p.canReassign && p.canSeeMoney, isTrue);
    expect(p.subcontractors.single.billedAmount, 50000);
    expect(p.subcontractors.single.projectStageId, 1);
    expect(p.team.single.roleLabel, 'Project coordinator');
  });
}
