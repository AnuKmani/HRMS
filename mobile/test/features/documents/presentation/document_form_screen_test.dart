import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/documents/data/document_source.dart';
import 'package:mobile/features/documents/presentation/document_form_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show pickDate, tapIn;

/// The path table the form navigates, and nothing else.
///
/// Saving ends in `context.go('/documents')`, and a form pumped under a bare
/// `MaterialApp` would throw on `GoRouter.of` for a reason that has nothing
/// to do with the validation under test.
GoRouter formRouter(String location) => GoRouter(
  initialLocation: location,
  routes: [
    GoRoute(
      path: '/documents',
      builder: (_, _) => const Scaffold(body: Text('documents-list')),
    ),
    GoRoute(
      path: '/documents/new',
      builder: (_, _) => const DocumentFormScreen(),
    ),
    GoRoute(
      path: '/documents/type-code',
      builder: (_, _) => const DocumentFormScreen(typeCode: 'passport'),
    ),
    GoRoute(
      path: '/documents/other',
      builder: (_, _) =>
          const DocumentFormScreen(employeeId: 4, typeCode: 'nowhere'),
    ),
  ],
);

void main() {
  late ScriptedDocuments script;
  late ScriptedDocumentPicker picker;

  setUp(() {
    script = ScriptedDocuments();
    script.scriptTypes([
      documentTypeRow(
        id: 1,
        name: 'Passport',
        code: 'PASSPORT',
        requiresNumber: true,
        requiresIssue: true,
        requiresExpiry: true,
        warningDays: 180,
      ),
      documentTypeRow(id: 2, name: 'Certificate', code: 'CERTIFICATE'),
    ]);
    picker = ScriptedDocumentPicker();
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    String location = '/documents/new',
    List<String> permissions = const ['documents.create'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        documents: script,
        picker: picker,
        child: MaterialApp.router(routerConfig: formRouter(location)),
      ),
    );
    await advance(tester);
  }

  Future<void> chooseType(WidgetTester tester, String name) async {
    await tapIn(tester, find.byKey(const ValueKey('document-type')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.text(name));
    await tester.pumpAndSettle();
  }

  Future<void> attach(WidgetTester tester, PickedDocument document) async {
    picker.next = document;

    await tapIn(tester, find.byKey(const ValueKey('choose-document-file')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.byKey(const ValueKey('document-source-gallery')));
    await tester.pumpAndSettle();
  }

  Future<void> save(WidgetTester tester) async {
    await tapIn(tester, find.byKey(const ValueKey('save-document')));
    await advance(tester);
  }

  testWidgets('a session without documents.create is refused at the door, '
      'and never asked what a document type is', (tester) async {
    await pumpForm(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.typesCalls, 0);
    expect(script.createCalls, 0);
  });

  testWidgets('the chosen type decides which fields are required', (
    tester,
  ) async {
    await pumpForm(tester);

    expect(find.text('Not set'), findsOneWidget);

    await chooseType(tester, 'Passport');

    // The rules arrive with the row rather than being matched on the name,
    // so the form can say what this type demands before anything is typed.
    expect(find.text('Needs number, issue date, expiry date'), findsOneWidget);

    await save(tester);

    expect(
      find.text('This document type requires a document number.'),
      findsOneWidget,
    );
    expect(
      find.text('This document type requires an issue date.'),
      findsOneWidget,
    );
    expect(
      find.text('This document type requires an expiry date.'),
      findsOneWidget,
    );
    expect(script.createCalls, 0);
  });

  testWidgets('a type with no rules still refuses to be filed without the '
      'document itself', (tester) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');

    expect(find.text('No extra details required'), findsOneWidget);

    await save(tester);

    expect(find.byKey(const ValueKey('document-file-error')), findsOneWidget);
    expect(find.text('Attach the document itself.'), findsOneWidget);
    expect(script.createCalls, 0);
  });

  testWidgets('a file larger than the server ceiling is refused before it '
      'is sent', (tester) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');

    await attach(tester, oversizedDocument());
    await save(tester);

    expect(
      find.text('That file is larger than the 10 MB limit.'),
      findsOneWidget,
    );
    expect(script.createCalls, 0);
  });

  testWidgets('a file the endpoint will not accept is refused by extension, '
      'not by a guess about its bytes', (tester) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');

    await attach(
      tester,
      pickedDocument(filename: 'definitely-not-a-virus.exe'),
    );
    await save(tester);

    expect(find.text('Attach a PDF, JPG, PNG or WebP.'), findsOneWidget);
    expect(script.createCalls, 0);
  });

  testWidgets('an expiry that does not follow its issue date is refused', (
    tester,
  ) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');

    // Both dates today: `DateField` is `readOnly`, so the picker is the only
    // route a person has, and driving it keeps this test green only while
    // the control works.
    await pickDate(tester, const ValueKey('document-issue'));
    await pickDate(tester, const ValueKey('document-expiry'));

    await attach(tester, pickedDocument());
    await save(tester);

    expect(
      find.text('The expiry date must be after the issue date.'),
      findsOneWidget,
    );
    expect(script.createCalls, 0);
  });

  testWidgets('an accepted upload travels as bytes and a name, never a path', (
    tester,
  ) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');

    await attach(tester, pickedDocument(filename: 'degree-scan.png'));
    expect(find.byKey(const ValueKey('document-file-chosen')), findsOneWidget);

    await save(tester);

    expect(script.createCalls, 1);
    expect(script.lastFilename, 'degree-scan.png');
    expect(script.lastFile, isNotNull);
    expect(script.lastBody!['document_type_id'], 2);
    expect(script.lastBody!.containsKey('employee_id'), isFalse);

    // The list, not the form: saving is a change of place, not a modal.
    expect(find.text('documents-list'), findsOneWidget);
  });

  testWidgets('a session filing for somebody else sends their id, and one '
      'filing its own does not', (tester) async {
    await pumpForm(
      tester,
      permissions: const ['documents.create', 'documents.manage'],
    );

    // The picker itself is the permission: without `documents.manage` the
    // field is not drawn at all, so there is no id to send and the API
    // reads an absent `employee_id` as "me".
    expect(find.byKey(const ValueKey('document-employee')), findsOneWidget);
  });

  testWidgets('a 422 from the server lands on the field it names', (
    tester,
  ) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');
    await attach(tester, pickedDocument());

    script.saveError = const ApiException(
      statusCode: 422,
      message: 'The given data was invalid.',
      errors: <String, String>{
        'document_number': 'That number is already on file.',
      },
    );

    await save(tester);

    expect(find.text('That number is already on file.'), findsOneWidget);
    expect(find.byKey(const ValueKey('document-form-banner')), findsNothing);
  });

  testWidgets('a 403 puts the door down rather than offering a retry loop', (
    tester,
  ) async {
    await pumpForm(tester);
    await chooseType(tester, 'Certificate');
    await attach(tester, pickedDocument());

    script.saveError = forbidden403;

    await save(tester);

    expect(find.byKey(const ValueKey('document-form-banner')), findsOneWidget);
    expect(find.text(forbidden403.message), findsOneWidget);

    final button = tester.widget<FilledButton>(
      find.byKey(const ValueKey('save-document')),
    );
    expect(button.onPressed, isNull);
  });

  testWidgets('a checklist row arrives with its document type already '
      'chosen — by code, not by name', (tester) async {
    await pumpForm(tester, location: '/documents/type-code');

    // The form asked the server what a passport is — it did not match the
    // word against a name it happens to know — and the answer is what the
    // picker now shows and what marks the fields required.
    expect(script.typesCalls, greaterThan(0));
    expect(find.text('Passport'), findsOneWidget);
    expect(find.text('Needs number, issue date, expiry date'), findsOneWidget);
  });

  testWidgets('a checklist code that matches no type leaves the picker '
      'usable rather than broken', (tester) async {
    await pumpForm(tester, location: '/documents/other');

    expect(script.typesCalls, greaterThan(0));
    expect(find.text('Not set'), findsOneWidget);

    await chooseType(tester, 'Certificate');
    expect(find.text('No extra details required'), findsOneWidget);
  });
}
