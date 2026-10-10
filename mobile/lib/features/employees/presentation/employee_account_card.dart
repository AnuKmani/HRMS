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
    this.mustChangePassword = false,
  });

  /// The username the employee signs in with.
  final String email;
  final List<String> roles;

  /// The password HR handed out, readable by HR only while the employee
  /// has not yet replaced it with their own.
  final String? temporaryPassword;

  /// True until the employee chooses their own password.
  final bool mustChangePassword;

  factory EmployeeAccount.fromJson(Map<String, dynamic> json) =>
      EmployeeAccount(
        email: json['email'] as String? ?? '',
        roles: [
          for (final role in (json['roles'] as List? ?? const []))
            if (role is String) role,
        ],
        temporaryPassword: json['temporary_password'] as String?,
        mustChangePassword: json['must_change_password'] == true,
      );
}

/// The account half of `GET /employees/{id}/account`, or null when the
/// employee has no login yet. The API's envelope turns "no login" into an
/// empty object `{}`, not null — reading that as an account would show a
/// login with a blank username and hide the Create login button.
EmployeeAccount? parseEmployeeAccount(Object? data) {
  if (data is! Map<String, dynamic>) return null;
  final email = data['email'];
  if (data['user_id'] == null || email is! String || email.isEmpty) {
    return null;
  }
  return EmployeeAccount.fromJson(data);
}

abstract class EmployeeAccountRepository {
  Future<EmployeeAccount?> find(int employeeId);
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
    String? password,
  });
  Future<EmployeeAccount> changeRole(int employeeId, String role);

  /// HR hands out a new temporary password; blank means the server makes
  /// one up. Either way the employee must choose their own at next sign-in.
  Future<EmployeeAccount> resetPassword(int employeeId, {String? password});
  Future<List<String>> roles();
}

class ApiEmployeeAccountRepository implements EmployeeAccountRepository {
  ApiEmployeeAccountRepository(this._client);

  final ApiClient _client;

  @override
  Future<EmployeeAccount?> find(int employeeId) async {
    final data = (await _client.get('/employees/$employeeId/account')).data;
    return parseEmployeeAccount(data);
  }

  @override
  Future<EmployeeAccount> create(
    int employeeId, {
    required String role,
    String? email,
    String? password,
  }) async => _one(
    (await _client.post(
      '/employees/$employeeId/account',
      body: <String, Object?>{
        'role': role,
        if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
        if (password != null && password.isNotEmpty) 'password': password,
      },
    )).data,
  );

  @override
  Future<EmployeeAccount> resetPassword(
    int employeeId, {
    String? password,
  }) async => _one(
    (await _client.post(
      '/employees/$employeeId/account/reset-password',
      body: <String, Object?>{
        if (password != null && password.isNotEmpty) 'password': password,
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

  /// HR types the first password to hand over, or leaves it blank for one
  /// the app makes up. Null when cancelled.
  Future<String?> _askPassword({required String title, required String go}) =>
      showDialog<String>(
        context: context,
        builder: (context) => _PasswordDialog(title: title, go: go),
      );

  Future<void> _create() async {
    final role = await _pickRole();
    if (role == null || !mounted) return;
    final password = await _askPassword(
      title: 'First password',
      go: 'Create login',
    );
    if (password == null || !mounted) return;

    setState(() => _saving = true);
    try {
      final account = await ref
          .read(employeeAccountRepositoryProvider)
          .create(widget.employeeId, role: role, password: password);
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

  Future<void> _resetPassword() async {
    final password = await _askPassword(title: 'Reset password', go: 'Reset');
    if (password == null || !mounted) return;

    setState(() => _saving = true);
    try {
      final account = await ref
          .read(employeeAccountRepositoryProvider)
          .resetPassword(widget.employeeId, password: password);
      if (!mounted) return;
      setState(() => _saving = false);
      await _showPassword(account, title: 'Password reset');
      await _load();
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

  Future<void> _showPassword(
    EmployeeAccount account, {
    String title = 'Login created',
  }) => showDialog<void>(
    context: context,
    builder: (context) => AlertDialog(
      icon: const Icon(Icons.key, size: 40),
      title: Text(title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Give these to the employee. You can also see the password on '
            'this profile until they change it.',
          ),
          const SizedBox(height: 12),
          const Text('Username (email)'),
          Text(
            account.email,
            key: const ValueKey('created-username'),
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
          ),
          const SizedBox(height: 8),
          const Text('Temporary password'),
          Text(
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
            'When they sign in with it, the app asks them to choose their '
            'own password straight away.',
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

  void _copy(String text, String done) {
    Clipboard.setData(ClipboardData(text: text));
    _snack(done);
  }

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
    'Site Supervisor' => 'Site visits, site attendance, first approvals',
    'Site Officer' => 'Own attendance and site visits',
    'Technician' => 'Own attendance and site visits',
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
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            key: const ValueKey('account-existing'),
            leading: const Icon(Icons.verified_user_outlined),
            title: const Text('Username'),
            subtitle: Text(
              account.email.isEmpty ? '—' : account.email,
              key: const ValueKey('account-username'),
              style: TextStyle(
                fontWeight: FontWeight.w700,
                fontSize: 16,
                color: Theme.of(context).colorScheme.onSurface,
              ),
            ),
            trailing: IconButton(
              tooltip: 'Copy username',
              icon: const Icon(Icons.copy, size: 20),
              onPressed: () => _copy(account.email, 'Username copied.'),
            ),
          ),
          _PasswordRow(account: account, onCopy: _copy),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Wrap(
              spacing: 6,
              children: [
                for (final role in account.roles)
                  Chip(label: Text(role), visualDensity: VisualDensity.compact),
              ],
            ),
          ),
        ],
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
                          : Row(
                              children: [
                                Expanded(
                                  child: OutlinedButton.icon(
                                    key: const ValueKey('account-change-role'),
                                    onPressed: _saving ? null : _changeRole,
                                    icon: const Icon(Icons.swap_horiz),
                                    label: const Text('Change role'),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: OutlinedButton.icon(
                                    key: const ValueKey(
                                      'account-reset-password',
                                    ),
                                    onPressed: _saving ? null : _resetPassword,
                                    icon: const Icon(Icons.lock_reset),
                                    label: const Text('Reset password'),
                                  ),
                                ),
                              ],
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

/// The password HR will hand over. Blank is allowed: the server then makes
/// one up. Returns the text (possibly empty), or null on Cancel.
class _PasswordDialog extends StatefulWidget {
  const _PasswordDialog({required this.title, required this.go});

  final String title;
  final String go;

  @override
  State<_PasswordDialog> createState() => _PasswordDialogState();
}

class _PasswordDialogState extends State<_PasswordDialog> {
  final TextEditingController _password = TextEditingController();

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final text = _password.text.trim();
    final tooShort = text.isNotEmpty && text.length < 4;

    return AlertDialog(
      title: Text(widget.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            key: const ValueKey('account-password'),
            controller: _password,
            autofocus: true,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              labelText: 'Password to give the employee',
              hintText: 'e.g. 12345',
              errorText: tooShort ? 'At least 4 characters.' : null,
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'Leave it blank and the app makes one up. Either way it is '
            'temporary: the employee chooses their own at first sign-in.',
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('account-password-go'),
          onPressed: tooShort ? null : () => Navigator.of(context).pop(text),
          child: Text(widget.go),
        ),
      ],
    );
  }
}

/// "Password: 12345 (temporary)" while HR's password is still in use;
/// "Changed by the employee" once it is not — nobody can read that one.
class _PasswordRow extends StatefulWidget {
  const _PasswordRow({required this.account, required this.onCopy});

  final EmployeeAccount account;
  final void Function(String text, String done) onCopy;

  @override
  State<_PasswordRow> createState() => _PasswordRowState();
}

class _PasswordRowState extends State<_PasswordRow> {
  bool _shown = false;

  @override
  Widget build(BuildContext context) {
    final password = widget.account.temporaryPassword;

    if (!widget.account.mustChangePassword) {
      return const ListTile(
        key: ValueKey('account-password-own'),
        leading: Icon(Icons.lock_outline),
        title: Text('Password'),
        subtitle: Text(
          'Changed by the employee — only they know it. If they forget '
          'it, use Reset password.',
        ),
      );
    }

    if (password == null) {
      return const ListTile(
        key: ValueKey('account-password-unknown'),
        leading: Icon(Icons.lock_clock_outlined),
        title: Text('Password'),
        subtitle: Text(
          'Temporary password not changed yet. Use Reset password to '
          'issue a new one.',
        ),
      );
    }

    return ListTile(
      key: const ValueKey('account-password-temporary'),
      leading: const Icon(Icons.key),
      title: const Text('Password (temporary)'),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _shown ? password : '••••••',
            key: const ValueKey('account-password-value'),
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
          ),
          const Text('Not changed by the employee yet.'),
        ],
      ),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            key: const ValueKey('account-password-toggle'),
            tooltip: _shown ? 'Hide password' : 'Show password',
            icon: Icon(_shown ? Icons.visibility_off : Icons.visibility),
            onPressed: () => setState(() => _shown = !_shown),
          ),
          IconButton(
            tooltip: 'Copy password',
            icon: const Icon(Icons.copy, size: 20),
            onPressed: () => widget.onCopy(password, 'Password copied.'),
          ),
        ],
      ),
    );
  }
}
