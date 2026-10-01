import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/remote_picker.dart';
import '../../employees/domain/employee.dart';
import '../../employees/presentation/employees_controller.dart';
import '../data/api_document_repository.dart';
import '../data/document_source.dart';
import '../domain/document_repository.dart';
import '../domain/document_type.dart';
import '../domain/employee_document.dart';
import 'document_source_sheet.dart';
import 'documents_controller.dart';

/// Upload an employment document — or correct the details of one.
///
/// [documentId] null means create.
///
/// Three things this form is careful about:
///
///  - **every requirement it enforces comes from the chosen type.** Whether
///    a number, an issue date or an expiry is expected is read from
///    `document_types`, never from a name this screen matched on, so a
///    deployment that adds its own kind of document gets a working form
///    without a code change here.
///
///  - **the file never touches disk.** Bytes come from the camera, the
///    gallery or a picker and go straight to the request; there is no
///    temporary file, no cached copy and no path in any payload.
///
///  - **the client's checks are a courtesy, not the rule.** The server
///    re-runs every one of them — MIME, extension, byte content, size, date
///    order — and its 422 is what actually decides. Catching the obvious
///    ones here is so a person is told *before* a 10 MB upload finishes,
///    not instead of being refused.
class DocumentFormScreen extends ConsumerStatefulWidget {
  const DocumentFormScreen({
    super.key,
    this.documentId,
    this.employeeId,
    this.typeCode,
  });

  final int? documentId;

  /// Who the file is being filed for. Absent means "me", which is what the
  /// API does with an absent `employee_id` — and the only thing a session
  /// without `documents.manage` may ask for.
  final int? employeeId;

  /// The requirement a caller arrived from — `passport`, `emirates_id` —
  /// used to preselect the matching document type.
  ///
  /// Matched **by code**, which is the only thing a checklist row carries and
  /// the only thing the two vocabularies are agreed to share. Nothing here
  /// knows what a passport *is*: if the code matches no active type the
  /// picker simply opens on "Not set", and the person chooses. A hard-coded
  /// assumption that `passport` exists would be the exact rule-as-data
  /// violation scope item A exists to prevent.
  final String? typeCode;

  @override
  ConsumerState<DocumentFormScreen> createState() => _DocumentFormScreenState();
}

class _DocumentFormScreenState extends ConsumerState<DocumentFormScreen> {
  late final TextEditingController _number;
  late final TextEditingController _issue;
  late final TextEditingController _expiry;
  late final TextEditingController _notes;

  int? _typeId;
  String? _typeName;
  DocumentType? _type;

  int? _employeeId;
  String? _employeeName;

  PickedDocument? _file;
  EmployeeDocument? _existing;

  bool _loading = false;
  bool _saving = false;
  bool _forbidden = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.documentId == null;

  DocumentRepository get _repository => ref.read(documentRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _number = TextEditingController();
    _issue = TextEditingController();
    _expiry = TextEditingController();
    _notes = TextEditingController();
    _employeeId = widget.employeeId;

    // Gated before the fetch for the same reason the detail screen is: a URL
    // typed by hand should be refused at the door rather than answered by a
    // request the API will only turn into a 403.
    final allowed = _isCreate
        ? ref.read(permissionScopeProvider).canCreateDocuments
        : ref.read(permissionScopeProvider).canWriteDocuments;

    if (!allowed) return;

    if (!_isCreate) _load();

    if (_isCreate) _applyTypeCode();
  }

  /// Preselects the type a checklist row pointed at, if such a type exists.
  ///
  /// Failure here is silent on purpose: the form still works, the picker
  /// still offers every active type, and a person who cannot find theirs
  /// picks it by name. A banner saying "that document type does not exist"
  /// would be reporting a detail about *configuration* to somebody who only
  /// wanted to attach a file.
  Future<void> _applyTypeCode() async {
    final wanted = widget.typeCode;
    if (wanted == null || wanted.isEmpty) return;

    try {
      final types = await _repository.types();
      if (!mounted) return;

      for (final type in types) {
        if (type.code.toLowerCase() != wanted.toLowerCase()) continue;

        setState(() {
          _typeId = type.id;
          _type = type;
          _typeName = type.name;
        });

        return;
      }
    } catch (_) {
      // The picker below is the fallback, and it is not affected.
    }
  }

  @override
  void dispose() {
    _number.dispose();
    _issue.dispose();
    _expiry.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final document = await _repository.find(widget.documentId!);
      if (!mounted) return;

      setState(() {
        _existing = document;
        _typeId = document.documentTypeId;
        _type = document.documentType;
        _typeName = document.documentType?.name;
        _employeeId = document.employeeId;
        _employeeName = document.employeeName;
        _number.text = document.documentNumber ?? '';
        _issue.text = document.issueDate ?? '';
        _expiry.text = document.expiryDate ?? '';
        _notes.text = document.notes ?? '';
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;
      setState(() {
        _banner = _messageFor(failure);
        _loading = false;
      });
    }
  }

  Future<void> _chooseFile() async {
    final chosen = await DocumentSourceSheet.show(context);
    if (chosen == null || !mounted) return;

    setState(() {
      _file = chosen;
      _errors = Map<String, String>.of(_errors)..remove('file');
    });
  }

  /// Everything this form can decide for itself, before a byte is sent.
  ///
  /// Deliberately a *subset* of the server's rules: the ones that need a
  /// database row (is this type active?) or a byte sniff (is this really a
  /// PDF?) are not re-implemented here, because a client that guessed them
  /// would be a second, weaker answer to the same question.
  Map<String, String> _validate({required bool needsFile}) {
    final errors = <String, String>{};

    if (_typeId == null) {
      errors['document_type_id'] = 'Choose what kind of document this is.';
    }

    final type = _type;

    if (type != null) {
      if (type.requiresDocumentNumber && _number.text.trim().isEmpty) {
        errors['document_number'] =
            'This document type requires a document number.';
      }
      if (type.requiresIssueDate && _issue.text.trim().isEmpty) {
        errors['issue_date'] = 'This document type requires an issue date.';
      }
      if (type.requiresExpiryDate && _expiry.text.trim().isEmpty) {
        errors['expiry_date'] = 'This document type requires an expiry date.';
      }
    }

    final issue = _issue.text.trim();
    final expiry = _expiry.text.trim();

    if (issue.isNotEmpty && _isInTheFuture(issue)) {
      errors['issue_date'] = 'An issue date cannot be in the future.';
    }

    if (issue.isNotEmpty &&
        expiry.isNotEmpty &&
        !_expiresAfter(issue, expiry)) {
      errors['expiry_date'] = 'The expiry date must be after the issue date.';
    }

    if (needsFile && _file == null) {
      errors['file'] = 'Attach the document itself.';
    }

    final file = _file;

    if (file != null && !_allowedExtension(file.filename)) {
      errors['file'] = 'Attach a PDF, JPG, PNG or WebP.';
    } else if (file != null && file.bytes.lengthInBytes > maxDocumentBytes) {
      errors['file'] =
          'That file is larger than the '
          '${(maxDocumentBytes ~/ (1024 * 1024))} MB limit.';
    }

    return errors;
  }

  DocumentType? _typeFromPicker(int? id) {
    if (id == null) return null;

    final items = ref.read(documentTypesPickerProvider).items;

    for (final type in items) {
      if (type.id == id) return type;
    }

    return null;
  }

  Future<void> _save() async {
    if (_saving) return;

    final needsFile = _isCreate && _existing?.hasFile != true;
    final errors = _validate(needsFile: needsFile);

    if (errors.isNotEmpty) {
      setState(() {
        _errors = errors;
        _banner = null;
      });
      return;
    }

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const <String, String>{};
    });

    final body = <String, Object?>{
      if (_isCreate) ...{
        'document_type_id': _typeId,
        // Only offered to a session holding `documents.manage`, and only
        // then sent: an absent `employee_id` means "me" to the API, so a
        // person filing their own passport never has to know their id.
        if (_employeeId != null) 'employee_id': _employeeId,
      },
      'document_number': _number.text.trim(),
      'issue_date': _issue.text.trim(),
      'expiry_date': _expiry.text.trim(),
      'notes': _notes.text.trim(),
    };

    final file = _file;

    try {
      if (_isCreate) {
        await _repository.create(
          body,
          file: file?.bytes,
          filename: file?.filename,
        );
      } else {
        await _repository.update(
          widget.documentId!,
          body,
          file: file?.bytes,
          filename: file?.filename,
        );
      }

      ref.read(documentListProvider.notifier).reload();
      ref.read(documentExpiryProvider.notifier).reload();
      if (!mounted) return;
      context.go('/documents');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _forbidden = failure.statusCode == 403;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
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

    if (_isCreate && !scope.canCreateDocuments) {
      return Scaffold(
        appBar: AppBar(title: const Text('Upload document')),
        body: const NoPermission(module: 'document upload'),
      );
    }

    if (!_isCreate && !scope.canWriteDocuments) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit document')),
        body: const NoPermission(module: 'document editing'),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(_isCreate ? 'Upload document' : 'Edit document'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('document-form-banner'),
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Material(
                        color: Theme.of(context).colorScheme.errorContainer,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Text(
                            _banner!,
                            style: TextStyle(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onErrorContainer,
                            ),
                          ),
                        ),
                      ),
                    ),
                  if (_isCreate) ...[
                    if (scope.canManageDocuments)
                      RemotePickerField<Employee>(
                        key: const ValueKey('document-employee'),
                        label: 'Whose document',
                        provider: employeesPickerProvider,
                        idOf: (employee) => employee.id,
                        labelOf: (employee) => employee.fullName,
                        value: _employeeId,
                        selectedLabel: _employeeName,
                        hint: 'Me',
                        searchHint: 'Search people',
                        errorText: _errors['employee_id'],
                        onChanged: (id) => setState(() => _employeeId = id),
                      ),
                    RemotePickerField<DocumentType>(
                      key: const ValueKey('document-type'),
                      label: 'Document type',
                      provider: documentTypesPickerProvider,
                      idOf: (type) => type.id,
                      labelOf: (type) => type.name,
                      value: _typeId,
                      selectedLabel: _typeName,
                      isRequired: true,
                      searchHint: 'Search document types',
                      helper: _type == null ? null : requirementsLabel(_type!),
                      errorText: _errors['document_type_id'],
                      // The row, not just its id: everything the form then
                      // decides (does this need a number? an expiry?) is read
                      // off it, and re-asking the API for the list on every
                      // change would buy nothing — the picker has already
                      // loaded the vocabulary to offer the choice.
                      onChanged: (id) => setState(() {
                        _typeId = id;
                        _type = _typeFromPicker(id);
                        _typeName = _type?.name;
                        // The three date/number rules belong to the type that
                        // was just chosen, so the errors explaining the old
                        // one are cleared rather than left to contradict the
                        // newly labelled fields.
                        _errors = Map<String, String>.of(_errors)
                          ..remove('document_type_id')
                          ..remove('document_number')
                          ..remove('issue_date')
                          ..remove('expiry_date');
                      }),
                    ),
                  ] else if (_typeName != null) ...[
                    _readOnlyType(context),
                  ],
                  LabeledTextField(
                    key: const ValueKey('document-number'),
                    label: 'Document number',
                    controller: _number,
                    isRequired: _type?.requiresDocumentNumber ?? false,
                    hint: 'Optional',
                    errorText: _errors['document_number'],
                  ),
                  DateField(
                    key: const ValueKey('document-issue'),
                    label: 'Issue date',
                    controller: _issue,
                    isRequired: _type?.requiresIssueDate ?? false,
                    allowEmpty: true,
                    errorText: _errors['issue_date'],
                  ),
                  DateField(
                    key: const ValueKey('document-expiry'),
                    label: 'Expiry date',
                    controller: _expiry,
                    isRequired: _type?.requiresExpiryDate ?? false,
                    allowEmpty: true,
                    errorText: _errors['expiry_date'],
                  ),
                  // `DateField` has no helper line of its own, and this one
                  // is worth saying: "expiring soon" means different things
                  // for a passport and a labour card, and a person should
                  // learn that before the date is chosen rather than from a
                  // chip afterwards.
                  if (_expiryHint() != null)
                    Padding(
                      key: const ValueKey('document-expiry-hint'),
                      padding: const EdgeInsets.only(top: 4, bottom: 8),
                      child: Text(
                        _expiryHint()!,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  LabeledTextField(
                    key: const ValueKey('document-notes'),
                    label: 'Notes',
                    controller: _notes,
                    maxLines: 3,
                    hint: 'Optional',
                    errorText: _errors['notes'],
                  ),
                  const SizedBox(height: 8),
                  _fileTile(context),
                  const SizedBox(height: 16),
                  FilledButton(
                    key: const ValueKey('save-document'),
                    onPressed: _saving || _forbidden ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(_isCreate ? 'Upload' : 'Save changes'),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _readOnlyType(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(
      children: [
        Icon(_type == null ? Icons.folder_outlined : Icons.task_alt, size: 20),
        const SizedBox(width: 8),
        Expanded(child: Text(_typeName ?? '')),
      ],
    ),
  );

  String? _expiryHint() {
    final type = _type;

    if (type == null || !type.requiresExpiryDate) return null;

    return 'This type warns ${warningLabelFor(type.expiryWarningDays)} before '
        'the date.';
  }

  Widget _fileTile(BuildContext context) {
    final theme = Theme.of(context);
    final chosen = _file;
    final keepExisting = !_isCreate && (_existing?.hasFile ?? false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedButton.icon(
          key: const ValueKey('choose-document-file'),
          onPressed: _chooseFile,
          icon: const Icon(Icons.attach_file_outlined),
          label: Text(
            chosen == null
                ? (keepExisting ? 'Replace the attached file' : 'Attach a file')
                : chosen.filename,
          ),
        ),
        if (chosen != null)
          Padding(
            key: const ValueKey('document-file-chosen'),
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              '${chosen.isPdf ? 'PDF' : 'Image'} · '
              '${(chosen.bytes.lengthInBytes / (1024 * 1024)).toStringAsFixed(2)} MB',
              style: theme.textTheme.bodySmall,
            ),
          ),
        if (chosen == null && keepExisting)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'The file already on this record is kept unless you replace it.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        if (_errors.containsKey('file'))
          Padding(
            key: const ValueKey('document-file-error'),
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _errors['file']!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
      ],
    );
  }

  static bool _allowedExtension(String filename) {
    final lower = filename.toLowerCase();

    return lower.endsWith('.pdf') ||
        lower.endsWith('.jpg') ||
        lower.endsWith('.jpeg') ||
        lower.endsWith('.png') ||
        lower.endsWith('.webp');
  }

  static bool _isInTheFuture(String isoDate) {
    final parsed = DateTime.tryParse(isoDate);
    if (parsed == null) return false;

    final today = DateTime.now();

    return parsed.isAfter(DateTime(today.year, today.month, today.day));
  }

  /// Strictly-after, and *unknown counts as fine*.
  ///
  /// Both dates come out of `DateField` as ISO, so a parse failure means the
  /// field was cleared rather than mistyped — and an empty field already had
  /// its own rule above. Refusing on a date nobody could read would name the
  /// wrong problem.
  static bool _expiresAfter(String issue, String expiry) {
    final issued = DateTime.tryParse(issue);
    final expires = DateTime.tryParse(expiry);

    if (issued == null || expires == null) return true;

    return expires.isAfter(issued);
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}

/// The server's own ceiling, echoed so a person is told *before* a huge
/// upload rather than after it.
///
/// `hrms.storage.document_max_kilobytes`, whose default is 10240. The API's
/// answer is the one that decides — this is a courtesy, not the boundary,
/// and it is deliberately not used anywhere else in the app.
const int maxDocumentBytes = 10 * 1024 * 1024;
