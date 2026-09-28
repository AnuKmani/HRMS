import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../data/api_loan_repository.dart';
import '../domain/loan.dart';

/// Asking for a loan or a salary advance.
///
/// Two things this form deliberately does not contain:
///
///  - **No "who is this for" field.** The server defaults `employee_id` to
///    the caller's own record for the same reason the certificate form has
///    no "employee" field: a person borrowing from their phone is not
///    looking up their own row id first. Somebody with `loans.manage` can
///    still file one for a colleague — through the API, and through the same
///    refusal if they do not hold it.
///
///  - **No status, balance or approver.** Those are the lifecycle's, not
///    the requester's; `LoanService` writes them and `StoreLoanRequest`
///    rejects them. A form that offered them would look like a form the
///    server ignores.
///
/// The installment amount is optional because the server divides the
/// principal evenly when it is left blank, and that split is the one a
/// client should not be re-deriving. When it *is* given, the server checks
/// it leaves something for the last payment and answers with a sentence —
/// so a failure here is drawn against the field rather than as a mystery.
class LoanFormScreen extends ConsumerStatefulWidget {
  const LoanFormScreen({super.key, this.loanId});

  /// A draft to edit, or null to ask for a new one.
  ///
  /// The same screen for both, because the API's edit is the same fields
  /// minus `employee_id` — and because a draft's whole purpose is that it
  /// can be corrected before anybody sees it. Once submitted the server
  /// refuses with a 409, which this screen draws as a banner rather than
  /// silently leaving the user editing a row that stopped accepting edits.
  final int? loanId;

  @override
  ConsumerState<LoanFormScreen> createState() => _LoanFormScreenState();
}

class _LoanFormScreenState extends ConsumerState<LoanFormScreen> {
  final _type = TextEditingController(text: Loan.typeLoan);
  final _principal = TextEditingController();
  final _installments = TextEditingController();
  final _each = TextEditingController();
  final _start = TextEditingController();
  final _reference = TextEditingController();
  final _remarks = TextEditingController();

  Map<String, String> _fieldErrors = const <String, String>{};
  String? _banner;
  bool _forbidden = false;
  bool _saving = false;
  bool _loading = false;

  bool get _isEdit => widget.loanId != null;

  @override
  void initState() {
    super.initState();

    if (_isEdit) _prefill();
  }

  Future<void> _prefill() async {
    setState(() => _loading = true);

    try {
      final loan = await ref.read(loanRepositoryProvider).find(widget.loanId!);

      if (!mounted) return;

      setState(() {
        _type.text = loan.loanType;
        _principal.text = loan.principalAmount;
        _installments.text = '${loan.numberOfInstallments}';
        if (loan.installmentAmount != '0.00') {
          _each.text = loan.installmentAmount;
        }
        _start.text = loan.startDate ?? '';
        _reference.text = loan.reference ?? '';
        _remarks.text = loan.remarks ?? '';
        _loading = false;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = failure.message;
        _forbidden = failure.statusCode == 403;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = 'The draft could not be loaded. Please try again.';
      });
    }
  }

  @override
  void dispose() {
    _type.dispose();
    _principal.dispose();
    _installments.dispose();
    _each.dispose();
    _start.dispose();
    _reference.dispose();
    _remarks.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _fieldErrors = const <String, String>{};
      _banner = null;
      _forbidden = false;
      _saving = true;
    });

    final body = <String, Object?>{
      'loan_type': _type.text,
      'principal_amount': _principal.text.trim(),
      'number_of_installments': _installments.text.trim(),
      // Left out when blank: `UpdateLoanRequest` treats an absent field as
      // "unchanged", which is how a client that never offered an installment
      // amount gets the server's own even split instead of an empty string
      // it then has to interpret.
      if (_each.text.trim().isNotEmpty) 'installment_amount': _each.text.trim(),
      'start_date': _start.text.trim(),
      // Both sent always, edit or create: `nullable` turns an emptied field
      // into a genuine "no reference", where omitting it would leave the old
      // one behind and the user would be clearing a field that would not
      // clear.
      'reference': _reference.text.trim(),
      'remarks': _remarks.text.trim(),
    };

    try {
      final repository = ref.read(loanRepositoryProvider);

      final loan = _isEdit
          ? await repository.update(widget.loanId!, body)
          : await repository.create(body);

      if (!mounted) return;

      // The draft is a real object the detail screen can then act on —
      // submitting it, editing it or leaving it as a draft — so the form
      // hands the user straight to the row it just made rather than to the
      // list where they would have to find it again.
      context.go('/loans/${loan.id}');
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _saving = false;

        if (failure.isValidation) {
          _fieldErrors = failure.errors;
        } else {
          _banner = failure.message;
          _forbidden = failure.statusCode == 403;
        }
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _saving = false;
        _banner = 'Something went wrong while saving. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    // Two different doors: asking for one needs `loans.create`, correcting
    // your own draft needs only the `loans.view` the route is registered
    // behind (the policy then checks whose it is).
    final allowed = _isEdit ? scope.canViewLoans : scope.canCreateLoans;

    if (!allowed) {
      return Scaffold(
        appBar: AppBar(title: Text(_isEdit ? 'Edit loan' : 'New loan')),
        body: Center(
          child: Text(
            _isEdit
                ? 'You may not edit this draft.'
                : 'You may not ask for a loan.',
          ),
        ),
      );
    }

    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit loan')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Edit draft' : 'New loan')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            FormBanner(message: _banner!, forbidden: _forbidden),
          StatusField(
            label: 'Kind of advance',
            value: _type.text,
            options: const <StatusOption>[
              StatusOption(Loan.typeLoan, 'Loan'),
              StatusOption(Loan.typeSalaryAdvance, 'Salary advance'),
            ],
            onChanged: (value) => setState(() => _type.text = value),
            helper:
                'Both are repaid the same way; the label only records what '
                'was asked for.',
          ),
          LabeledTextField(
            key: const ValueKey('loan-principal'),
            label: 'Amount',
            controller: _principal,
            isRequired: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            errorText: _fieldErrors['principal_amount'],
            hint: '12000.00',
          ),
          LabeledTextField(
            key: const ValueKey('loan-installments'),
            label: 'Number of installments',
            controller: _installments,
            isRequired: true,
            keyboardType: TextInputType.number,
            errorText: _fieldErrors['number_of_installments'],
            hint: '12',
            helper:
                'Taken from your salary each month until the balance '
                'reaches zero.',
          ),
          LabeledTextField(
            key: const ValueKey('loan-each'),
            label: 'Installment amount (optional)',
            controller: _each,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            errorText: _fieldErrors['installment_amount'],
            helper:
                'Leave this blank to split the amount evenly — the server '
                'works it out and always leaves something for the last '
                'payment.',
          ),
          DateField(
            key: const ValueKey('loan-start'),
            label: 'First installment due',
            controller: _start,
            isRequired: true,
            errorText: _fieldErrors['start_date'],
            onChanged: (_) => setState(() {}),
          ),
          LabeledTextField(
            key: const ValueKey('loan-reference'),
            label: 'Reference',
            controller: _reference,
            errorText: _fieldErrors['reference'],
            hint: 'Optional — your own note for what this is against',
          ),
          LabeledTextField(
            key: const ValueKey('loan-remarks'),
            label: 'Remarks',
            controller: _remarks,
            maxLines: 3,
            errorText: _fieldErrors['remarks'],
            hint: 'Optional',
          ),
          const SizedBox(height: 8),
          FilledButton(
            key: const ValueKey('loan-submit'),
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(_isEdit ? 'Save changes' : 'Save as draft'),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
