/// One expense category, and the two rules a claim has to satisfy.
///
/// Matches `ExpenseCategoryResource`. Categories are a table rather than a
/// switch statement in the app too: the form reads [requiresReceipt] and
/// [maximumAmount] from the row it was given, so a screen can warn *before*
/// submit about the paper a category demands — and the API re-reads the same
/// two values on create, update and submit, so what the screen believed never
/// decides what the server accepts.
///
/// Null [maximumAmount] means "no ceiling", which is not the same as zero:
/// a category with `0.00` would refuse every claim, and none does.
class ExpenseCategory {
  const ExpenseCategory({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    required this.status,
    required this.requiresReceipt,
    this.maximumAmount,
  });

  final int id;
  final String name;
  final String code;
  final String? description;
  final String status;
  final bool requiresReceipt;
  final String? maximumAmount;

  bool get isActive => status == 'active';

  /// "Needs a receipt" / "Ceiling INR 1,000.00" — the sentence the form
  /// shows under the picker so the rules are known while filling the claim
  /// rather than discovered at submit.
  String get ruleSummary {
    final rules = <String>[
      if (requiresReceipt) 'Needs a receipt',
      if (maximumAmount != null) 'Up to $maximumAmount',
    ];

    return rules.isEmpty ? '' : rules.join(' · ');
  }

  factory ExpenseCategory.fromJson(Map<String, dynamic> json) =>
      ExpenseCategory(
        id: _int(json['id']) ?? 0,
        name: json['name'] as String? ?? '',
        code: json['code'] as String? ?? '',
        description: json['description'] as String?,
        status: json['status'] as String? ?? 'inactive',
        requiresReceipt: json['requires_receipt'] == true,
        maximumAmount: json['maximum_amount'] as String?,
      );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
