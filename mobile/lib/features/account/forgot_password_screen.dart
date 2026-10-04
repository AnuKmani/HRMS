import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_exception.dart';
import '../../core/presentation/fields.dart';
import '../../core/presentation/form_controls.dart';
import 'account_repository.dart';

/// "I can't sign in": ask for a reset link by email.
///
/// Reachable signed out — it is the one screen besides the sign-in form
/// that is. The server answers the same sentence whether or not the address
/// belongs to anybody, and this screen repeats that sentence rather than
/// inventing a friendlier one that would give the answer away. The new
/// password is chosen on the web page the email links to.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _email = TextEditingController();

  bool _sending = false;
  String? _sent;
  String? _banner;
  String? _emailError;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _email.text.trim();

    if (email.isEmpty || !email.contains('@') || email.contains(' ')) {
      setState(() => _emailError = 'Enter a valid email address.');
      return;
    }

    setState(() {
      _sending = true;
      _emailError = null;
      _banner = null;
    });

    try {
      final message = await ref
          .read(accountRepositoryProvider)
          .requestPasswordReset(email);

      if (!mounted) return;
      setState(() {
        _sending = false;
        _sent = message;
      });
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _emailError = failure.errors['email'];
        _banner = _emailError == null ? failure.message : null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final sent = _sent;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Reset password'),
        // Reached by `push` from the sign-in form, but a cold deep link has
        // nothing under it — Back must still lead somewhere.
        leading: context.canPop()
            ? null
            : IconButton(
                tooltip: 'Back to sign in',
                icon: const Icon(Icons.arrow_back),
                onPressed: () => context.go('/login'),
              ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (sent != null) ...[
            Text(
              sent,
              key: const ValueKey('reset-sent'),
              style: Theme.of(context).textTheme.bodyLarge,
            ),
            const SizedBox(height: 8),
            Text(
              'Open the link in the email to choose a new password, then '
              'sign in here with it.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ] else ...[
            if (_banner != null) FormBanner(message: _banner!),
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Text(
                'Enter the email address of your work account and we will '
                'send you a link to choose a new password.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
            LabeledTextField(
              key: const ValueKey('reset-email'),
              label: 'Email',
              controller: _email,
              isRequired: true,
              enabled: !_sending,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.email],
              errorText: _emailError,
            ),
            FilledButton(
              key: const ValueKey('send-reset-link'),
              onPressed: _sending ? null : _submit,
              child: _sending
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Send reset link'),
            ),
          ],
        ],
      ),
    );
  }
}
