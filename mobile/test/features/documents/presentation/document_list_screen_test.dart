import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/documents/domain/employee_document.dart';
import 'package:mobile/features/documents/presentation/document_list_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedDocuments script;

  setUp(() {
    script = ScriptedDocuments.rows([
      documentRow(
        id: 1,
        typeCode: 'PASSPORT',
        typeName: 'Passport',
        employeeName: 'Anu Kmani',
        status: EmployeeDocument.statusValid,
        expiry: '2026-11-01',
        expiryState: EmployeeDocument.expirySoon,
        daysUntil: 31,
      ),
      documentRow(
        id: 2,
        typeCode: 'VISA',
        typeName: 'Visa',
        employeeName: 'Meera Nair',
        status: EmployeeDocument.statusPending,
        expiry: '2026-10-06',
        expiryState: EmployeeDocument.expirySoon,
        daysUntil: 5,
      ),
      documentRow(
        id: 3,
        typeCode: 'EMPLOYMENT_CONTRACT',
        typeName: 'Employment contract',
        employeeName: 'Anu Kmani',
        status: EmployeeDocument.statusExpired,
        expiry: '2026-09-19',
        expiryState: EmployeeDocument.expiryExpired,
        daysUntil: -12,
        fileName: 'contract.pdf',
        mime: 'application/pdf',
      ),
    ]);
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['documents.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        documents: script,
        child: const MaterialApp(home: DocumentListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without documents.view is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('upload-document')), findsNothing);
  });

  testWidgets('a row says whose it is, what it is, and how long it has left', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    // Three facts per row, and the expiry one is a sentence rather than a
    // colour — scope item U is about this exact string.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('document-row-2')),
        matching: find.text('Visa'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('document-row-2')),
        matching: find.text('Meera Nair · N1234567 · passport-scan.jpg'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('document-row-2')),
        matching: find.text('Expires in 5 days'),
      ),
      findsOneWidget,
    );

    // Scoped: "Verified" is also an option in the status filter above.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('document-row-1')),
        matching: find.text('Verified'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('document-row-3')),
        matching: find.text('Expired 12 days ago'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the upload door belongs to documents.create, not to viewing', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['documents.view']);
    expect(find.byKey(const ValueKey('upload-document')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['documents.view', 'documents.create'],
    );
    expect(find.byKey(const ValueKey('upload-document')), findsOneWidget);
  });

  testWidgets('the everybody\'s-expiry door belongs to documents.expiry.view', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['documents.view']);
    expect(find.byKey(const ValueKey('documents-expiring-door')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['documents.view', 'documents.expiry.view'],
    );
    expect(
      find.byKey(const ValueKey('documents-expiring-door')),
      findsOneWidget,
    );
  });

  testWidgets('the status filter travels as a query parameter', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery!['status'], isNull);

    await tapIn(tester, find.byKey(const ValueKey('document-status-filter')));
    await tester.pumpAndSettle();

    // Scoped to the menu: the list behind it holds rows whose own chips read
    // the same words.
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Awaiting verification'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'pending');
  });

  testWidgets('the expiry selector becomes the two booleans the API reads', (
    tester,
  ) async {
    await pumpList(tester);

    await tapIn(tester, find.byKey(const ValueKey('document-expiry-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Expiring soon'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    // One key in `state.query` so the dropdown has a value to draw when it
    // comes back; two on the wire because that is what the endpoint takes.
    // The selector's own name is *not* sent — "expiring" is not a column.
    expect(script.lastQuery!['expiring_soon'], '1');
    expect(script.lastQuery!.containsKey('expiry'), isFalse);
  });

  testWidgets('an empty answer says what would appear here', (tester) async {
    script = ScriptedDocuments();
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No documents yet.'), findsOneWidget);
  });

  testWidgets('a refusal — 401 or 403 — is a message, not a blank page', (
    tester,
  ) async {
    script.listError = const ApiException(
      statusCode: 401,
      message: 'Unauthenticated.',
    );
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('Unauthenticated.'), findsOneWidget);
  });

  testWidgets('while the first page is in flight there is a spinner', (
    tester,
  ) async {
    final held = Completer<void>();
    script.holdList = held;

    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);
    expect(find.byKey(const ValueKey('list-empty')), findsNothing);

    held.complete();
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    script.holdList = null;
  });
}
