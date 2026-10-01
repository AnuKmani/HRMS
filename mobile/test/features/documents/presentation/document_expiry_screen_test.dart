import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/documents/domain/employee_document.dart';
import 'package:mobile/features/documents/presentation/document_expiry_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedDocuments script;

  setUp(() {
    script = ScriptedDocuments();
    script.expiringRows = <EmployeeDocument>[
      EmployeeDocument.fromJson(
        documentRow(
          id: 11,
          typeCode: 'EMIRATES_ID',
          typeName: 'Emirates ID',
          employeeName: 'Meera Nair',
          status: EmployeeDocument.statusValid,
          expiry: '2026-10-03',
          expiryState: EmployeeDocument.expirySoon,
          daysUntil: 2,
        ),
      ),
      EmployeeDocument.fromJson(
        documentRow(
          id: 12,
          typeCode: 'VISA',
          typeName: 'Visa',
          employeeName: 'Ravi Das',
          status: EmployeeDocument.statusExpired,
          expiry: '2026-09-01',
          expiryState: EmployeeDocument.expiryExpired,
          daysUntil: -30,
          fileName: 'visa.pdf',
          mime: 'application/pdf',
        ),
      ),
    ];
  });

  Future<void> pumpExpiry(
    WidgetTester tester, {
    List<String> permissions = const <String>[
      'documents.view',
      'documents.expiry.view',
    ],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        documents: script,
        child: const MaterialApp(home: DocumentExpiryScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without documents.expiry.view never asks what is '
      'about to lapse for everybody', (tester) async {
    await pumpExpiry(tester, permissions: const ['documents.view']);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.expiringCalls, 0);
  });

  testWidgets('the report opens on its own window and says what is due, in '
      'words', (tester) async {
    await pumpExpiry(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(script.expiringCalls, 1);
    expect(script.lastExpiringQuery!['within'], 90);

    expect(find.byKey(const ValueKey('expiring-row-11')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-11')),
        matching: find.text('Expires in 2 days'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-12')),
        matching: find.text('Expired 30 days ago'),
      ),
      findsOneWidget,
    );
    // The subtitle names the person and the date, so a row is actionable
    // without opening it.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expiring-row-11')),
        matching: find.text('Meera Nair · Expires 2026-10-03'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the window travels as the parameter the scheduler reads', (
    tester,
  ) async {
    await pumpExpiry(tester);

    await tapIn(tester, find.byKey(const ValueKey('expiry-window-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Next 30 days'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastExpiringQuery!['within'], '30');
  });

  testWidgets('an empty window says so rather than showing the directory', (
    tester,
  ) async {
    script.expiringRows = <EmployeeDocument>[];
    await pumpExpiry(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('Nothing is due to expire.'), findsOneWidget);
    expect(find.byKey(const ValueKey('document-row-11')), findsNothing);
  });
}
