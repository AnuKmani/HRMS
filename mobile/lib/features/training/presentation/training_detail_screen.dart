import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/pdf_opener.dart';
import '../../../core/presentation/status_chip.dart';
import '../data/api_training_repository.dart';
import '../domain/employee_training.dart';
import '../domain/training_repository.dart';
import 'training_controller.dart';
import 'training_sheets.dart';

/// One enrolment, everything the server knows about it, and the actions this
/// session may attempt.
///
/// Four things this screen is careful about:
///
///  - **the card is opened by id, never by link.** The payload carries a
///    `certificate_file_url` that points at an authenticated API route, and
///    this screen does not render it: it fetches the bytes through the
///    repository and hands them to the viewer. Nothing here could be copied
///    into a browser and left there, which is the whole point of the private
///    store the certificate shares with every other document in this app.
///
///  - **status and certificate state are printed as two chips, in words.**
///    "Completed" and "Expires in 5 days" are different facts from
///    different sources — one is a decision somebody made, the other is date
///    arithmetic the server recomputes on every read — and neither is ever a
///    colour alone.
///
///  - **the actions are gated by their own permissions, not by one.**
///    Recording a pass is `training.complete`, cancelling the seat is
///    `training.update`, and reading the card is the policy's own answer
///    about *whose* it is. A desk that may put people on a course is not by
///    that fact handed the ability to sign one off.
///
///  - **a refusal stays on screen.** The server answers "already completed"
///    with a 409, not a 403: "you may not" and "that already happened" are
///    different sentences and only one says what to do next.
class TrainingDetailScreen extends ConsumerStatefulWidget {
  const TrainingDetailScreen({super.key, required this.trainingId});

  final int trainingId;

  @override
  ConsumerState<TrainingDetailScreen> createState() =>
      _TrainingDetailScreenState();
}

class _TrainingDetailScreenState extends ConsumerState<TrainingDetailScreen> {
  EmployeeTraining? _training;
  bool _loading = false;
  bool _busy = false;
  bool _forbidden = false;

  String? _banner;
  Uint8List? _imageBytes;

  TrainingRepository get _repository => ref.read(trainingRepositoryProvider);

  @override
  void initState() {
    super.initState();

    // Gated before the fetch, not just before the build: a URL typed by
    // hand would otherwise ask the API for a record the session was never
    // going to be shown, and the answer to that question is a 403 with
    // nothing to do about it.
    if (ref.read(permissionScopeProvider).canViewTraining) _load();
  }

  @override
  void didUpdateWidget(covariant TrainingDetailScreen oldWidget) {
    super.didUpdateWidget(oldWidget);

    // The State survives a route that lands back on this screen with a
    // different id — an edit pushed and popped, or a list that navigated
    // sideways — and without this the screen would keep describing the row
    // it is no longer showing.
    if (oldWidget.trainingId != widget.trainingId) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final training = await _repository.find(widget.trainingId);
      if (!mounted) return;

      setState(() {
        _training = training;
        _loading = false;
        _forbidden = false;
      });

      _previewIfImage(training);
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _forbidden = failure is ApiException && failure.statusCode == 403;
        _banner = _messageFor(failure);
      });
    }
  }

  /// An image card is drawn inline; anything else is opened on a tap.
  Future<void> _previewIfImage(EmployeeTraining training) async {
    if (!training.hasCertificate || !training.isImage) return;

    try {
      final bytes = await _repository.file(training.id);
      if (!mounted) return;
      setState(() => _imageBytes = bytes);
    } catch (_) {
      // A preview is a courtesy. The row — its status, dates and the
      // "Open" button below — is the answer to the question this screen is
      // asked, and one that failed to draw a thumbnail must not paint an
      // error over it.
      if (mounted) setState(() => _imageBytes = null);
    }
  }

  Future<void> _openFile() async {
    final training = _training;
    if (training == null || _busy) return;

    if (_imageBytes != null) {
      await _showImage(training, _imageBytes!);
      return;
    }

    setState(() => _busy = true);

    try {
      final bytes = await _repository.file(training.id);
      if (!mounted) return;

      if (training.isImage) {
        await _showImage(training, bytes);
      } else {
        // Bytes in, a viewer out. There is no URL to keep and no file to
        // cache: `PdfOpener` writes into the app's own sandbox and hands it
        // straight to the OS, so nothing outlives the request.
        await ref
            .read(pdfOpenerProvider)
            .openBytes(bytes, training.certificateOriginalName ?? 'card.pdf');
      }
    } catch (failure) {
      if (mounted) setState(() => _banner = _messageFor(failure));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showImage(EmployeeTraining training, Uint8List bytes) {
    return showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(child: InteractiveViewer(child: Image.memory(bytes))),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _recordCompletion() async {
    final training = _training;
    if (training == null || _busy) return;

    final result = await showTrainingCompletionSheet(
      context: context,
      training: training,
    );

    if (result == null || !mounted) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      await _repository.complete(
        training.id,
        result.body,
        file: result.file?.bytes,
        filename: result.file?.filename,
      );

      ref.read(trainingListProvider.notifier).reload();
      ref.read(trainingExpiryProvider.notifier).reload();

      await _load();
    } on ApiException catch (failure) {
      if (mounted) setState(() => _banner = _messageFor(failure));
    } catch (_) {
      if (mounted) {
        setState(() => _banner = 'Something went wrong. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancelEnrolment() async {
    final training = _training;
    if (training == null || _busy) return;

    final remarks = await showTrainingCancelSheet(context: context);
    if (remarks == null || !mounted) return;

    setState(() {
      _busy = true;
      _banner = null;
    });

    try {
      await _repository.cancel(training.id, remarks: remarks);

      ref.read(trainingListProvider.notifier).reload();
      ref.read(trainingExpiryProvider.notifier).reload();

      await _load();
    } on ApiException catch (failure) {
      if (mounted) setState(() => _banner = _messageFor(failure));
    } catch (_) {
      if (mounted) {
        setState(() => _banner = 'Something went wrong. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(permissionScopeProvider.select((s) => s.canViewTraining))) {
      return Scaffold(
        appBar: AppBar(title: const Text('Training record')),
        body: const NoPermission(module: 'training records'),
      );
    }

    final scope = ref.watch(permissionScopeProvider);
    final training = _training;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Training record'),
        actions: [
          if (training != null && training.hasCertificate)
            IconButton(
              key: const ValueKey('open-certificate'),
              tooltip: 'Open certificate',
              icon: const Icon(Icons.badge_outlined),
              onPressed: _busy ? null : _openFile,
            ),
        ],
      ),
      body: _loading && training == null
          ? const Center(
              child: CircularProgressIndicator(
                key: ValueKey('training-detail-loading'),
              ),
            )
          : training == null
          ? _failed()
          : RefreshIndicator(onRefresh: _load, child: _body(training, scope)),
    );
  }

  Widget _failed() => Center(
    child: Padding(
      padding: const EdgeInsets.all(32),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.error_outline,
            size: 48,
            color: Theme.of(context).hintColor,
          ),
          const SizedBox(height: 12),
          Text(
            _forbidden
                ? 'You may not open this training record.'
                : (_banner ?? 'That training record could not be loaded.'),
            key: const ValueKey('training-detail-error'),
            textAlign: TextAlign.center,
          ),
          if (!_forbidden) ...[
            const SizedBox(height: 12),
            FilledButton(
              key: const ValueKey('training-detail-retry'),
              onPressed: _load,
              child: const Text('Try again'),
            ),
          ],
        ],
      ),
    ),
  );

  Widget _body(EmployeeTraining training, PermissionScope scope) {
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('training-detail-body'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: [
        if (_banner != null)
          Padding(
            key: const ValueKey('training-detail-banner'),
            padding: const EdgeInsets.only(bottom: 12),
            child: Material(
              color: theme.colorScheme.errorContainer,
              borderRadius: BorderRadius.circular(8),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(
                  _banner!,
                  style: TextStyle(color: theme.colorScheme.onErrorContainer),
                ),
              ),
            ),
          ),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  training.programName.isNotEmpty
                      ? training.programName
                      : 'Training ${training.id}',
                  key: const ValueKey('training-detail-title'),
                  style: theme.textTheme.titleLarge,
                ),
                const SizedBox(height: 6),
                Text(
                  [
                    if (training.programCode.isNotEmpty) training.programCode,
                    if (training.typeName.isNotEmpty) training.typeName,
                  ].join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    StatusChip(
                      label: training.statusText,
                      tone: training.statusTone,
                    ),
                    // Two chips, two sources: the stored decision and the
                    // server's date arithmetic, side by side rather than
                    // one standing in for the other.
                    if (training.hasCertificate) ...[
                      const SizedBox(width: 8),
                      StatusChip(
                        label: training.expiryText,
                        tone: training.expiryTone,
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        _section(
          theme,
          title: 'Person and dates',
          rows: [
            ('Who', training.employeeName ?? 'You'),
            if ((training.enrollmentDate ?? '').isNotEmpty)
              ('Enrolled on', training.enrollmentDate!),
            if ((training.trainingDate ?? '').isNotEmpty)
              ('Course runs on', training.trainingDate!),
            if ((training.completionDate ?? '').isNotEmpty)
              ('Completed on', training.completionDate!),
            (
              'Trainer',
              (training.trainer ?? '').isNotEmpty
                  ? training.trainer!
                  : 'Not recorded',
            ),
          ],
        ),
        const SizedBox(height: 16),
        if (training.hasCertificate)
          _section(
            theme,
            title: 'Certificate',
            rows: [
              if ((training.certificateNumber ?? '').isNotEmpty)
                ('Number', training.certificateNumber!),
              if ((training.certificateIssueDate ?? '').isNotEmpty)
                ('Issued', training.certificateIssueDate!),
              if ((training.certificateExpiryDate ?? '').isNotEmpty)
                ('Expires', training.certificateExpiryDate!),
              (
                'File',
                '${training.certificateOriginalName ?? 'card'} · ${training.sizeLabel}',
              ),
              ('State', training.expiryText),
            ],
            trailing: TextButton.icon(
              key: const ValueKey('download-certificate'),
              icon: const Icon(Icons.download_outlined),
              label: const Text('Open'),
              onPressed: _busy ? null : _openFile,
            ),
          )
        else if (training.isCompleted)
          _section(
            theme,
            title: 'Certificate',
            rows: const [('File', 'No certificate was uploaded')],
          ),
        if ((training.remarks ?? '').isNotEmpty) ...[
          const SizedBox(height: 16),
          _section(theme, title: 'Notes', rows: [('Notes', training.remarks!)]),
        ],
        if (scope.canCompleteTraining || scope.canEditEnrolments) ...[
          const SizedBox(height: 20),
          if (training.isCompletable)
            FilledButton.icon(
              key: const ValueKey('complete-training'),
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('Record completion'),
              onPressed: _busy ? null : _recordCompletion,
            ),
          if (!training.isTerminal &&
              training.status != EmployeeTraining.statusCancelled) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              key: const ValueKey('cancel-training'),
              icon: const Icon(Icons.block_outlined),
              label: const Text('Cancel this enrolment'),
              onPressed: _busy || !scope.canEditEnrolments
                  ? null
                  : _cancelEnrolment,
            ),
          ],
        ],
      ],
    );
  }

  Widget _section(
    ThemeData theme, {
    required String title,
    required List<(String, String)> rows,
    Widget? trailing,
  }) => Card(
    margin: EdgeInsets.zero,
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(title, style: theme.textTheme.titleMedium)),
              ?trailing,
            ],
          ),
          const SizedBox(height: 10),
          for (final (label, value) in rows) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 132,
                    child: Text(
                      label,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.hintColor,
                      ),
                    ),
                  ),
                  Expanded(child: Text(value)),
                ],
              ),
            ),
          ],
        ],
      ),
    ),
  );

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}
