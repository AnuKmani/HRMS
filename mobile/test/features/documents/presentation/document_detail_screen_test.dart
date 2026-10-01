import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';
import 'package:mobile/features/documents/domain/employee_document.dart';
import 'package:mobile/features/documents/presentation/document_detail_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show pngBytes, tapIn;

/// The path table the detail screen navigates, and nothing else.
GoRouter detailRouter(String location) => GoRouter(
  initialLocation: location,
  routes: [
    GoRoute(
      path: '/documents/:id',
      builder: (_, state) => DocumentDetailScreen(
        documentId: int.parse(state.pathParameters['id']!),
      ),
    ),
    GoRoute(
      path: '/documents/:id/edit',
      builder: (_, state) =>
          Scaffold(body: Text('editing-${state.pathParameters['id']}')),
    ),
  ],
);

const ApiException alreadyDecided = ApiException(
  statusCode: 409,
  message: 'This document has already been verified.',
);

const ApiException unauthenticated = ApiException(
  statusCode: 401,
  message: 'Unauthenticated.',
);

void main() {
  late ScriptedDocuments script;

  setUp(() {
    script = ScriptedDocuments.rows([
      documentRow(
        id: 1,
        typeCode: 'PASSPORT',
        typeName: 'Passport',
        status: EmployeeDocument.statusPending,
        expiry: '2026-10-06',
        expiryState: EmployeeDocument.expirySoon,
        daysUntil: 5,
      ),
      documentRow(
        id: 2,
        typeCode: 'EMPLOYMENT_CONTRACT',
        typeName: 'Employment contract',
        status: EmployeeDocument.statusValid,
        expiry: null,
        expiryState: EmployeeDocument.expiryNone,
        daysUntil: null,
        fileName: 'contract.pdf',
        mime: 'application/pdf',
        verifiedAt: '2026-09-29T10:00:00Z',
      ),
      documentRow(
        id: 3,
        typeCode: 'EMIRATES_ID',
        typeName: 'Emirates ID',
        status: EmployeeDocument.statusPending,
        hasFile: false,
        expiry: '2026-12-01',
        expiryState: EmployeeDocument.expiryValid,
        daysUntil: 61,
      ),
    ]);

    // Real PNG bytes, because the detail screen draws an image preview for
    // anything it recognises as one — a test double's placeholder header
    // would be rejected by the codec halfway through an assertion about
    // something else entirely.
    script.fileBytes = pngBytes;
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const ['documents.view'],
    PdfOpener? opener,
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        documents: script,
        pdfOpener: opener,
        child: MaterialApp.router(routerConfig: detailRouter('/documents/$id')),
      ),
    );
    await advance(tester);
  }

  Future<void> openActions(WidgetTester tester) async {
    await tapIn(tester, find.byKey(const ValueKey('document-actions')));
    await tester.pumpAndSettle();
  }

  testWidgets('a session without documents.view is never shown a file', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
    expect(script.fileCalls, 0);
  });

  testWidgets('the screen states the decision and the date separately', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1);

    expect(find.text('Passport'), findsOneWidget);
    expect(find.text('Awaiting verification'), findsOneWidget);
    expect(find.text('Expires in 5 days'), findsOneWidget);
    expect(find.text('N1234567'), findsOneWidget);

    // A `verified` document whose date has passed is two facts, not one.
    await pumpDetail(tester, id: 2);

    expect(find.text('Verified'), findsOneWidget);
    expect(find.text('No expiry date'), findsOneWidget);
    expect(find.text('180 days'), findsOneWidget);
  });

  testWidgets('signing off belongs to documents.verify, editing to '
      'documents.update, and archiving to documents.delete', (tester) async {
    await pumpDetail(tester, id: 1, permissions: const ['documents.view']);

    expect(find.byKey(const ValueKey('document-actions')), findsNothing);
    expect(find.byKey(const ValueKey('edit-document')), findsNothing);
    expect(find.byKey(const ValueKey('archive-document')), findsNothing);

    await pumpDetail(
      tester,
      id: 1,
      permissions: const ['documents.view', 'documents.update'],
    );
    expect(find.byKey(const ValueKey('edit-document')), findsOneWidget);
    // Seeing the row is not the same as being allowed to judge it.
    expect(find.byKey(const ValueKey('document-actions')), findsNothing);

    await pumpDetail(
      tester,
      id: 1,
      permissions: const <String>[
        'documents.view',
        'documents.verify',
        'documents.delete',
      ],
    );
    expect(find.byKey(const ValueKey('document-actions')), findsOneWidget);
    expect(find.byKey(const ValueKey('edit-document')), findsNothing);
  });

  testWidgets('an already-decided document offers nothing to decide', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const <String>[
        'documents.view',
        'documents.verify',
        'documents.delete',
      ],
    );

    // The menu is keyed on `status`, not on `is_pending` — the row's own
    // record decides what is on offer, not what the reader may do.
    expect(find.byKey(const ValueKey('document-actions')), findsNothing);
    // Archiving a verified file is still available: it is a change of place,
    // not a change of verdict.
    expect(find.byKey(const ValueKey('archive-document')), findsOneWidget);
  });

  testWidgets('a rejection is refused until a reason is given, then travels', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const <String>['documents.view', 'documents.verify'],
    );

    await openActions(tester);
    await tapIn(tester, find.byKey(const ValueKey('reject-document')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.byKey(const ValueKey('reject-confirm')));
    await tester.pump();

    // A refusal with no reason is a decision nobody can learn from, and the
    // API answers 422 without one — so the rule is enforced here rather
    // than discovered as a red field.
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(script.rejectCalls, 0);

    await tester.enterText(
      find.byKey(const ValueKey('reject-reason')),
      'The back of the card was cut off.',
    );
    await tapIn(tester, find.byKey(const ValueKey('reject-confirm')));
    await advance(tester);

    expect(script.rejectCalls, 1);
    expect(script.lastRejectReason, 'The back of the card was cut off.');
    expect(find.text('Rejected'), findsOneWidget);
    expect(find.text('The back of the card was cut off.'), findsOneWidget);
  });

  testWidgets('verifying moves the row the server moved', (tester) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const ['documents.view', 'documents.verify'],
    );

    expect(find.text('Awaiting verification'), findsOneWidget);

    await openActions(tester);
    await tapIn(tester, find.byKey(const ValueKey('verify-document')));
    await advance(tester);

    expect(script.verifyCalls, 1);
    expect(find.text('Verified'), findsOneWidget);
    expect(find.text('Awaiting verification'), findsNothing);
  });

  testWidgets('a 409 the server still makes is shown as its own sentence', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 1,
      permissions: const ['documents.view', 'documents.verify'],
    );

    script.verifyError = alreadyDecided;

    await openActions(tester);
    await tapIn(tester, find.byKey(const ValueKey('verify-document')));
    await advance(tester);

    expect(
      find.byKey(const ValueKey('document-detail-banner')),
      findsOneWidget,
    );
    expect(find.text(alreadyDecided.message), findsOneWidget);
  });

  testWidgets('archiving is confirmed, and a cancellation archives nothing', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const <String>['documents.view', 'documents.delete'],
    );

    await tapIn(tester, find.byKey(const ValueKey('archive-document')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Nothing is deleted'), findsOneWidget);

    await tapIn(tester, find.byKey(const ValueKey('archive-cancel')));
    await tester.pumpAndSettle();

    expect(script.archiveCalls, 0);

    await tapIn(tester, find.byKey(const ValueKey('archive-document')));
    await tester.pumpAndSettle();
    await tapIn(tester, find.byKey(const ValueKey('archive-confirm')));
    await advance(tester);

    expect(script.archiveCalls, 1);
    expect(find.text('Archived'), findsOneWidget);
  });

  testWidgets('a PDF is opened by id, through the viewer, with no URL kept', (
    tester,
  ) async {
    final opener = RecordingPdfOpener();

    await pumpDetail(tester, id: 2, opener: opener);

    // Nothing fetched yet: a preview is only started for something this
    // screen knows how to draw.
    expect(script.fileCalls, 0);

    await tapIn(tester, find.byKey(const ValueKey('open-document-file')));
    await advance(tester);

    expect(script.fileCalls, 1);
    expect(script.lastFileId, 2);
    expect(opener.calls, 1);
    expect(opener.opened, ['contract.pdf']);
  });

  testWidgets('an image is drawn from the bytes the policy-checked route '
      'returned', (tester) async {
    script.fileBytes = pngBytes;

    await pumpDetail(tester, id: 1);

    expect(script.fileCalls, 1);
    expect(
      find.byKey(const ValueKey('document-image-preview')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('open-document-file')), findsOneWidget);
  });

  testWidgets('a record with no file says so rather than offering one', (
    tester,
  ) async {
    await pumpDetail(tester, id: 3);

    expect(find.byKey(const ValueKey('document-no-file')), findsOneWidget);
    expect(find.byKey(const ValueKey('open-document-file')), findsNothing);
    expect(script.fileCalls, 0);
  });

  testWidgets('a 403 is a refusal and a 401 is a question, not the same '
      'thing', (tester) async {
    script.findError = forbidden403;
    await pumpDetail(tester, id: 1);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

    script.findError = unauthenticated;
    await pumpDetail(tester, id: 1);

    expect(find.byKey(const ValueKey('document-not-found')), findsOneWidget);
    expect(find.text(unauthenticated.message), findsOneWidget);
  });
}
