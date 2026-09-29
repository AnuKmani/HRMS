import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/data/device_camera.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';
import 'package:mobile/features/expenses/data/api_expense_repository.dart';
import 'package:mobile/features/expenses/domain/expense.dart';
import 'package:mobile/features/expenses/domain/expense_category.dart';
import 'package:mobile/features/expenses/domain/expense_receipt.dart';
import 'package:mobile/features/expenses/domain/expense_repository.dart';

import 'attendance.dart';
import 'fakes.dart';
import 'phase4.dart';
import 'site_reports.dart' show pngBytes;

export 'phase4.dart'
    show advance, useTallScreen, forbidden403, notFound404, unreachable;

/// The expense repository, scripted.
///
/// The seven lifecycle verbs are recorded rather than modelled: a screen test
/// asks "did pressing Approve call `approve` with this id, and with whose
/// remarks?", and a double that actually walked `draft → pending → approved`
/// would be re-testing `ExpenseService` in Dart.
///
/// Receipts are the exception, because receipt *state* is what two of the
/// Phase 9 tests are about: [addReceipts] appends a row and [removeReceipt]
/// takes one away, so a detail screen can be shown with and without evidence
/// without a server in the way.
class ScriptedExpenses extends Scripted<Expense> implements ExpenseRepository {
  ScriptedExpenses({super.items, required super.idOf, super.fallback});

  String? lastTransition;
  int? lastTransitionId;
  String? lastRemarks;

  /// The body `create` was handed, kept apart from [Scripted]'s own
  /// [Scripted.lastBody] because this form's body is what the test is about:
  /// "did it send the category and the amount, and never `employee_id`?"
  Map<String, Object?>? lastCreated;

  Object? actionError;

  /// The whole category vocabulary, which `GET /expense-categories` returns
  /// in one response rather than as a page.
  List<ExpenseCategory> categoryRows = <ExpenseCategory>[];

  int receiptCalls = 0;
  int? lastReceiptExpenseId;
  int? lastReceiptCount;
  int? lastRemovedReceiptId;
  int? lastReadReceiptId;

  /// What reading a receipt's bytes resolves to — a real 1×1 PNG, so the
  /// dialog's `Image.memory` decodes instead of reporting "Invalid image
  /// data" for a reason that has nothing to do with the flow under test.
  Uint8List receiptReply = pngBytes;

  Future<Expense> _transition(String name, int id, {String? remarks}) async {
    lastTransition = name;
    lastTransitionId = id;
    lastRemarks = remarks;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return find(id);
  }

  @override
  Future<Expense> submit(int id, {String remarks = ''}) =>
      _transition('submit', id, remarks: remarks.isEmpty ? null : remarks);

  @override
  Future<Expense> approve(int id, {String remarks = ''}) =>
      _transition('approve', id, remarks: remarks.isEmpty ? null : remarks);

  @override
  Future<Expense> reject(int id, {required String remarks}) =>
      _transition('reject', id, remarks: remarks);

  @override
  Future<Expense> cancel(int id, {String remarks = ''}) =>
      _transition('cancel', id, remarks: remarks.isEmpty ? null : remarks);

  @override
  Future<Expense> create(Map<String, Object?> body) async {
    lastCreated = body;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.create(body);
  }

  @override
  Future<Expense> update(int id, Map<String, Object?> body) async {
    lastCreated = body;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.update(id, body);
  }

  @override
  Future<List<ExpenseCategory>> categories({
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return categoryRows;
  }

  @override
  Future<Expense> addReceipts(
    int id,
    List<Uint8List> files, {
    String filename = 'receipt',
  }) async {
    receiptCalls++;
    lastReceiptExpenseId = id;
    lastReceiptCount = files.length;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    // The count follows the upload, because the assertion a receipt test
    // makes is about what the screen can then say: "2 receipts", not "the
    // repository was called".
    final index = items.indexWhere((item) => idOf(item) == id);

    if (index >= 0) {
      final current = items[index];
      final added = <ExpenseReceipt>[
        for (var frame = 0; frame < files.length; frame++)
          ExpenseReceipt(
            id: _nextReceiptId++,
            expenseId: id,
            originalName: '$filename-${frame + 1}.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 4096,
            isImage: true,
            isPdf: false,
          ),
      ];

      items[index] = _withReceipts(current, <ExpenseReceipt>[
        ...current.receipts ?? const <ExpenseReceipt>[],
        ...added,
      ]);
    }

    return find(id);
  }

  @override
  Future<Expense> removeReceipt(int id, int receiptId) async {
    lastRemovedReceiptId = receiptId;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    final claim = await find(id);
    final kept = <ExpenseReceipt>[
      for (final receipt in claim.receipts ?? const <ExpenseReceipt>[])
        if (receipt.id != receiptId) receipt,
    ];

    items[items.indexOf(claim)] = _withReceipts(claim, kept);

    return find(id);
  }

  @override
  Future<Uint8List> receipt(int expenseId, int receiptId) async {
    lastReadReceiptId = receiptId;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return receiptReply;
  }

  /// Rebuilds a claim with a different receipt set — the only field this
  /// double is allowed to change, since everything else in a transition
  /// belongs to the service under test.
  static Expense _withReceipts(Expense claim, List<ExpenseReceipt> receipts) =>
      Expense(
        id: claim.id,
        employeeId: claim.employeeId,
        employeeName: claim.employeeName,
        expenseCategoryId: claim.expenseCategoryId,
        category: claim.category,
        projectId: claim.projectId,
        projectName: claim.projectName,
        siteId: claim.siteId,
        siteName: claim.siteName,
        expenseDate: claim.expenseDate,
        amount: claim.amount,
        currency: claim.currency,
        description: claim.description,
        status: claim.status,
        summary: claim.summary,
        requiresReceipt: claim.requiresReceipt,
        maximumAmount: claim.maximumAmount,
        receiptCount: receipts.length,
        receipts: receipts,
        currentApprovalStep: claim.currentApprovalStep,
        approvalChain: claim.approvalChain,
        submittedAt: claim.submittedAt,
        approvedAt: claim.approvedAt,
        rejectedAt: claim.rejectedAt,
        cancelledAt: claim.cancelledAt,
      );

  /// Ids minted for receipts this double invents, so two uploads never
  /// collide and a "remove this one" test can name its row.
  static int _nextReceiptId = 1001;
}

/// Wraps a screen in the providers Phase 9 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repository.
Widget scopedPhase9({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedExpenses? expenses,
  ScriptedCamera? camera,
  PdfOpener? pdfOpener,
}) => ProviderScope(
  overrides: [
    permissionScopeProvider.overrideWithValue(
      PermissionScope(buildUser(permissions: permissions, roles: roles)),
    ),
    if (expenses != null) expenseRepositoryProvider.overrideWithValue(expenses),
    // Always overridden, for the same reason Phase 7 always overrides it:
    // the receipt sheet opens the *real* camera provider otherwise, and a
    // widget test would then wait on a platform channel nobody answers.
    documentCameraProvider.overrideWithValue(camera ?? ScriptedCamera()),
    if (pdfOpener != null) pdfOpenerProvider.overrideWithValue(pdfOpener),
  ],
  child: child,
);

/// A router with enough of the app's real path table for a Phase 9 screen to
/// navigate the way it does in production.
GoRouter phase9Router(String initialLocation, List<GoRoute> routes) =>
    GoRouter(initialLocation: initialLocation, routes: routes);

/* ------------------------------------------------------------- fixtures */

/// One receipt, as `ExpenseReceiptResource` shapes it.
ExpenseReceipt expenseReceipt({
  required int id,
  int expenseId = 1,
  String name = 'IMG_0142.jpg',
  String mime = 'image/jpeg',
  int bytes = 245760,
  bool image = true,
}) => ExpenseReceipt(
  id: id,
  expenseId: expenseId,
  originalName: name,
  mimeType: mime,
  sizeBytes: bytes,
  isImage: image,
  isPdf: !image,
);

/// One category, as `ExpenseCategoryResource` shapes it.
ExpenseCategory expenseCategory({
  required int id,
  String name = 'Travel',
  String code = 'TRAVEL',
  bool requiresReceipt = true,
  String? maximumAmount,
  String status = 'active',
}) => ExpenseCategory(
  id: id,
  name: name,
  code: code,
  description: null,
  status: status,
  requiresReceipt: requiresReceipt,
  maximumAmount: maximumAmount,
);

/// An expense claim, as `ExpenseResource` sends one.
///
/// A builder rather than a constant because the state a claim sits in is
/// exactly the variable under test — draft, waiting, refused, settled.
Expense expenseRow({
  required int id,
  String name = 'Anu Kmani',
  String status = Expense.statusDraft,
  String date = '2026-09-28',
  String amount = '250.00',
  String currency = 'INR',
  int categoryId = 1,
  String category = 'Travel',
  bool requiresReceipt = false,
  String? maximumAmount,
  String description = 'Taxi from the airport to the site office',
  int? siteId,
  String? siteName,
  int? projectId,
  String? projectName,
  int receiptCount = 0,
  List<ExpenseReceipt> receipts = const <ExpenseReceipt>[],
  bool chain = false,
  int? currentStep,
  String? submittedAt,
  String? approvedAt,
  String? rejectedAt,
  String? cancelledAt,
}) => Expense.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': id,
  'employee': <String, dynamic>{'full_name': name},
  'expense_category_id': categoryId,
  'category': <String, dynamic>{
    'id': categoryId,
    'name': category,
    'code': category.toUpperCase(),
    'status': 'active',
    'requires_receipt': requiresReceipt,
    'maximum_amount': maximumAmount,
  },
  'project_id': projectId,
  'project': projectId == null
      ? null
      : <String, dynamic>{'id': projectId, 'name': projectName},
  'site_id': siteId,
  'site': siteId == null
      ? null
      : <String, dynamic>{'id': siteId, 'name': siteName},
  'expense_date': date,
  'amount': amount,
  'currency': currency,
  'description': description,
  'status': status,
  'is_draft': status == Expense.statusDraft,
  'is_open': status == Expense.statusDraft || status == Expense.statusPending,
  'requires_receipt': requiresReceipt,
  'maximum_amount': maximumAmount,
  'receipt_count': receiptCount,
  'receipts': receipts
      .map(
        (receipt) => <String, dynamic>{
          'id': receipt.id,
          'expense_id': receipt.expenseId,
          'original_name': receipt.originalName,
          'mime_type': receipt.mimeType,
          'size_bytes': receipt.sizeBytes,
          'is_image': receipt.isImage,
          'is_pdf': receipt.isPdf,
          'uploaded_by': 7,
          'created_at': '2026-09-28T09:15:00Z',
        },
      )
      .toList(),
  'current_approval_step': currentStep ?? (chain ? 1 : null),
  'approval_chain': chain
      ? <dynamic>[
          <String, dynamic>{
            'id': 100 + id,
            'sequence': 1,
            'name': 'Standard expense approval',
            'approver_type': 'reporting_manager',
            'status': status == Expense.statusPending
                ? 'pending'
                : status == Expense.statusApproved
                ? 'approved'
                : 'pending',
            'is_current': status == Expense.statusPending,
            'acted_at': status == Expense.statusApproved
                ? '2026-09-28T11:00:00Z'
                : null,
            'resolved_approver': <String, dynamic>{'full_name': 'Sara Iqbal'},
          },
          <String, dynamic>{
            'id': 200 + id,
            'sequence': 2,
            'name': 'Finance sign-off',
            'approver_type': 'permission',
            'approver_permission': 'expenses.manage',
            'status': status == Expense.statusApproved ? 'approved' : 'pending',
            'is_current': false,
            'acted_at': status == Expense.statusApproved
                ? '2026-09-28T16:00:00Z'
                : null,
            'remarks': status == Expense.statusRejected
                ? 'Out of policy.'
                : null,
          },
        ]
      : null,
  'submitted_at': submittedAt,
  'approved_at': approvedAt,
  'rejected_at': rejectedAt,
  'cancelled_at': cancelledAt,
  'created_at': '2026-09-28T08:00:00Z',
});
