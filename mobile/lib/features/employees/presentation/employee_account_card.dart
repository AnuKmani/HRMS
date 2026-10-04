import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';

/// Who this employee signs in as, and with which role.
class EmployeeAccount {
  const EmployeeAccount({
    required this.email,
    required this.roles,
    this.temporaryPassword,
  });

  final String email;
  final List<String> roles;

  /// Present only in the response that created the login — shown once.
  final String? temporaryPassword;

  factory EmployeeAccount.fromJson(Map<String, dynamic> json) =>
      EmployeeAccount(
        email: json['email'] as String? ?? '',
        roles: [
          for (final role in (json['roles'] as List? ?? const []))
            if (role is String) role,
        ],
        temporaryPassword: json['temporary_password'] as String?,
      );
}

abstract class EmployeeAccountRepository {
  Future<EmployeeAccount?> find(int employeeId);
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
  });
  Future<EmployeeAccount> changeRole(int employeeId, String role);
  Future<List<String>> roles();
}

class ApiEmployeeAccountRepository implements EmployeeAccountRepository {
  ApiEmployeeAccountRepository(this._client);

  final ApiClient _client;

  @override
  Future<EmployeeAccount?> find(int employeeId) async {
    final data = (await _client.get('/employees/$employeeId/account')).data;
    return data is Map<String, dynamic> ? EmployeeAccount.fromJson(data) : null;
  }

  @override
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
  }) async => _one(
    (await _client.post(
      '/employees/$employeeId/account',
      body: <String, Object?>{
        'role': role,
        if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
      },
    )).data,
  );

  @override
  Future<EmployeeAccount> changeRole(int employeeId, String role) async => _one(
    (await _client.put(
      '/employees/$employeeId/account',
      body: <String, Object?>{'role': role},
    )).data,
  );

  @override
  Future<List<String>> roles() async {
    final data = (await _client.get('/roles')).data;
    if (data is! List) throw unexpectedShapeException;
    return [
      for (final row in data)
        if (row is Map && row['name'] is String) row['name'] as String,
    ];
  }

  EmployeeAccount _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return EmployeeAccount.fromJson(data);
  }
}

final employeeAccountRepositoryProvider = Provider<EmployeeAccountRepository>(
  (ref) => ApiEmployeeAccountRepository(ref.watch(apiClientProvider)),
);

/// The "App login & role" section of an employee's page.
///
/// HR gives a new joiner a login here and chooses their role — Employee,
/// Site Supervisor, Project Manager… The role is everything they can do:
/// a Project Manager can add sites because that role can, and the server
/// enforces it. Shown to `users.view`; the buttons need `users.manage`.
class EmployeeAccountCard extends ConsumerStatefulWidget {
  const EmployeeAccountCard({
    super.key,
    required this.employeeId,
    required this.employeeEmail,
  });

  final int employeeId;
  final String? employeeEmail;

  @override
  ConsumerState<EmployeeAccountCard> createState() =>
      _EmployeeAccountCardState();
}

class _EmployeeAccountCardState extends ConsumerState<EmployeeAccountCard> {
  bool _loading = true;
  bool _saving = false;
  EmployeeAccount? _account;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final account = await ref
          .read(employeeAccountRepositoryProvider)
          .find(widget.employeeId);
      if (!mounted) return;
      setState(() {
        _account = account;
        _loading = false;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _error = failure.message;
        _loading = false;
      });
    }
  }

  Future<String?> _pickRole({String? current}) async {
    final List<String> roles;
    try {
      roles = await ref.read(employeeAccountRepositoryProvider).roles();
    } on ApiException catch (failure) {
      _snack(failure.message);
      return null;
    }
    if (!mounted) return null;

    return showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text(
                'Choose the role. It decides everything this person can do.',
              ),
            ),
            for (final role in roles)
              ListTile(
                key: ValueKey('role-option-$role'),
                leading: Icon(
                  role == current
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                ),
                title: Text(role),
                subtitle: Text(_describe(role)),
                onTap: () => Navigator.of(context).pop(role),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _create() async {
    final role = await _pickRole();
    if (role == null || !mounted) return;

    setState(() => _saving = true);
    try {
      final account = await ref
          .read(employeeAccountRepositoryProvider)
          .create(widget.employeeId, role: role);
      if (!mounted) return;
      setState(() {
        _account = account;
        _saving = false;
      });
      await _showPassword(account);
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() => _saving = false);
      _snack(
        failure.errors.values.isNotEmpty
            ? failure.errors.values.first
            : failure.message,
      );
    }
  }

  Future<void> _changeRole() async {
    final account = _account;
    if (account == null) return;
    final role = await _pickRole(
      current: account.roles.isEmpty ? null : account.roles.first,
    );
    if (role == null || !mounted) return;

    setState(() => _saving = true);
    try {
      final updated = await ref
          .read(employeeAccountRepositoryProvider)
          .changeRole(widget.employeeId, role);
      if (!mounted) return;
      setState(() {
        _account = updated;
        _saving = false;
      });
      _snack(
        'Role changed to $role. It applies the next time they open the app.',
      );
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() => _saving = false);
      _snack(failure.message);
    }
  }

  Future<void> _showPassword(EmployeeAccount account) => showDialog<void>(
    context: context,
    builder: (context) => AlertDialog(
      icon: const Icon(Icons.key, size: 40),
      title: const Text('Login created'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Give these to the employee. The password is shown only now.',
          ),
          const SizedBox(height: 12),
          const Text('Email'),
          SelectableText(
            account.email,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          const Text('Temporary password'),
          SelectableText(
            account.temporaryPassword ?? '',
            key: const ValueKey('temporary-password'),
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 20,
              letterSpacing: 1,
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'They should change it after signing in: account icon → '
            'Change password.',
          ),
        ],
      ),
      actions: [
        TextButton.icon(
          onPressed: () => Clipboard.setData(
            ClipboardData(
              text:
                  'Email: ${account.email}\n'
                  'Password: ${account.temporaryPassword ?? ''}',
            ),
          ),
          icon: const Icon(Icons.copy),
          label: const Text('Copy'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done'),
        ),
      ],
    ),
  );

  void _snack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  static String _describe(String role) => switch (role) {
    'Super Admin' => 'Everything, including system setup',
    'HR Admin' => 'People, leave, documents, logins and roles',
    'HR Executive' => 'Day-to-day HR work',
    'Payroll Admin' => 'Payroll, payslips, loans, certificates',
    'Project Manager' => 'Projects, sites (can add sites), site staff',
    'Site Engineer' => 'Site visits and site reports',
    'Site Supervisor' => 'Site attendance, daily reports, first approvals',
    'Finance' => 'Expenses and money approvals',
    'Management' => 'Summaries and totals',
    'Employee' => 'Own attendance, leave, payslips',
    _ => '',
  };

  @override
  Widget build(BuildContext context) {
    final canManage = ref.watch(
      permissionScopeProvider.select((scope) => scope.canManageUsers),
    );
    final theme = Theme.of(context);
    final account = _account;

    final Widget body;
    if (_loading) {
      body = const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    } else if (_error != null) {
      body = ListTile(
        title: Text(_error!),
        trailing: TextButton(onPressed: _load, child: const Text('Retry')),
      );
    } else if (account == null) {
      body = ListTile(
        key: const ValueKey('account-none'),
        leading: const Icon(Icons.no_accounts_outlined),
        title: const Text('No app login yet'),
        subtitle: Text(
          canManage
              ? 'Create one to let them sign in. Their email: '
                    '${widget.employeeEmail ?? '—'}'
              : 'HR can create one.',
        ),
      );
    } else {
      body = ListTile(
        key: const ValueKey('account-existing'),
        leading: const Icon(Icons.verified_user_outlined),
        title: Text(account.email),
        subtitle: Wrap(
          spacing: 6,
          children: [
            for (final role in account.roles)
              Chip(label: Text(role), visualDensity: VisualDensity.compact),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('App login & role', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: theme.dividerColor),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Column(
              children: [
                body,
                if (canManage && !_loading && _error == null)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                    child: SizedBox(
                      width: double.infinity,
                      child: account == null
                          ? FilledButton.icon(
                              key: const ValueKey('account-create'),
                              onPressed: _saving ? null : _create,
                              icon: const Icon(Icons.person_add),
                              label: const Text('Create login & choose role'),
                            )
                          : OutlinedButton.icon(
                              key: const ValueKey('account-change-role'),
                              onPressed: _saving ? null : _changeRole,
                              icon: const Icon(Icons.swap_horiz),
                              label: const Text('Change role'),
                            ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
