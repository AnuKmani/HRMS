import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../projects/domain/project.dart';
import '../../projects/presentation/projects_controller.dart';
import '../../sites/domain/site.dart';
import '../../sites/presentation/sites_controller.dart';
import '../data/api_expense_repository.dart';
import '../domain/expense_category.dart';
import 'expenses_controller.dart';

/// Claim an expense, or edit a draft — [expenseId] null means create.
///
/// What is *not* on this form is the point of it: no status, no approval
/// step, no employee. A claim is a draft until it is submitted, the chain is
/// the workflow's, and `employee_id` is refused by the API because the claim
/// is filed for whoever is signed in — a form that offered those three would
/// be asking the person spending their own money to declare who owns it and
/// where it is in a process they have not started.
///
/// Validation is the server's, not a mirror of it. Every field draws an error
/// the API returned, which is the same split `fields.dart` exists for: a rule
/// copied into Dart is a rule that stops being true the day the backend
/// changes it, and nobody notices until a claim the screen accepted comes
/// back red.
class ExpenseFormScreen extends ConsumerStatefulWidget {
  const ExpenseFormScreen({super.key, this.expenseId});

  /// A draft to edit, or null to raise a new claim.
  final int? expenseId;

  @override
  ConsumerState<ExpenseFormScreen> createState() => _ExpenseFormScreenState();
}

class _ExpenseFormScreenState extends ConsumerState<ExpenseFormScreen> {
  late final TextEditingController _date;
  late final TextEditingController _amount;
  late final TextEditingController _currency;
  late final TextEditingController _description;

  int? _categoryId;
  String? _categoryName;
  ExpenseCategory? _category;

  int? _siteId;
  int? _projectId;
  String? _siteName;
  String? _projectName;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.expenseId == null;

  @override
  void initState() {
    super.initState();
    _date = TextEditingController();
    _amount = TextEditingController();
    // The organisation's code, and the same default `Money` formats with.
    // The app has no settings endpoint, so rather than leave the field blank
    // and make every first claim fail on a rule the person was never shown,
    // it starts at the code the server's own example uses and says so.
    _currency = TextEditingController(text: 'INR');
    _description = TextEditingController();

    if (!_isCreate) {
      // The same condition the build gate uses: a draft this session may not
      // correct is never downloaded either. Otherwise a URL typed by hand
      // would fetch the claim first and refuse it a moment later.
      if (ref.read(permissionScopeProvider).canUpdateExpenses) _load();
    }
  }

  @override
  void dispose() {
    _date.dispose();
    _amount.dispose();
    _currency.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final claim = await ref
          .read(expenseRepositoryProvider)
          .find(widget.expenseId!);
      if (!mounted) return;

      setState(() {
        _date.text = claim.expenseDate;
        _amount.text = claim.amount;
        _currency.text = claim.currency;
        _description.text = claim.description;
        _categoryId = claim.expenseCategoryId;
        _categoryName = claim.categoryName;
        _category = claim.category;
        _siteId = claim.siteId;
        _siteName = claim.siteName;
        _projectId = claim.projectId;
        _projectName = claim.projectName;
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _banner = failure is ApiException
            ? failure.message
            : 'Something went wrong. Please try again.';
        _loading = false;
      });
    }
  }

  /// The category's two rules, read from the row itself rather than kept
  /// from the moment it was tapped: the picker's list may still be in
  /// flight, and a rule that appeared only after it landed would flicker on
  /// for a claim that had already been edited from the claim's own copy.
  ExpenseCategory? get _selectedCategory {
    for (final row in ref.watch(expenseCategoriesPickerProvider).items) {
      if (row.id == _categoryId) return row;
    }

    // Editing carries the rules with it, so a draft opened on a slow
    // connection still says "Needs a receipt" before anything else loads.
    return _category;
  }

  Future<void> _save() async {
    if (_saving) return;

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const <String, String>{};
    });

    // No `employee_id` and no `status`: both are `prohibited` in
    // `StoreExpenseRequest`, and sending either would be asking to be
    // refused rather than saving a round trip.
    final body = <String, Object?>{
      'expense_date': _date.text.trim(),
      'expense_category_id': _categoryId,
      'amount': _amount.text.trim(),
      'currency': _currency.text.trim().toUpperCase(),
      'description': _description.text.trim(),
      'site_id': _siteId,
      'project_id': _projectId,
    };

    try {
      final repository = ref.read(expenseRepositoryProvider);

      final claim = _isCreate
          ? await repository.create(body)
          : await repository.update(widget.expenseId!, body);

      ref.read(expenseListProvider.notifier).reload();
      if (!mounted) return;

      // The draft is a real object with a submit button on it, so the form
      // hands the user straight to the row it just made.
      context.go('/expenses/${claim.id}');
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _saving = false;

        if (failure.isValidation) {
          _errors = failure.errors;
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

    // Two different doors: asking needs `expenses.create`, correcting your
    // own draft needs the `expenses.update` the PUT route is registered
    // behind (the policy then checks whose it is).
    final allowed = _isCreate
        ? scope.canCreateExpenses
        : scope.canUpdateExpenses;

    if (!allowed) {
      return Scaffold(
        appBar: AppBar(
          title: Text(_isCreate ? 'New expense claim' : 'Edit expense draft'),
        ),
        body: Center(
          child: Text(
            _isCreate
                ? 'You may not raise an expense claim.'
                : 'You may not edit this draft.',
          ),
        ),
      );
    }

    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit expense draft')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    final rules = _selectedCategory?.ruleSummary ?? '';

    return Scaffold(
      appBar: AppBar(
        title: Text(_isCreate ? 'New expense claim' : 'Edit expense draft'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_banner != null)
              Padding(
                key: const ValueKey('expense-form-banner'),
                padding: const EdgeInsets.only(bottom: 16),
                child: Material(
                  color: Theme.of(context).colorScheme.errorContainer,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Text(
                      _banner!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.onErrorContainer,
                      ),
                    ),
                  ),
                ),
              ),
            DateField(
              key: const ValueKey('expense-date'),
              label: 'Date spent',
              controller: _date,
              isRequired: true,
              // Money already spent: the server enforces the same bound, and
              // offering a future date here would only set up a refusal
              // later.
              lastDate: DateTime.now(),
              errorText: _errors['expense_date'],
            ),
            RemotePickerField<ExpenseCategory>(
              key: const ValueKey('expense-category'),
              label: 'Category',
              provider: expenseCategoriesPickerProvider,
              idOf: (category) => category.id,
              labelOf: (category) => category.name,
              value: _categoryId,
              selectedLabel: _categoryName,
              isRequired: true,
              hint: 'Choose a category',
              searchHint: 'Search categories',
              sheetTitle: 'Expense category',
              errorText: _errors['expense_category_id'],
              onChanged: (id) => setState(() {
                _categoryId = id;
                _categoryName = null;
              }),
            ),
            if (rules.isNotEmpty)
              Padding(
                key: const ValueKey('expense-category-rules'),
                padding: const EdgeInsets.only(bottom: 16),
                child: Text(
                  rules,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            LabeledTextField(
              key: const ValueKey('expense-amount'),
              label: 'Amount',
              controller: _amount,
              isRequired: true,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              hint: '0.00',
              errorText: _errors['amount'],
            ),
            LabeledTextField(
              key: const ValueKey('expense-currency'),
              label: 'Currency',
              controller: _currency,
              isRequired: true,
              hint: 'INR',
              helper: 'Three letters, the code this was charged in.',
              errorText: _errors['currency'],
            ),
            LabeledTextField(
              key: const ValueKey('expense-description'),
              label: 'What it was for',
              controller: _description,
              isRequired: true,
              maxLines: 3,
              hint: 'e.g. Taxi from the airport to the site office',
              errorText: _errors['description'],
            ),
            RemotePickerField<Site>(
              key: const ValueKey('expense-site'),
              label: 'Site (optional)',
              provider: sitesPickerProvider,
              idOf: (site) => site.id,
              labelOf: (site) => site.name,
              value: _siteId,
              selectedLabel: _siteName,
              hint: 'No specific site',
              searchHint: 'Search sites',
              errorText: _errors['site_id'],
              onChanged: (id) => setState(() {
                _siteId = id;
                _siteName = null;
              }),
            ),
            RemotePickerField<Project>(
              key: const ValueKey('expense-project'),
              label: 'Project (optional)',
              provider: projectsPickerProvider,
              idOf: (project) => project.id,
              labelOf: (project) => project.name,
              value: _projectId,
              selectedLabel: _projectName,
              hint: 'No specific project',
              searchHint: 'Search projects',
              errorText: _errors['project_id'],
              helper: _siteId != null && _projectId == null
                  ? 'A site has to belong to the project chosen here.'
                  : null,
              onChanged: (id) => setState(() {
                _projectId = id;
                _projectName = null;
              }),
            ),
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('save-expense'),
              onPressed: _saving || _forbidden ? null : _save,
              child: _saving
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(_isCreate ? 'Save as draft' : 'Save changes'),
            ),
          ],
        ),
      ),
    );
  }
}
