import 'package:flutter/material.dart';

import '../../../core/presentation/fields.dart';
import '../../documents/data/document_source.dart';
import '../../documents/presentation/document_source_sheet.dart';
import '../domain/employee_training.dart';

/// What the completion sheet decided: the fields, plus the bytes if there
/// were any.
///
/// Returned as a pair rather than fired straight into the repository
/// because the sheet must not know whether the request will be retried —
/// the detail screen owns the busy flag, the banner and the reload, and a
/// sheet that had already sent its own would leave a second Save button
/// pressing against a row that had already moved.
class TrainingCompletion {
  const TrainingCompletion({required this.body, this.file});

  final Map<String, Object?> body;
  final PickedDocument? file;
}

/// Record a pass — the certificate, its dates, and the file itself.
///
/// A sheet rather than a route because it is one act on one row: nothing
/// here can be navigated away from and come back to, and the whole point of
/// the screen underneath is that it will show what this decided.
///
/// Two things the form is careful about:
///
///  - **the expiry is left empty unless somebody typed one.** When it is,
///    the server dates the card from the program's own
///    `certificate_validity_days`; when it is not, the card never lapses.
///    Both are real answers, and a client that filled the box in with a
///    number would be inventing a rule the operator never configured.
///
///  - **a course that promises a card demands one.** That rule is the
///    program's own flag rather than something this sheet guessed, and the
///    check here happens *before* an upload that was always going to be
///    refused with a 422.
Future<TrainingCompletion?> showTrainingCompletionSheet({
  required BuildContext context,
  required EmployeeTraining training,
}) => showModalBottomSheet<TrainingCompletion>(
  context: context,
  isScrollControlled: true,
  builder: (context) => _CompletionSheet(training: training),
);

Future<String?> showTrainingCancelSheet({required BuildContext context}) =>
    showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (context) => const _CancelSheet(),
    );

class _CompletionSheet extends StatefulWidget {
  const _CompletionSheet({required this.training});

  final EmployeeTraining training;

  @override
  State<_CompletionSheet> createState() => _CompletionSheetState();
}

class _CompletionSheetState extends State<_CompletionSheet> {
  late final TextEditingController _completedOn;
  late final TextEditingController _runsOn;
  late final TextEditingController _trainer;
  late final TextEditingController _result;
  late final TextEditingController _remarks;
  late final TextEditingController _number;
  late final TextEditingController _issued;
  late final TextEditingController _expires;

  PickedDocument? _file;
  bool _saving = false;
  String? _banner;
  String? _fileError;

  @override
  void initState() {
    super.initState();

    _completedOn = TextEditingController(text: _today());
    _runsOn = TextEditingController(text: widget.training.trainingDate ?? '');
    _trainer = TextEditingController(text: widget.training.trainer ?? '');
    _result = TextEditingController();
    _remarks = TextEditingController();
    _number = TextEditingController();
    _issued = TextEditingController(text: _today());
    // Deliberately empty. When it stays empty the server dates the card
    // from the program's own `certificate_validity_days` — which is the
    // arithmetic that belongs there, because a client computing it from
    // "now" would be a second, drifting answer to a question the request
    // already has the whole answer to. Typed into, it is the instructor's
    // own date and beats the default.
    _expires = TextEditingController();
  }

  static String _today() {
    final now = DateTime.now();
    return '${now.year.toString().padLeft(4, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.day.toString().padLeft(2, '0')}';
  }

  @override
  void dispose() {
    _completedOn.dispose();
    _runsOn.dispose();
    _trainer.dispose();
    _result.dispose();
    _remarks.dispose();
    _number.dispose();
    _issued.dispose();
    _expires.dispose();
    super.dispose();
  }

  Future<void> _pickFile() async {
    final chosen = await DocumentSourceSheet.show(context);
    if (chosen == null || !mounted) return;

    setState(() {
      _file = chosen;
      _fileError = null;
    });
  }

  void _save() {
    if (_saving) return;

    final completion = _completedOn.text.trim();
    final issued = _issued.text.trim();
    final expires = _expires.text.trim();

    // The two date orders the server also checks, caught here so a person
    // is told before a request that was always going to fail. The rest is
    // the server's — a client that mirrored every rule would be a second,
    // weaker answer to the same question.
    String? fileError;
    if (widget.training.certificateRequired && _file == null) {
      fileError =
          'This course issues a certificate, so the card has to be attached.';
    }

    String? expiresError;
    if (issued.isNotEmpty &&
        expires.isNotEmpty &&
        expires.compareTo(issued) < 0) {
      expiresError = 'The card cannot expire before it was issued.';
    }

    setState(() {
      _fileError = fileError;
      _banner = expiresError;
    });

    if (fileError != null || expiresError != null) return;

    setState(() => _saving = true);

    final body = <String, Object?>{
      if (completion.isNotEmpty) 'completion_date': completion,
      if (_runsOn.text.trim().isNotEmpty) 'training_date': _runsOn.text.trim(),
      if (_trainer.text.trim().isNotEmpty) 'trainer': _trainer.text.trim(),
      if (_result.text.trim().isNotEmpty) 'result': _result.text.trim(),
      if (_remarks.text.trim().isNotEmpty) 'remarks': _remarks.text.trim(),
      if (_number.text.trim().isNotEmpty)
        'certificate_number': _number.text.trim(),
      if (issued.isNotEmpty) 'certificate_issue_date': issued,
      if (expires.isNotEmpty) 'certificate_expiry_date': expires,
    };

    Navigator.of(context).pop(TrainingCompletion(body: body, file: _file));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.85,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      'Record completion',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  IconButton(
                    key: const ValueKey('close-completion'),
                    tooltip: 'Close',
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
            ),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_banner != null)
                      Padding(
                        key: const ValueKey('completion-banner'),
                        padding: const EdgeInsets.only(bottom: 16),
                        child: Material(
                          color: theme.colorScheme.errorContainer,
                          borderRadius: BorderRadius.circular(8),
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Text(
                              _banner!,
                              style: TextStyle(
                                color: theme.colorScheme.onErrorContainer,
                              ),
                            ),
                          ),
                        ),
                      ),
                    DateField(
                      key: const ValueKey('completion-date'),
                      label: 'Completed on',
                      controller: _completedOn,
                      allowEmpty: true,
                    ),
                    DateField(
                      key: const ValueKey('completion-runs-on'),
                      label: 'Course ran on',
                      controller: _runsOn,
                      allowEmpty: true,
                    ),
                    LabeledTextField(
                      key: const ValueKey('completion-trainer'),
                      label: 'Trainer',
                      controller: _trainer,
                      hint: 'Defaults to the course provider',
                    ),
                    LabeledTextField(
                      key: const ValueKey('completion-result'),
                      label: 'Result',
                      controller: _result,
                      hint: 'Pass',
                    ),
                    LabeledTextField(
                      key: const ValueKey('completion-number'),
                      label: 'Certificate number',
                      controller: _number,
                    ),
                    DateField(
                      key: const ValueKey('completion-issued'),
                      label: 'Certificate issued',
                      controller: _issued,
                      allowEmpty: true,
                    ),
                    DateField(
                      key: const ValueKey('completion-expires'),
                      label: 'Certificate expires',
                      controller: _expires,
                      allowEmpty: true,
                      helper:
                          'Leave empty and the card is dated from the '
                          "course's own validity. Type a date to override "
                          'it.',
                    ),
                    LabeledTextField(
                      key: const ValueKey('completion-remarks'),
                      label: 'Notes',
                      controller: _remarks,
                      maxLines: 3,
                    ),
                    const SizedBox(height: 4),
                    OutlinedButton.icon(
                      key: const ValueKey('attach-certificate'),
                      icon: const Icon(Icons.attach_file_outlined),
                      label: Text(
                        _file == null
                            ? (widget.training.certificateRequired
                                  ? 'Attach certificate *'
                                  : 'Attach certificate (optional)')
                            : _file!.filename,
                      ),
                      onPressed: _pickFile,
                    ),
                    if (_fileError != null)
                      Padding(
                        key: const ValueKey('completion-file-error'),
                        padding: const EdgeInsets.only(top: 6),
                        child: Text(
                          _fileError!,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.error,
                          ),
                        ),
                      ),
                    const SizedBox(height: 20),
                    FilledButton(
                      key: const ValueKey('save-completion'),
                      onPressed: _saving ? null : _save,
                      child: _saving
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Record completion'),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CancelSheet extends StatefulWidget {
  const _CancelSheet();

  @override
  State<_CancelSheet> createState() => _CancelSheetState();
}

class _CancelSheetState extends State<_CancelSheet> {
  late final TextEditingController _remarks;

  @override
  void initState() {
    super.initState();
    _remarks = TextEditingController();
  }

  @override
  void dispose() {
    _remarks.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 16,
          right: 16,
          top: 16,
          bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Cancel this enrolment', style: theme.textTheme.titleMedium),
            const SizedBox(height: 6),
            Text(
              'The seat goes away and the person is not put on the course. '
              'The row stays so the decision has a record of who made it.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            LabeledTextField(
              key: const ValueKey('cancel-remarks'),
              label: 'Why',
              controller: _remarks,
              maxLines: 3,
              hint: 'Optional',
            ),
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('confirm-cancel'),
              onPressed: () => Navigator.of(context).pop(_remarks.text.trim()),
              child: const Text('Cancel enrolment'),
            ),
          ],
        ),
      ),
    );
  }
}
