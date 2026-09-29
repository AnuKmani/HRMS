/// One loan or salary advance, with its repayment schedule when the server
/// sent one.
///
/// Money stays a decimal **string** here for the same reason it does in
/// `PayrollResource`: DECIMAL columns travel as text so that the one place
/// a figure is rounded is the place that settled it. The balance, the
/// schedule and the status are all present because they answer three
/// different questions a borrower actually asks — "how much do I still
/// owe?", "when is the next one taken?" and "am I still repaying?" — and
/// none of them can be derived from the others on the client without
/// re-implementing `LoanService`.
class Loan {
  const Loan({
    required this.id,
    required this.employeeId,
    this.employeeName,
    this.employeeCode,
    required this.loanType,
    required this.typeLabel,
    this.reference,
    this.currency = 'INR',
    required this.principalAmount,
    required this.installmentAmount,
    required this.numberOfInstallments,
    required this.outstandingBalance,
    required this.repaidAmount,
    this.startDate,
    required this.status,
    this.nextInstallment,
    this.remarks,
    this.installments = const <LoanInstallment>[],
    this.approvedAt,
    this.rejectedAt,
    this.cancelledAt,
    this.createdAt,
  });

  static const statusDraft = 'draft';
  static const statusPending = 'pending';
  static const statusApproved = 'approved';
  static const statusActive = 'active';
  static const statusCompleted = 'completed';
  static const statusRejected = 'rejected';
  static const statusCancelled = 'cancelled';

  static const statuses = [
    statusDraft,
    statusPending,
    statusApproved,
    statusActive,
    statusCompleted,
    statusRejected,
    statusCancelled,
  ];

  static const typeLoan = 'loan';
  static const typeSalaryAdvance = 'salary_advance';

  final int id;
  final int employeeId;
  final String? employeeName;
  final String? employeeCode;

  final String loanType;
  final String typeLabel;
  final String? reference;

  /// The organisation's `system.currency` as the server read it when this
  /// row was serialised — sent with the figures so that a screen never has
  /// to guess the code, and never prints a stale one after a setting change.
  final String currency;

  final String principalAmount;
  final String installmentAmount;
  final int numberOfInstallments;
  final String outstandingBalance;
  final String repaidAmount;

  final String? startDate;
  final String status;
  final LoanInstallment? nextInstallment;
  final String? remarks;
  final List<LoanInstallment> installments;

  final String? approvedAt;
  final String? rejectedAt;
  final String? cancelledAt;
  final String? createdAt;

  bool get isDraft => status == statusDraft;

  /// Editable means "nobody has seen it yet". `pending` is deliberately not
  /// here: a borrower keeping a request editable while an approver is
  /// reading it is how "what was asked" and "what was decided" come to
  /// disagree, and the server refuses it with a 409 for exactly that
  /// reason.
  bool get isEditable => status == statusDraft;

  /// Withdrawable while nothing has been decided — draft or awaiting a
  /// decision. The service, not this flag, has the final word.
  bool get isWithdrawable => status == statusDraft || status == statusPending;

  bool get isRepaying => status == statusApproved || status == statusActive;

  /// May this session press Submit? Both halves of the question, and the
  /// permission is the client's half of *hiding* a button the API would
  /// refuse anyway.
  bool get canSubmit => status == statusDraft;

  /// May it be answered? The server still asks whether the caller is the
  /// borrower — nobody signs off on their own debt — so this is only ever
  /// the coarse "does the decision button exist at all".
  bool get canBeAnswered => status == statusPending;

  String get statusLabel => switch (status) {
    statusDraft => 'Draft',
    statusPending => 'Awaiting approval',
    statusApproved => 'Approved',
    statusActive => 'Repaying',
    statusCompleted => 'Completed',
    statusRejected => 'Rejected',
    statusCancelled => 'Cancelled',
    _ => status,
  };

  /// How far repayment has actually got, as a fraction of the principal.
  ///
  /// Derived from the two balances rather than counted from installments,
  /// so a skipped or adjusted payment moves it honestly instead of leaving
  /// the progress bar implying a payment that never happened.
  double get progress {
    final principal = double.tryParse(principalAmount);
    final outstanding = double.tryParse(outstandingBalance);

    if (principal == null || outstanding == null || principal <= 0) return 0;

    final paid = principal - outstanding;
    if (paid <= 0) return 0;

    return (paid / principal).clamp(0.0, 1.0);
  }

  factory Loan.fromJson(Map<String, dynamic> json) => Loan(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _nestedName(json['employee']),
    employeeCode: _nestedCode(json['employee']),
    loanType: json['loan_type'] as String? ?? typeLoan,
    typeLabel: json['type_label'] as String? ?? 'Loan',
    reference: json['reference'] as String?,
    currency: json['currency'] as String? ?? 'INR',
    principalAmount: json['principal_amount'] as String? ?? '0.00',
    installmentAmount: json['installment_amount'] as String? ?? '0.00',
    numberOfInstallments: _int(json['number_of_installments']) ?? 0,
    outstandingBalance: json['outstanding_balance'] as String? ?? '0.00',
    repaidAmount: json['repaid_amount'] as String? ?? '0.00',
    startDate: json['start_date'] as String?,
    status: json['status'] as String? ?? statusDraft,
    nextInstallment: json['next_installment'] is Map<String, dynamic>
        ? LoanInstallment.fromJson(
            json['next_installment']! as Map<String, dynamic>,
            partial: true,
          )
        : null,
    remarks: json['remarks'] as String?,
    installments: json['installments'] is List
        ? (json['installments']! as List)
              .whereType<Map<String, dynamic>>()
              .map((raw) => LoanInstallment.fromJson(raw))
              .toList()
        : const <LoanInstallment>[],
    approvedAt: json['approved_at'] as String?,
    rejectedAt: json['rejected_at'] as String?,
    cancelledAt: json['cancelled_at'] as String?,
    createdAt: json['created_at'] as String?,
  );
}

/// One payment in the schedule, with what has actually been taken of it.
///
/// Four figures, kept apart on purpose because a screen answers a different
/// question with each of them:
///
/// * [amount] — what the schedule says is due (the client never recomputes it)
/// * [deductedAmount] — what pay runs have taken of it so far
/// * [remainingAmount] — what is still owed on *this* payment
/// * `Loan.outstandingBalance` — what is left on the loan as a whole
///
/// [payrollId] is the run that took money from it most recently. With a
/// partial deduction the full history lives on the server's `payroll_items`;
/// this is the pointer that says "somebody has already touched this row",
/// which is what keeps a `deducted` status from being read as a guess.
class LoanInstallment {
  const LoanInstallment({
    required this.sequence,
    this.dueDate,
    required this.amount,
    this.deductedAmount = '0.00',
    required this.remainingAmount,
    required this.status,
    this.payrollId,
    this.deductedAt,
    this.skippedReason,
  });

  static const statusPending = 'pending';
  static const statusPartiallyDeducted = 'partially_deducted';
  static const statusDeducted = 'deducted';
  static const statusSkipped = 'skipped';
  static const statusAdjusted = 'adjusted';

  final int sequence;
  final String? dueDate;
  final String amount;
  final String deductedAmount;
  final String remainingAmount;
  final String status;
  final int? payrollId;
  final String? deductedAt;

  /// Skipped and adjusted payments record why, so a borrower reading the
  /// schedule can tell "the run was short" from "nobody took it".
  final String? skippedReason;

  /// Some of it was taken and some was not: the rest is still owed and is
  /// offered to the next payroll run rather than written off.
  bool get isPartial => status == statusPartiallyDeducted;

  String get statusLabel => switch (status) {
    statusPending => 'Due',
    statusPartiallyDeducted => 'Partly deducted',
    statusDeducted => 'Deducted',
    statusSkipped => 'Skipped',
    statusAdjusted => 'Adjusted',
    _ => status,
  };

  factory LoanInstallment.fromJson(
    Map<String, dynamic> json, {
    bool partial = false,
  }) {
    final amount = json['amount'] as String? ?? '0.00';

    return LoanInstallment(
      sequence: _int(json['sequence']) ?? 0,
      dueDate: json['due_date'] as String?,
      amount: amount,
      deductedAmount: json['deducted_amount'] as String? ?? '0.00',
      // Falling back to the scheduled figure keeps an older payload (or a
      // fixture that never says) meaning "nothing has been taken of it
      // yet", which is what `pending` already claims.
      remainingAmount: json['remaining_amount'] as String? ?? amount,
      status: json['status'] as String? ?? statusPending,
      payrollId: partial ? null : _int(json['payroll_id']),
      deductedAt: partial ? null : json['deducted_at'] as String?,
      skippedReason: partial ? null : json['skipped_reason'] as String?,
    );
  }
}

String? _nestedName(Object? raw) =>
    raw is Map<String, dynamic> && raw['full_name'] is String
    ? raw['full_name'] as String
    : null;

String? _nestedCode(Object? raw) =>
    raw is Map<String, dynamic> && raw['employee_code'] is String
    ? raw['employee_code'] as String
    : null;

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
