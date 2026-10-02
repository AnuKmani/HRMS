import '../../../core/presentation/status_chip.dart';
import 'asset_assignment.dart';
import 'asset_type.dart';

/// One piece of company property, in the shape the app renders.
///
/// Mirrors `AssetResource`. Three things about it are worth knowing before a
/// screen draws them:
///
///  - **[status] and [currentCondition] are two different facts and are
///    never allowed to stand in for each other.** The first says where the
///    asset sits in its lifecycle (and therefore what the next legal action
///    is), the second says what condition it is physically in. An asset can
///    be `assigned` and `poor` at the same time, and it usually is.
///
///  - **[purchaseCost] is withheld, not nulled.** The field is money in
///    DECIMAL(12,2) and the server *omits* it from a payload whose reader
///    does not hold `assets.manage`, so a payload without it is visibly
///    different from one whose asset was free. [costVisible] records which
///    of the two arrived — without it, "no cost recorded" and "you are not
///    entitled to the cost" would be the same `null` on this screen.
///
///  - **[isAssignable] is a fact about the record, not about the reader.**
///    An asset in maintenance is not available to *anybody*. Whether *you*
///    may act is PermissionScope's answer, asked when you try — which is
///    also why this class carries no `can_edit`.
class Asset {
  const Asset({
    required this.id,
    required this.assetCode,
    required this.assetTypeId,
    this.assetType,
    required this.name,
    this.description,
    this.serialNumber,
    this.manufacturer,
    this.model,
    this.purchaseDate,
    this.purchaseCost,
    required this.costVisible,
    required this.currentCondition,
    required this.status,
    this.notes,
    this.currentAssignment,
    this.currentHolder,
    this.assignments,
    required this.isAssignable,
    required this.isRetired,
    this.createdAt,
    this.updatedAt,
  });

  static const statusAvailable = 'available';
  static const statusAssigned = 'assigned';
  static const statusMaintenance = 'maintenance';
  static const statusDamaged = 'damaged';
  static const statusLost = 'lost';
  static const statusRetired = 'retired';

  static const statuses = [
    statusAvailable,
    statusAssigned,
    statusMaintenance,
    statusDamaged,
    statusLost,
    statusRetired,
  ];

  static const conditionNew = 'new';
  static const conditionGood = 'good';
  static const conditionFair = 'fair';
  static const conditionPoor = 'poor';

  static const conditions = [
    conditionNew,
    conditionGood,
    conditionFair,
    conditionPoor,
  ];

  /// The server's {@see Asset::TRANSITIONS}, restated so a form can show the
  /// moves that exist rather than offering all six and letting the API
  /// reject four of them.
  ///
  /// **Only a suggestion.** The server re-derives this under a row lock, and
  /// it additionally refuses `assigned` (use the assign action) and
  /// `available` while somebody holds it (use the return action) — both of
  /// which are reasons to route a person to the right button rather than to
  /// draw a red line under the wrong one.
  static const Map<String, List<String>> transitions = {
    statusAvailable: [
      statusAssigned,
      statusMaintenance,
      statusDamaged,
      statusLost,
      statusRetired,
    ],
    statusAssigned: [statusMaintenance, statusDamaged, statusLost],
    statusMaintenance: [
      statusAvailable,
      statusDamaged,
      statusLost,
      statusRetired,
    ],
    statusDamaged: [
      statusAvailable,
      statusMaintenance,
      statusLost,
      statusRetired,
    ],
    statusLost: [statusAvailable, statusMaintenance, statusRetired],
    statusRetired: <String>[],
  };

  final int id;
  final String assetCode;
  final int assetTypeId;
  final AssetType? assetType;

  final String name;
  final String? description;
  final String? serialNumber;
  final String? manufacturer;
  final String? model;
  final String? purchaseDate;

  final String? purchaseCost;
  final bool costVisible;

  final String currentCondition;
  final String status;
  final String? notes;

  final AssetAssignment? currentAssignment;

  /// Null when the history was not fetched — which is deliberately *not*
  /// the same as "nobody has it". A payload without the history says
  /// nothing, and this class must never render "we did not ask" as "it is
  /// free".
  final String? currentHolder;

  /// The whole history when the server loaded it, `null` when it did not.
  final List<AssetAssignment>? assignments;

  final bool isAssignable;
  final bool isRetired;

  final String? createdAt;
  final String? updatedAt;

  String get typeName => assetType?.name ?? '';

  bool get isAssigned => status == statusAssigned;

  bool get isAvailable => status == statusAvailable;

  /// The holder's name, or `''`. The server ships it beside the assignment
  /// so a register can show *who* without loading each hand-over.
  String get holderName => currentHolder ?? '';

  bool get hasHolder => holderName.isNotEmpty || currentAssignment != null;

  String get statusText => switch (status) {
    statusAvailable => 'Available',
    // The word, not a tick: "Assigned" is a state the register has to be
    // able to contradict the moment the asset comes back, so it reads as a
    // fact about right now rather than as a done thing.
    statusAssigned => 'Assigned',
    statusMaintenance => 'In maintenance',
    statusDamaged => 'Damaged',
    statusLost => 'Lost',
    statusRetired => 'Retired',
    _ => status,
  };

  StatusTone get statusTone => switch (status) {
    statusAvailable => StatusTone.positive,
    statusAssigned => StatusTone.info,
    statusMaintenance => StatusTone.warning,
    statusDamaged => StatusTone.negative,
    statusLost => StatusTone.negative,
    // Terminal, so not `negative`: nothing is broken, it has been written
    // off on purpose and on paper.
    statusRetired => StatusTone.neutral,
    _ => StatusTone.neutral,
  };

  String get conditionText => switch (currentCondition) {
    conditionNew => 'New',
    conditionGood => 'Good',
    conditionFair => 'Fair',
    conditionPoor => 'Poor',
    _ => currentCondition,
  };

  StatusTone get conditionTone => switch (currentCondition) {
    conditionNew => StatusTone.positive,
    conditionGood => StatusTone.positive,
    conditionFair => StatusTone.warning,
    conditionPoor => StatusTone.negative,
    _ => StatusTone.neutral,
  };

  /// "SN-1234" / "Serial number not recorded".
  String get serialLabel {
    final serial = serialNumber;

    if (serial == null || serial.trim().isEmpty) {
      return 'Serial number not recorded';
    }

    return serial;
  }

  /// "Lenovo T14 Gen 3" when both halves exist, else whichever does.
  String get modelLabel {
    final maker = manufacturer?.trim() ?? '';
    final model = this.model?.trim() ?? '';

    if (maker.isEmpty && model.isEmpty) return '';
    if (maker.isEmpty) return model;
    if (model.isEmpty) return maker;

    return '$maker $model';
  }

  /// What may be offered as a next status right now, honouring the two
  /// moves the API refuses for a reason of its own.
  ///
  /// `assigned` is never offered: the route for it exists separately, and a
  /// status dropdown that could set it would be a second way to say
  /// somebody holds an asset when no hand-over was ever written.
  List<String> get statusTargets {
    final allowed = <String>[
      for (final target in transitions[status] ?? const <String>[])
        // Only a status the *record* permits: an open hand-over is an
        // independent fact the transition map does not know about, so it is
        // filtered separately rather than assumed.
        if (target != statusAssigned &&
            !(target == statusAvailable && isAssigned))
          target,
    ];

    return allowed;
  }

  factory Asset.fromJson(Map<String, dynamic> json) {
    final rawType = json['asset_type'];
    final rawHolder = json['current_holder'];
    final rawAssignment = json['current_assignment'];
    final rawAssignments = json['assignments'];

    return Asset(
      id: _int(json['id']) ?? 0,
      assetCode: json['asset_code'] as String? ?? '',
      assetTypeId: _int(json['asset_type_id']) ?? 0,
      assetType: rawType is Map<String, dynamic>
          ? AssetType.fromJson(rawType)
          : null,
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
      serialNumber: json['serial_number'] as String?,
      manufacturer: json['manufacturer'] as String?,
      model: json['model'] as String?,
      purchaseDate: json['purchase_date'] as String?,
      // The key's *absence* is the gate, so the key's presence is what is
      // recorded. A `null` value and a missing value would otherwise both
      // become `false`, and a manager would be shown "No cost recorded" for
      // an asset whose cost they were simply not entitled to.
      purchaseCost: json['purchase_cost']?.toString(),
      costVisible: json.containsKey('purchase_cost'),
      currentCondition: json['current_condition'] as String? ?? conditionGood,
      status: json['status'] as String? ?? statusAvailable,
      notes: json['notes'] as String?,
      currentAssignment: rawAssignment is Map<String, dynamic>
          ? AssetAssignment.fromJson(rawAssignment)
          : null,
      currentHolder: _briefName(rawHolder),
      assignments: rawAssignments is List
          ? <AssetAssignment>[
              for (final row in rawAssignments)
                if (row is Map<String, dynamic>) AssetAssignment.fromJson(row),
            ]
          : null,
      isAssignable: json['is_assignable'] == true,
      isRetired: json['is_retired'] == true,
      createdAt: json['created_at'] as String?,
      updatedAt: json['updated_at'] as String?,
    );
  }
}

String? _briefName(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final name = raw['name'] ?? raw['full_name'];
  if (name is String && name.isNotEmpty) return name;

  final code = raw['employee_code'];
  if (code is String && code.isNotEmpty) return code;

  return null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
