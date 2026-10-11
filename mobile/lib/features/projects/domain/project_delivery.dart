/// What a project is made of once it is running: stages and progress,
/// subcontractors, the site team, payment milestones and documents.
library;

double _num(Object? v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
double? _numOrNull(Object? v) =>
    v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'));
int _int(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;
String? _str(Object? v) => v is String && v.trim().isNotEmpty ? v : null;

List<T> listOf<T>(Object? value, T Function(Map<String, dynamic>) parse) => [
  if (value is List)
    for (final row in value)
      if (row is Map<String, dynamic>) parse(row),
];

/// `1250000` → `AED 1,250,000`.
String money(num? value) {
  if (value == null) return '—';
  final whole = value.round().toString();
  final grouped = whole.replaceAllMapped(
    RegExp(r'(\d)(?=(\d{3})+$)'),
    (m) => '${m[1]},',
  );
  return 'AED $grouped';
}

/// `12.5` → `12.5%`, `40.0` → `40%`.
String pct(num? value) {
  if (value == null) return '—';
  final fixed = value.toStringAsFixed(1);
  return '${fixed.endsWith('.0') ? fixed.substring(0, fixed.length - 2) : fixed}%';
}

class ProjectStage {
  const ProjectStage({
    required this.id,
    required this.name,
    required this.weight,
    required this.percentComplete,
    this.contractValue,
    this.billedAmount,
  });

  final int id;
  final String name;
  final double weight;
  final double percentComplete;

  /// The system's value and what has been billed — only for those who may
  /// see money (Project Manager and above, Sales).
  final double? contractValue;
  final double? billedAmount;

  double? get billedPercent => contractValue != null && contractValue! > 0
      ? (billedAmount ?? 0) / contractValue! * 100
      : null;

  factory ProjectStage.fromJson(Map<String, dynamic> j) => ProjectStage(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    weight: _num(j['weight']),
    percentComplete: _num(j['percent_complete']),
    contractValue: _numOrNull(j['contract_value']),
    billedAmount: _numOrNull(j['billed_amount']),
  );
}

/// The standard stage list a new project starts with.
const stageTemplate = <(String, double)>[
  ('Mobilisation', 5),
  ('Foundation', 15),
  ('Structure', 35),
  ('MEP', 20),
  ('Finishing', 20),
  ('Testing & handover', 5),
];

class Subcontractor {
  const Subcontractor({
    required this.id,
    required this.name,
    this.trade,
    this.contactName,
    this.contactPhone,
    this.contractValue,
    this.billedAmount,
    this.projectStageId,
    this.startDate,
    this.endDate,
    this.hasDocument = false,
    this.documentName,
    required this.status,
    this.removedOn,
    this.removedReason,
  });

  final int id;
  final String name;
  final String? trade;
  final String? contactName;
  final String? contactPhone;
  final double? contractValue;
  final double? billedAmount;

  /// The system this subcontractor installs.
  final int? projectStageId;
  final String? startDate;
  final String? endDate;
  final bool hasDocument;
  final String? documentName;
  final String status;
  final String? removedOn;
  final String? removedReason;

  bool get isRemoved => status == 'removed';

  factory Subcontractor.fromJson(Map<String, dynamic> j) => Subcontractor(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    trade: _str(j['trade']),
    contactName: _str(j['contact_name']),
    contactPhone: _str(j['contact_phone']),
    contractValue: _numOrNull(j['contract_value']),
    billedAmount: _numOrNull(j['billed_amount']),
    projectStageId: j['project_stage_id'] is num
        ? (j['project_stage_id'] as num).toInt()
        : null,
    startDate: _str(j['start_date']),
    endDate: _str(j['end_date']),
    hasDocument: j['has_document'] == true,
    documentName: _str(j['document_name']),
    status: j['status'] as String? ?? 'active',
    removedOn: _str(j['removed_on']),
    removedReason: _str(j['removed_reason']),
  );
}

class TeamMember {
  const TeamMember({
    required this.employeeId,
    required this.name,
    required this.role,
  });

  final int employeeId;
  final String name;

  /// `site_engineer` or `site_supervisor`.
  final String role;

  String get roleLabel => switch (role) {
    'site_supervisor' => 'Site supervisor',
    'coordinator' => 'Project coordinator',
    _ => 'Site engineer',
  };

  factory TeamMember.fromJson(Map<String, dynamic> j) => TeamMember(
    employeeId: _int(j['employee_id']),
    name: j['name'] as String? ?? '',
    role: j['role'] as String? ?? 'site_engineer',
  );
}

/// Someone who may be put on a project team.
class TeamCandidate {
  const TeamCandidate({required this.id, required this.name, this.role});

  final int id;
  final String name;
  final String? role;

  factory TeamCandidate.fromJson(Map<String, dynamic> j) => TeamCandidate(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    role: _str(j['role']),
  );
}

class PaymentMilestone {
  const PaymentMilestone({
    required this.id,
    required this.name,
    required this.triggerPercent,
    this.amountPercent,
    this.amount,
    required this.status,
    this.notes,
  });

  final int id;
  final String name;
  final double triggerPercent;
  final double? amountPercent;
  final double? amount;

  /// `pending`, `reached`, `requested`, `paid`.
  final String status;
  final String? notes;

  String get statusLabel => switch (status) {
    'reached' => 'Reached — request payment',
    'requested' => 'Payment requested',
    'paid' => 'Paid',
    _ => 'Not reached yet',
  };

  factory PaymentMilestone.fromJson(Map<String, dynamic> j) => PaymentMilestone(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    triggerPercent: _num(j['trigger_percent']),
    amountPercent: _numOrNull(j['amount_percent']),
    amount: _numOrNull(j['amount']),
    status: j['status'] as String? ?? 'pending',
    notes: _str(j['notes']),
  );
}

class ProjectDocumentFile {
  const ProjectDocumentFile({
    required this.id,
    required this.title,
    required this.category,
    this.originalName,
    this.size = 0,
    this.uploadedAt,
  });

  final int id;
  final String title;
  final String category;
  final String? originalName;
  final int size;
  final String? uploadedAt;

  static const categories = <(String, String)>[
    ('drawing', 'Drawing'),
    ('contract', 'Contract'),
    ('permit', 'Permit'),
    ('other', 'Other'),
  ];

  String get categoryLabel {
    for (final (v, l) in categories) {
      if (v == category) return l;
    }
    return category;
  }

  factory ProjectDocumentFile.fromJson(Map<String, dynamic> j) =>
      ProjectDocumentFile(
        id: _int(j['id']),
        title: j['title'] as String? ?? '',
        category: j['category'] as String? ?? 'other',
        originalName: _str(j['original_name']),
        size: _int(j['size']),
        uploadedAt: _str(j['uploaded_at']),
      );
}

class ProgressUpdate {
  const ProgressUpdate({
    this.stage,
    required this.stagePercent,
    required this.projectPercent,
    required this.source,
    this.by,
    this.note,
    this.at,
  });

  final String? stage;
  final double stagePercent;
  final double projectPercent;

  /// `daily_report` or `manual`.
  final String source;
  final String? by;
  final String? note;
  final String? at;

  String get sourceLabel =>
      source == 'manual' ? 'Corrected by PM' : 'Daily report';

  factory ProgressUpdate.fromJson(Map<String, dynamic> j) => ProgressUpdate(
    stage: _str(j['stage']),
    stagePercent: _num(j['stage_percent']),
    projectPercent: _num(j['project_percent']),
    source: j['source'] as String? ?? 'daily_report',
    by: _str(j['by']),
    note: _str(j['note']),
    at: _str(j['at']),
  );
}

/// A project as Sales sees it: progress only.
class ProjectProgress {
  const ProjectProgress({
    required this.id,
    required this.name,
    required this.code,
    required this.status,
    this.startDate,
    this.endDate,
    required this.progressPercent,
    this.expectedPercent,
    this.behindSchedule = false,
    this.milestonesDue = 0,
    this.stages = const [],
    this.milestones = const [],
  });

  final int id;
  final String name;
  final String code;
  final String status;
  final String? startDate;
  final String? endDate;
  final double progressPercent;
  final double? expectedPercent;
  final bool behindSchedule;
  final int milestonesDue;
  final List<ProjectStage> stages;
  final List<PaymentMilestone> milestones;

  factory ProjectProgress.fromJson(Map<String, dynamic> j) => ProjectProgress(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    code: j['code'] as String? ?? '',
    status: j['status'] as String? ?? '',
    startDate: _str(j['start_date']),
    endDate: _str(j['end_date']),
    progressPercent: _num(j['progress_percent']),
    expectedPercent: _numOrNull(j['expected_percent']),
    behindSchedule: j['behind_schedule'] == true,
    milestonesDue: _int(j['milestones_due']),
    stages: listOf(j['stages'], ProjectStage.fromJson),
    milestones: listOf(j['payment_milestones'], PaymentMilestone.fromJson),
  );
}
