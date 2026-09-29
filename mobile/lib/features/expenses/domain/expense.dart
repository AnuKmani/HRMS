import '../../../core/data/approval_step.dart';
import 'expense_category.dart';
import 'expense_receipt.dart';

/// One expense claim.
///
/// Matches `ExpenseResource`. Three pairs in here are never allowed to stand
/// in for each other:
///
///  - [status] and [isDraft] — the state, and the *one* state the claim can
///    still be edited in. Derived from the server's own `is_draft` where it
///    sends it, and from `status` otherwise, so a client that disagreed with
///    the server about what a draft is would be wrong in a way nobody could
///    debug.
///
///  - [amount] and [maximumAmount] — what was claimed, and what the category
///    allows. The ceiling is re-read by the API on create, update *and*
///    submit; showing it here is a courtesy so a person is not surprised,
///    never a substitute for that check.
///
///  - [status] and the approval chain — a workflow outcome, and the sequence
///    that produced it. The chain arrives only from `show`, because a list of
///    fifty rows each pulling a sequence of approval records is the textbook
///    N+1 and a list screen has nowhere to put them anyway.
///
/// Money is a decimal **string**, never a Dart `double`: `amount` is
/// DECIMAL(12,2) on the server and `Money::decimal()` in the payload, and
/// parsing it into a double to print it would re-round a figure the server
/// had already settled. `core/presentation/money.dart` is the only place that
/// turns it into text.
class Expense {
  const Expense({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.expenseCategoryId,
    this.category,
    this.projectId,
    this.projectName,
    this.siteId,
    this.siteName,
    required this.expenseDate,
    required this.amount,
    required this.currency,
    required this.description,
    required this.status,
    this.summary,
    this.requiresReceipt,
    this.maximumAmount,
    this.receiptCount,
    this.receipts,
    this.currentApprovalStep,
    this.approvalChain,
    this.submittedAt,
    this.approvedAt,
    this.rejectedAt,
    this.cancelledAt,
  });

  static const statusDraft = 'draft';
  static const statusPending = 'pending';
  static const statusApproved = 'approved';
  static const statusRejected = 'rejected';
  static const statusCancelled = 'cancelled';

  static const statuses = [
    statusDraft,
    statusPending,
    statusApproved,
    statusRejected,
    statusCancelled,
  ];

  final int id;
  final int employeeId;
  final String? employeeName;

  final int expenseCategoryId;
  final ExpenseCategory? category;

  final int? projectId;
  final String? projectName;
  final int? siteId;
  final String? siteName;

  final String expenseDate;

  /// Decimal string, two places. Not a double — see the class docblock.
  final String amount;
  final String currency;
  final String description;

  final String status;

  /// The claim's own one-line "what is this" as the server rendered it.
  final String? summary;

  final bool? requiresReceipt;
  final String? maximumAmount;

  final int? receiptCount;
  final List<ExpenseReceipt>? receipts;

  final int? currentApprovalStep;
  final List<ApprovalStep>? approvalChain;

  final String? submittedAt;
  final String? approvedAt;
  final String? rejectedAt;
  final String? cancelledAt;

  bool get isDraft => status == statusDraft;

  bool get isPending => status == statusPending;

  bool get isApproved => status == statusApproved;

  /// Still on the employee's side of the wire — the only state the form may
  /// be re-opened in, and the only state receipts may be added to or removed
  /// from.
  bool get isEditable => isDraft;

  /// The only state in which an approval endpoint could succeed.
  bool get isAwaitingDecision => isPending;

  /// Still answerable — the draft and the claim in the chain both may be
  /// withdrawn, a settled one may not.
  bool get isOpen => isDraft || isPending;

  bool get hasDecision =>
      approvedAt != null || rejectedAt != null || cancelledAt != null;

  String get statusLabel => switch (status) {
    statusDraft => 'Draft',
    statusPending => 'Awaiting approval',
    statusApproved => 'Approved',
    statusRejected => 'Rejected',
    statusCancelled => 'Cancelled',
    _ => status,
  };

  String get categoryName => category?.name ?? '';

  /// Whether evidence has to accompany this claim before it can be
  /// submitted, read from the claim's own category rules.
  bool get needsReceipt =>
      requiresReceipt ?? category?.requiresReceipt ?? false;

  /// "2 receipts" / "1 receipt" / "No receipts yet".
  String get receiptLabel {
    final count = receiptCount ?? receipts?.length ?? 0;

    if (count == 0) return 'No receipts yet';
    if (count == 1) return '1 receipt';
    return '$count receipts';
  }

  factory Expense.fromJson(Map<String, dynamic> json) => Expense(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _nestedName(json['employee']),
    expenseCategoryId: _int(json['expense_category_id']) ?? 0,
    category: json['category'] is Map<String, dynamic>
        ? ExpenseCategory.fromJson(json['category'] as Map<String, dynamic>)
        : null,
    projectId: _int(json['project_id']),
    projectName: _nestedName(json['project']),
    siteId: _int(json['site_id']),
    siteName: _nestedName(json['site']),
    expenseDate: json['expense_date'] as String? ?? '',
    amount: json['amount'] as String? ?? '0.00',
    currency: json['currency'] as String? ?? 'INR',
    description: json['description'] as String? ?? '',
    status: json['status'] as String? ?? statusDraft,
    summary: json['summary'] as String?,
    requiresReceipt: json['requires_receipt'] as bool?,
    maximumAmount: json['maximum_amount'] as String?,
    receiptCount: _int(json['receipt_count']),
    receipts: json['receipts'] is List
        ? (json['receipts']! as List)
              .whereType<Map<String, dynamic>>()
              .map(ExpenseReceipt.fromJson)
              .toList()
        : null,
    currentApprovalStep: _int(json['current_approval_step']),
    approvalChain: json['approval_chain'] is List
        ? (json['approval_chain']! as List)
              .whereType<Map<String, dynamic>>()
              .map(ApprovalStep.fromJson)
              .toList()
        : null,
    submittedAt: json['submitted_at'] as String?,
    approvedAt: json['approved_at'] as String?,
    rejectedAt: json['rejected_at'] as String?,
    cancelledAt: json['cancelled_at'] as String?,
  );
}

/// A nested `name`, with `full_name` as the employee resource spells it.
///
/// One helper rather than three: an employee brief, a project and a site all
/// arrive as an object, and reading `name` off two of them while missing the
/// third's `full_name` is how a detail screen ends up blank for one record
/// type only.
String? _nestedName(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final name = raw['name'] ?? raw['full_name'];
  if (name is String && name.isNotEmpty) return name;

  return null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
