import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/presentation/fields.dart';
import '../../core/presentation/form_controls.dart';
import '../../core/router/return_navigation.dart';
import 'account_repository.dart';

/// Changing your own password while signed in.
///
/// The rules — length, letters and numbers, the current password being
/// right — are the server's (`ChangePasswordRequest`) and come back as field
/// errors; this screen only checks what it can without asking: that every
/// box is filled and the two new ones agree. A success signs every *other*
/// device out, and says so.
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() =>
      _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();

  bool _saving = false;
  String? _banner;
  Map<String, String> _errors = const {};

  @override
  void dispose() {
    _current.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final errors = <String, String>{
      if (_current.text.isEmpty)
        'current_password': 'Enter your current password.',
      if (_password.text.isEmpty) 'password': 'Enter a new password.',
      if (_password.text.isNotEmpty && _confirmation.text != _password.text)
        'password_confirmation': 'The two new passwords do not match.',
    };

    if (errors.isNotEmpty) {
      setState(() {
        _errors = errors;
        _banner = null;
      });
      return;
    }

    setState(() {
      _saving = true;
      _errors = const {};
      _banner = null;
    });

    try {
      final message = await ref
          .read(accountRepositoryProvider)
          .changePassword(
            currentPassword: _current.text,
            password: _password.text,
            passwordConfirmation: _confirmation.text,
          );

      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
      context.returnTo('/home');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Change password')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null) FormBanner(message: _banner!),
          LabeledTextField(
            key: const ValueKey('current-password'),
            label: 'Current password',
            controller: _current,
            obscureText: true,
            isRequired: true,
            enabled: !_saving,
            autofillHints: const [AutofillHints.password],
            errorText: _errors['current_password'],
          ),
          LabeledTextField(
            key: const ValueKey('new-password'),
            label: 'New password',
            controller: _password,
            obscureText: true,
            isRequired: true,
            enabled: !_saving,
            helper: 'At least 8 characters, with letters and numbers.',
            autofillHints: const [AutofillHints.newPassword],
            errorText: _errors['password'],
          ),
          LabeledTextField(
            key: const ValueKey('confirm-password'),
            label: 'Confirm new password',
            controller: _confirmation,
            obscureText: true,
            isRequired: true,
            enabled: !_saving,
            textInputAction: TextInputAction.done,
            autofillHints: const [AutofillHints.newPassword],
            errorText: _errors['password_confirmation'],
          ),
          const SizedBox(height: 8),
          FilledButton(
            key: const ValueKey('save-password'),
            onPressed: _saving ? null : _submit,
            child: _saving
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Change password'),
          ),
          const SizedBox(height: 12),
          Text(
            'Your other devices will be signed out. This one stays signed in.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
