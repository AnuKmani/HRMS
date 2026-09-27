/// One pot of leave for one type in one year.
///
/// Matches `LeaveBalanceResource`. The five numbers are emitted separately
/// because they answer five different questions, and `remaining` is read from
/// the server rather than recomputed here: the formula is
///
///     remaining = entitlement + carry_forward + adjustment - used - pending
///
/// ...and a client that reimplemented it would be a second place for it to be
/// wrong. [used] and [pending] are never collapsed into one figure — an
/// approved leave and a requested leave are exactly the difference the two
/// columns exist to prevent.
class LeaveBalance {
  const LeaveBalance({
    required this.id,
    required this.employeeId,
    required this.leaveTypeId,
    required this.year,
    required this.entitlement,
    required this.carryForward,
    required this.adjustment,
    required this.used,
    required this.pending,
    required this.remaining,
    required this.isNegative,
    this.leaveType,
    this.employeeName,
  });

  final int id;
  final int employeeId;
  final int leaveTypeId;
  final int year;

  final int entitlement;
  final int carryForward;
  final int adjustment;
  final double used;
  final double pending;
  final double remaining;

  final bool isNegative;

  /// Present only when the endpoint was asked for it with `with`, so nullable
  /// rather than a placeholder: an absent name and an unnamed type are
  /// different facts.
  final String? leaveType;

  /// Present only when the endpoint was asked for it — a manager reading a
  /// team's balances gets a name, and somebody reading their own may not.
  final String? employeeName;

  /// A whole number of days renders without a trailing `.0`; a half day does
  /// not lose the half.
  String get remainingLabel => _days(remaining);

  String get usedLabel => _days(used);

  String get pendingLabel => _days(pending);

  /// One line for a list row: what was granted, what is gone, what is left.
  String get summary =>
      '$usedLabel used · $pendingLabel pending · '
      '$remainingLabel left';

  static String _days(double value) {
    if (value == value.roundToDouble()) return value.toStringAsFixed(0);

    return value.toStringAsFixed(1);
  }

  factory LeaveBalance.fromJson(Map<String, dynamic> json) => LeaveBalance(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    leaveTypeId: _int(json['leave_type_id']) ?? 0,
    year: _int(json['year']) ?? 0,
    entitlement: _int(json['entitlement']) ?? 0,
    carryForward: _int(json['carry_forward']) ?? 0,
    adjustment: _int(json['adjustment']) ?? 0,
    used: _double(json['used']),
    pending: _double(json['pending']),
    remaining: _double(json['remaining']),
    isNegative: json['is_negative'] == true,
    leaveType: _typeName(json['leave_type']),
    employeeName: _typeName(json['employee']),
  );
}

String? _typeName(Object? raw) {
  if (raw is Map<String, dynamic>) return raw['name'] as String?;
  return null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

double _double(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0;
  return 0;
}
