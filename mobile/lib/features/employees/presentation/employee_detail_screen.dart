import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../data/api_employees_repository.dart';
import '../domain/employee.dart';

/// One employee, in full.
///
/// Three failure shapes, deliberately distinguished: a 403 says the session
/// may not read this person at all; any other error says the request failed
/// and can be retried; a successful load with no salary means the *server*
/// decided not to show one, which is not an error and is not offered as a
/// retry.
class EmployeeDetailScreen extends ConsumerStatefulWidget {
  const EmployeeDetailScreen({super.key, required this.employeeId});

  final int employeeId;

  @override
  ConsumerState<EmployeeDetailScreen> createState() =>
      _EmployeeDetailScreenState();
}

class _EmployeeDetailScreenState extends ConsumerState<EmployeeDetailScreen> {
  Employee? _employee;
  String? _message;
  bool _forbidden = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _message = null;
      _forbidden = false;
    });

    try {
      final employee = await ref
          .read(employeesRepositoryProvider)
          .find(widget.employeeId);

      if (!mounted) return;

      setState(() {
        _employee = employee;
        _loading = false;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _message = failure.message;
        _forbidden = failure.statusCode == 403;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _message = 'Something went wrong while loading this employee. Please try again.';
      });
    }
  }

  Future<void> _delete(Employee employee) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove employee?'),
        content: Text(
          '${employee.fullName} will be removed from the directory. '
          'Their record is kept, and anything already recorded against it '
          'stays intact.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('confirm-delete'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Remove'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    try {
      await ref.read(employeesRepositoryProvider).remove(employee.id);

      if (!mounted) return;
      context.go('/employees');
    } on ApiException catch (failure) {
      if (!mounted) return;

      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(failure.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Employee')),
      body: _buildBody(scope),
    );
  }

  Widget _buildBody(PermissionScope scope) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_employee == null) {
      return Center(
        key: const ValueKey('detail-error'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                _forbidden ? Icons.lock_outline : Icons.error_outline,
                size: 48,
                color: Theme.of(context).colorScheme.error,
              ),
              const SizedBox(height: 12),
              Text(
                _message ?? 'This employee could not be loaded.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyLarge,
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                key: const ValueKey('detail-retry'),
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                label: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    final employee = _employee!;
    final theme = Theme.of(context);

    // Two gates, both required. The API omits `salary` entirely unless the
    // session holds `employees.salary.view`; the flag here exists so the UI
    // does not depend on that omission alone. `employees.view` alone has never
    // been enough to see a salary.
    final showSalary =
        scope.canViewSalary &&
        employee.salaryVisible &&
        employee.salary != null;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 28,
                child: Text(
                  employee.fullName.isEmpty
                      ? '?'
                      : employee.fullName[0].toUpperCase(),
                  style: theme.textTheme.titleLarge,
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      employee.fullName.isEmpty
                          ? employee.employeeCode
                          : employee.fullName,
                      key: const ValueKey('detail-name'),
                      style: theme.textTheme.titleLarge,
                    ),
                    const SizedBox(height: 4),
                    Text(employee.summary, style: theme.textTheme.bodyMedium),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          _section('Employment', [
            _row('Employee code', employee.employeeCode),
            _row('Status', employee.employmentStatus.replaceAll('_', ' ')),
            _row('Type', employee.employmentType.replaceAll('_', ' ')),
            _row('Joining date', employee.joiningDate),
            _row('Reporting manager', employee.reportingManagerName),
          ]),
          _section('Placement', [
            _row('Department', employee.departmentName),
            _row('Designation', employee.designationName),
            _row('Primary project', employee.primaryProjectName),
            _row('Primary site', employee.primarySiteName),
          ]),
          _section('Contact', [
            _row('Email', employee.email),
            _row('Phone', employee.phone),
            _row('Address', employee.address),
            _row('Date of birth', employee.dateOfBirth),
            _row('Nationality', employee.nationality),
          ]),
          _section('Emergency contact', [
            _row('Name', employee.emergencyContactName),
            _row('Phone', employee.emergencyContactPhone),
            _row('Relationship', employee.emergencyContactRelation),
          ]),
          if (showSalary)
            _section('Compensation', [
              _row(
                'Salary',
                employee.salary!.toStringAsFixed(2),
                key: const ValueKey('detail-salary'),
              ),
            ]),
          const SizedBox(height: 24),
          if (scope.canUpdateEmployees || scope.canDeleteEmployees)
            Row(
              children: [
                if (scope.canUpdateEmployees)
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const ValueKey('detail-edit'),
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit'),
                      onPressed: () =>
                          context.push('/employees/${employee.id}/edit'),
                    ),
                  ),
                if (scope.canUpdateEmployees && scope.canDeleteEmployees)
                  const SizedBox(width: 12),
                if (scope.canDeleteEmployees)
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const ValueKey('detail-delete'),
                      icon: const Icon(Icons.delete_outline),
                      label: const Text('Remove'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: theme.colorScheme.error,
                      ),
                      onPressed: () => _delete(employee),
                    ),
                  ),
              ],
            ),
        ],
      ),
    );
  }

  Widget _section(String title, List<Widget> rows) => Padding(
    padding: const EdgeInsets.only(bottom: 24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        DecoratedBox(
          decoration: BoxDecoration(
            border: Border.all(color: Theme.of(context).dividerColor),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(children: rows),
        ),
      ],
    ),
  );

  Widget _row(String label, String? value, {Key? key}) {
    final resolved = (value == null || value.trim().isEmpty) ? '—' : value;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 140,
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
          Expanded(
            child: Text(resolved, key: key ?? ValueKey('detail-row-$label')),
          ),
        ],
      ),
    );
  }
}
