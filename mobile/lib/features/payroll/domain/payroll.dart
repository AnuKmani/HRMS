/// One month of one person's pay — the row `GET /payroll` returns.
///
/// Money fields are **strings** and stay strings. `PayrollResource` sends
/// `decimal:2` columns as text on purpose (see its own note), and this class
/// does not undo that: a `double` here would be re-rounded by whoever printed
/// it next, and the app and the server's PDF would stop agreeing about a
/// figure somebody is paid.
///
/// Everything a screen needs in order to *decide* what to offer is server-made
/// too: [status], [canRecalculate] and [canLock] arrive rather than being
/// derived from the status table again in Dart. Two copies of a state machine
/// is one copy too many, and the one on the client is the one that gets stale.
class Payroll {
  const Payroll({
    required this.id,
    required this.employeeId,
    this.employeeName,
    this.employeeCode,
    required this.payrollYear,
    required this.payrollMonth,
    required this.periodLabel,
    required this.basicSalary,
    required this.totalAllowances,
    required this.overtimeAmount,
    required this.overtimeMinutes,
    required this.bonusAmount,
    required this.grossSalary,
    required this.lopDays,
    required this.lopDivisor,
    required this.lopAmount,
    required this.loanDeduction,
    required this.advanceDeduction,
    required this.otherDeductions,
    required this.totalDeductions,
    required this.netSalary,
    required this.status,
    this.blockedReason,
    required this.canRecalculate,
    required this.canLock,
    required this.currency,
    this.items = const <PayrollItem>[],
    this.reviewedAt,
    this.processedAt,
    this.lockedAt,
  });

  static const statusDraft = 'draft';
  static const statusCalculated = 'calculated';
  static const statusReviewed = 'reviewed';
  static const statusProcessed = 'processed';
  static const statusLocked = 'locked';

  static const statuses = [
    statusDraft,
    statusCalculated,
    statusReviewed,
    statusProcessed,
    statusLocked,
  ];

  final int id;
  final int employeeId;
  final String? employeeName;
  final String? employeeCode;

  final int payrollYear;
  final int payrollMonth;
  final String periodLabel;

  final String basicSalary;
  final String totalAllowances;
  final String overtimeAmount;
  final int overtimeMinutes;
  final String bonusAmount;
  final String grossSalary;

  final String lopDays;
  final String lopDivisor;
  final String lopAmount;
  final String loanDeduction;
  final String advanceDeduction;
  final String otherDeductions;
  final String totalDeductions;
  final String netSalary;

  final String status;
  final String? blockedReason;
  final bool canRecalculate;
  final bool canLock;
  final String currency;

  final List<PayrollItem> items;

  final String? reviewedAt;
  final String? processedAt;
  final String? lockedAt;

  bool get isDraft => status == statusDraft;

  /// Past the point where a figure may be restated. Read straight off the
  /// server's own `can_recalculate` rather than from the status, so the two
  /// can never disagree about which button belongs on screen.
  bool get isImmutable => !canRecalculate;

  /// A row with nothing to calculate — `employees.salary` was null. Showing
  /// the reason rather than a zero is the difference between "not paid" and
  /// "no salary on record", and only the server knows which one it meant.
  bool get isBlocked => blockedReason != null;

  String get statusLabel => switch (status) {
    statusDraft => 'Draft',
    statusCalculated => 'Calculated',
    statusReviewed => 'Reviewed',
    statusProcessed => 'Processed',
    statusLocked => 'Locked',
    _ => status,
  };

  /// Which single button the flow offers next, or null when there is none.
  String? get nextAction => switch (status) {
    statusCalculated => 'review',
    statusReviewed => 'finalize',
    statusProcessed => 'lock',
    _ => null,
  };

  String? get nextActionLabel => switch (nextAction) {
    'review' => 'Mark as reviewed',
    'finalize' => 'Mark as processed',
    'lock' => 'Lock the period',
    _ => null,
  };

  factory Payroll.fromJson(Map<String, dynamic> json) => Payroll(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _name(json['employee']),
    employeeCode: _code(json['employee']),
    payrollYear: _int(json['payroll_year']) ?? 0,
    payrollMonth: _int(json['payroll_month']) ?? 0,
    periodLabel: json['period_label'] as String? ?? '',
    basicSalary: json['basic_salary'] as String? ?? '0.00',
    totalAllowances: json['total_allowances'] as String? ?? '0.00',
    overtimeAmount: json['overtime_amount'] as String? ?? '0.00',
    overtimeMinutes: _int(json['overtime_minutes']) ?? 0,
    bonusAmount: json['bonus_amount'] as String? ?? '0.00',
    grossSalary: json['gross_salary'] as String? ?? '0.00',
    lopDays: json['lop_days'] as String? ?? '0.00',
    lopDivisor: json['lop_divisor'] as String? ?? '0.00',
    lopAmount: json['lop_amount'] as String? ?? '0.00',
    loanDeduction: json['loan_deduction'] as String? ?? '0.00',
    advanceDeduction: json['advance_deduction'] as String? ?? '0.00',
    otherDeductions: json['other_deductions'] as String? ?? '0.00',
    totalDeductions: json['total_deductions'] as String? ?? '0.00',
    netSalary: json['net_salary'] as String? ?? '0.00',
    status: json['status'] as String? ?? statusDraft,
    blockedReason: json['blocked_reason'] as String?,
    canRecalculate: json['can_recalculate'] != false,
    canLock: json['can_lock'] == true,
    currency: json['currency'] as String? ?? 'INR',
    items: json['items'] is List
        ? (json['items']! as List)
              .whereType<Map<String, dynamic>>()
              .map(PayrollItem.fromJson)
              .toList()
        : const <PayrollItem>[],
    reviewedAt: json['reviewed_at'] as String?,
    processedAt: json['processed_at'] as String?,
    lockedAt: json['locked_at'] as String?,
  );
}

/// One line of the payslip.
///
/// [quantity] and [rate] exist so a line can be *checked* — `2.00 × 1,000.00`
/// against the two days of loss of pay in the leave history — rather than
/// taken on faith. [amount] stays authoritative; nothing here recomputes it.
class PayrollItem {
  const PayrollItem({
    required this.id,
    required this.type,
    required this.code,
    required this.description,
    this.quantity,
    this.rate,
    required this.amount,
  });

  static const typeEarning = 'earning';
  static const typeDeduction = 'deduction';

  final int id;
  final String type;
  final String code;
  final String description;
  final String? quantity;
  final String? rate;
  final String amount;

  bool get isEarning => type == typeEarning;

  /// `2.00 × 1,000.00`, or null when the line has no working to show.
  String? get working =>
      quantity == null || rate == null ? null : '$quantity × $rate';

  factory PayrollItem.fromJson(Map<String, dynamic> json) => PayrollItem(
    id: _int(json['id']) ?? 0,
    type: json['type'] as String? ?? typeEarning,
    code: json['code'] as String? ?? '',
    description: json['description'] as String? ?? '',
    quantity: json['quantity'] as String?,
    rate: json['rate'] as String?,
    amount: json['amount'] as String? ?? '0.00',
  );
}

/// Company totals for a period — deliberately no employee-level rows.
///
/// The shape of this class is the guarantee behind `payroll.summary.view`: if
/// a field can be read as "and who earned it?", it does not belong here.
class PayrollSummary {
  const PayrollSummary({
    required this.year,
    this.month,
    required this.employeeCount,
    required this.grossPayroll,
    required this.totalDeductions,
    required this.netPayroll,
    required this.currency,
  });

  final int year;
  final int? month;
  final int employeeCount;
  final String grossPayroll;
  final String totalDeductions;
  final String netPayroll;
  final String currency;

  factory PayrollSummary.fromJson(Map<String, dynamic> json) => PayrollSummary(
    year: _int(json['year']) ?? 0,
    month: _int(json['month']),
    employeeCount: _int(json['employee_count']) ?? 0,
    grossPayroll: json['gross_payroll'] as String? ?? '0.00',
    totalDeductions: json['total_deductions'] as String? ?? '0.00',
    netPayroll: json['net_payroll'] as String? ?? '0.00',
    currency: json['currency'] as String? ?? 'INR',
  );
}

/// What a run reported — counts, never figures. Twenty thousand numbers in a
/// response body would be unusable and an accidental bulk leak of every
/// salary the caller can reach.
class PayrollRunReport {
  const PayrollRunReport({
    required this.year,
    required this.month,
    required this.calculated,
    required this.updated,
    required this.created,
    required this.skipped,
    required this.draft,
  });

  final int year;
  final int month;
  final int calculated;
  final int updated;
  final int created;
  final int skipped;
  final int draft;

  factory PayrollRunReport.fromJson(Map<String, dynamic> json) =>
      PayrollRunReport(
        year: _int(json['year']) ?? 0,
        month: _int(json['month']) ?? 0,
        calculated: _int(json['calculated']) ?? 0,
        updated: _int(json['updated']) ?? 0,
        created: _int(json['created']) ?? 0,
        skipped: _int(json['skipped']) ?? 0,
        draft: _int(json['draft']) ?? 0,
      );
}

String? _name(Object? raw) =>
    raw is Map<String, dynamic> && raw['full_name'] is String
    ? raw['full_name'] as String
    : null;

String? _code(Object? raw) =>
    raw is Map<String, dynamic> && raw['employee_code'] is String
    ? raw['employee_code'] as String
    : null;

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
