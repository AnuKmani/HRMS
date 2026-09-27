import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_controller.dart';

/// The sign-in form.
///
/// Errors are drawn in two places, and the split follows the API rather than
/// the other way round: a 422 arrives as `message` (a banner) plus `errors`
/// (a value under each offending input), while a rejected 401 arrives as a
/// banner alone — deliberately, so the answer is identical for an unknown
/// address and a wrong password and the form cannot be used to discover which
/// accounts exist.
///
/// Local and server-side validation stay separate on purpose. The validators
/// below only ask "is this filled in and shaped like an address"; anything
/// the API disputes arrives through `forceErrorText`. Folding both into one
/// validator would leave the server's verdict with nowhere to go, because
/// nothing re-runs a validator that has already passed — and the request that
/// carried the complaint is the one that would have to trigger it.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _obscurePassword = true;
  bool _submitted = false;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _submitted = true);

    if (!(_formKey.currentState?.validate() ?? false)) return;

    // After this call there is nothing to do here: a success redirects via
    // the router, and a failure is already in `state` where the form draws it.
    await ref
        .read(authControllerProvider.notifier)
        .login(
          email: _emailController.text.trim(),
          password: _passwordController.text,
        );
  }

  void _onChanged(String _) {
    // Drop the previous attempt's explanation as soon as the user starts
    // changing the input it was about — otherwise "that address is not
    // valid" would keep sitting under a field it no longer describes.
    ref.read(authControllerProvider.notifier).dismissFeedback();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(authControllerProvider);
    final busy = state.isBusy;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                autovalidateMode: _submitted
                    ? AutovalidateMode.onUserInteraction
                    : AutovalidateMode.disabled,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'HRMS',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Sign in with your work account',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 24),
                    if (state.message.isNotEmpty) ...[
                      _FeedbackBanner(
                        message: state.message,
                        retryAfter: state.retryAfter,
                      ),
                      const SizedBox(height: 16),
                    ],
                    TextFormField(
                      controller: _emailController,
                      enabled: !busy,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.email],
                      textInputAction: TextInputAction.next,
                      onChanged: _onChanged,
                      decoration: const InputDecoration(labelText: 'Email'),
                      validator: _emailError,
                      // Server-reported, not validator-derived. A 422 arrives
                      // after `validate()` has already run, and nothing would
                      // re-run it — `forceErrorText` is the one hook that puts
                      // a fresh message under the input without waiting for
                      // the next keystroke to trigger one.
                      forceErrorText: state.errors['email'],
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _passwordController,
                      enabled: !busy,
                      obscureText: _obscurePassword,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.done,
                      onChanged: _onChanged,
                      onFieldSubmitted: (_) => _submit(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        suffixIcon: IconButton(
                          tooltip: _obscurePassword
                              ? 'Show password'
                              : 'Hide password',
                          icon: Icon(
                            _obscurePassword
                                ? Icons.visibility
                                : Icons.visibility_off,
                          ),
                          onPressed: busy
                              ? null
                              : () => setState(
                                  () => _obscurePassword = !_obscurePassword,
                                ),
                        ),
                      ),
                      validator: _passwordError,
                      forceErrorText: state.errors['password'],
                    ),
                    const SizedBox(height: 24),
                    FilledButton(
                      onPressed: busy ? null : _submit,
                      child: busy
                          ? const SizedBox(
                              height: 20,
                              width: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Sign in'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// Local checks only. Anything the API disagreed about arrives separately
  /// through `forceErrorText`, which is what actually overrides this — the
  /// two never have to race for the same slot.
  ///
  /// Deliberately looser than the server's `email:rfc`: a check that rejected
  /// what the API would accept would block the request that was supposed to
  /// explain itself, and the user would never see the reason.
  String? _emailError(String? value) {
    final email = value?.trim() ?? '';
    if (email.isEmpty) return 'Enter your email address.';
    if (!email.contains('@') || email.contains(' ')) {
      return 'Enter a valid email address.';
    }

    return null;
  }

  String? _passwordError(String? value) {
    if ((value ?? '').isEmpty) return 'Enter your password.';

    return null;
  }
}

class _FeedbackBanner extends StatelessWidget {
  const _FeedbackBanner({required this.message, this.retryAfter});

  final String message;
  final Duration? retryAfter;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final text = retryAfter == null
        ? message
        : '$message You can try again in ${retryAfter!.inSeconds}s.';

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.error_outline, size: 20, color: scheme.onErrorContainer),
          const SizedBox(width: 8),
          Expanded(
            child: Text(text, style: TextStyle(color: scheme.onErrorContainer)),
          ),
        ],
      ),
    );
  }
}
