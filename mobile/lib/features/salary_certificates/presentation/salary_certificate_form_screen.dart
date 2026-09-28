import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/form_controls.dart';
import '../data/api_salary_certificate_repository.dart';

/// Asking for a salary certificate.
///
/// Two fields, and the smallness is the point — the API's own docblock says
/// the same. `purpose` is required because a certificate issued "for
/// whatever they want" is not a document anybody can audit afterwards, and
/// there is **no employee field**: the server defaults it to the caller's
/// own record, the same way the loan form does not ask who is borrowing. An
/// HR desk filing for a colleague does so with an explicit `employee_id`,
/// which this screen does not yet offer — see the note in `FLUTTER_GUIDE`.
class SalaryCertificateFormScreen extends ConsumerStatefulWidget {
  const SalaryCertificateFormScreen({super.key});

  @override
  ConsumerState<SalaryCertificateFormScreen> createState() =>
      _SalaryCertificateFormScreenState();
}

class _SalaryCertificateFormScreenState
    extends ConsumerState<SalaryCertificateFormScreen> {
  final _purpose = TextEditingController();
  final _date = TextEditingController();

  Map<String, String> _fieldErrors = const <String, String>{};
  String? _banner;
  bool _forbidden = false;
  bool _saving = false;

  @override
  void dispose() {
    _purpose.dispose();
    _date.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _fieldErrors = const <String, String>{};
      _banner = null;
      _forbidden = false;
      _saving = true;
    });

    try {
      final request = await ref
          .read(salaryCertificateRepositoryProvider)
          .create(<String, Object?>{
            'purpose': _purpose.text.trim(),
            if (_date.text.trim().isNotEmpty) 'request_date': _date.text.trim(),
          });

      if (!mounted) return;

      // Straight to the row: the reference number it was just given, and the
      // pending status, are the two things a person asks for next.
      context.go('/salary-certificates/${request.id}');
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
    final canAsk = ref.watch(
      permissionScopeProvider.select(
        (scope) => scope.canViewSalaryCertificates,
      ),
    );

    if (!canAsk) {
      return Scaffold(
        appBar: AppBar(title: const Text('New certificate request')),
        body: const Center(child: Text('You may not ask for a certificate.')),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('New certificate request')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_banner != null)
            FormBanner(message: _banner!, forbidden: _forbidden),
          LabeledTextField(
            key: const ValueKey('certificate-purpose'),
            label: 'What is it for?',
            controller: _purpose,
            isRequired: true,
            maxLines: 3,
            errorText: _fieldErrors['purpose'],
            hint: 'Housing loan with National Bank',
            helper:
                'Name the bank, landlord or authority — the certificate is '
                'a record of who asked for it and why.',
          ),
          DateField(
            key: const ValueKey('certificate-date'),
            label: 'Requested on',
            controller: _date,
            errorText: _fieldErrors['request_date'],
            onChanged: (_) => setState(() {}),
          ),
          // DateField carries no helper of its own — it is shared with the
          // master-record forms, where "defaults to today" would be a lie —
          // so the one sentence this screen wants below it is drawn here.
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 16),
            child: Text(
              'Leave this alone to ask for it today.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          const SizedBox(height: 8),
          FilledButton(
            key: const ValueKey('certificate-submit'),
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Request'),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
