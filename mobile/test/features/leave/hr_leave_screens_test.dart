import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/leave/domain/leave_balance.dart';
import 'package:mobile/features/leave/domain/leave_type.dart';
import 'package:mobile/features/leave/presentation/hr_leave_screens.dart';

void main() {
  test('an unlimited leave type says so instead of a number of days', () {
    final type = LeaveType.fromJson({
      'id': 2,
      'name': 'Sick Leave',
      'code': 'SL',
      'entitlement_days': 0,
      'is_unlimited': true,
      'is_paid': true,
      'status': 'active',
    });
    expect(type.isUnlimited, isTrue);
    expect(type.pickerLabel, 'Sick Leave · unlimited');

    final balance = LeaveBalance.fromJson({
      'id': 1,
      'employee_id': 1,
      'leave_type_id': 2,
      'year': 2026,
      'entitlement': 0,
      'used': 2,
      'pending': 0,
      'remaining': -2,
      'unlimited': true,
    });
    expect(balance.remainingLabel, 'Unlimited');
  });

  test('the overview row reads balances and request counts', () {
    final row = EmployeeLeaveRow.fromJson({
      'employee_id': 7,
      'name': 'Arun Lal',
      'employee_code': 'EMP-0007',
      'balances': [
        {
          'id': 1,
          'leave_type': 'Annual Leave',
          'code': 'AL',
          'entitlement': 30,
          'adjustment': 0,
          'carry_forward': 0,
          'used': 5,
          'pending': 2,
          'remaining': 23,
          'unlimited': false,
        },
      ],
      'requests': {'approved': 2, 'pending': 1, 'rejected': 0},
    });
    expect(row.balances.single.leftLabel, '23 left');
    expect(row.requests['approved'], 2);
  });
}
