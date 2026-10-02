import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/assets/domain/asset.dart';
import 'package:mobile/features/assets/presentation/asset_detail_screen.dart';

import '../../../support/phase11.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedAssets script;

  setUp(() {
    script = ScriptedAssets.rows([
      assetRow(
        id: 1,
        assetCode: 'TOOL-00042',
        name: 'Dell Latitude 5540',
        typeId: 1,
        typeName: 'Laptop',
        status: Asset.statusAvailable,
        currentCondition: Asset.conditionGood,
        cost: '1234.56',
        assignments: const <Map<String, dynamic>>[],
      ),
      assetRow(
        id: 2,
        assetCode: 'PHONE-00011',
        name: 'iPhone 15',
        typeId: 2,
        typeName: 'Mobile phone',
        status: Asset.statusAssigned,
        currentCondition: Asset.conditionFair,
        hasHolder: true,
        holderName: 'Meera Nair',
        isAssignable: false,
        assignments: <Map<String, dynamic>>[
          assetAssignmentRow(
            id: 71,
            assignedDate: '2026-09-15',
            remarks: 'Charger left in the drawer.',
          ),
        ],
        currentAssignment: assetAssignmentRow(
          id: 71,
          assignedDate: '2026-09-15',
          remarks: 'Charger left in the drawer.',
        ),
      ),
      assetRow(
        id: 3,
        assetCode: 'LIFT-00003',
        name: 'Genie lift',
        typeId: 3,
        typeName: 'Safety equipment',
        status: Asset.statusAvailable,
        currentCondition: Asset.conditionNew,
        // No `assignments` key at all: the payload that never asked.
        assignments: null,
        currentAssignment: null,
      ),
      assetRow(
        id: 4,
        assetCode: 'OLD-00001',
        name: 'Decommissioned desk',
        typeId: 1,
        typeName: 'Laptop',
        status: Asset.statusRetired,
        currentCondition: Asset.conditionPoor,
        isRetired: true,
        isAssignable: false,
        assignments: const <Map<String, dynamic>>[],
      ),
    ]);
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const [
      'assets.view',
      'assets.create',
      'assets.update',
      'assets.manage',
      'assets.assign',
      'assets.return',
      'assets.history.view',
    ],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        assets: script,
        child: MaterialApp(home: AssetDetailScreen(assetId: id)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without assets.view is never asked for the record', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('status, condition and readiness are three separate words', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1);

    expect(find.byKey(const ValueKey('asset-detail-body')), findsOneWidget);
    expect(find.byKey(const ValueKey('asset-detail-title')), findsOneWidget);
    expect(find.text('TOOL-00042 · Laptop'), findsOneWidget);
    expect(find.text('Available'), findsOneWidget);
    expect(find.text('Good'), findsOneWidget);
    expect(find.text('Ready to hand out'), findsOneWidget);
  });

  testWidgets('a cost the server withheld says so, rather than reading as '
      'free', (tester) async {
    script.payloads[1]!['purchase_cost'] = null;
    script.payloads[1]!.remove('purchase_cost');
    script.items[0] = Asset.fromJson(script.payloads[1]!);

    // Permissions are irrelevant here: the *payload* is what is missing,
    // and the two absences (withheld vs never written down) are drawn as
    // two different sentences.
    await pumpDetail(tester, id: 1, permissions: const ['assets.view']);

    expect(find.text('Not shown to your role'), findsOneWidget);
    expect(find.text('Not recorded'), findsNothing);
  });

  testWidgets('a cost that arrived is printed in the company\u2019s money', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1, permissions: const ['assets.view']);

    // From `client-settings`, not from a constant in this file.
    expect(find.text('AED 1,234.56'), findsOneWidget);
    expect(find.text('Not shown to your role'), findsNothing);
    expect(find.text('Not recorded'), findsNothing);
  });

  testWidgets(
    '\u201cwe did not ask\u201d is not the same as \u201cit is free\u201d',
    (tester) async {
      await pumpDetail(tester, id: 3, permissions: const ['assets.view']);

      expect(find.byKey(const ValueKey('asset-holder')), findsOneWidget);
      expect(find.text('Holders not loaded'), findsOneWidget);
      expect(
        find.text('This view did not fetch the hand-over history.'),
        findsOneWidget,
      );
      expect(find.text('Nobody holds it'), findsNothing);
    },
  );

  testWidgets('an asset nobody has ever taken out says which shelf it is on', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1, permissions: const ['assets.view']);

    expect(find.text('Nobody holds it'), findsOneWidget);
    expect(find.text('It is on the shelf.'), findsOneWidget);
    expect(find.text('Holders not loaded'), findsNothing);
  });

  testWidgets('an out-on-loan asset names its holder, its date and its '
      'remarks', (tester) async {
    await pumpDetail(tester, id: 2, permissions: const ['assets.view']);

    expect(find.text('Meera Nair'), findsOneWidget);
    expect(find.text('Since 2026-09-15'), findsOneWidget);
    expect(find.text('Remarks: Charger left in the drawer.'), findsOneWidget);
    expect(find.text('Assigned'), findsOneWidget);
    expect(find.text('Fair'), findsOneWidget);
    expect(find.text('Ready to hand out'), findsNothing);
  });

  testWidgets('handing out belongs to assets.assign, and to an asset nobody '
      'holds', (tester) async {
    await pumpDetail(tester, id: 1, permissions: const ['assets.view']);
    expect(find.byKey(const ValueKey('assign-asset')), findsNothing);

    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('assign-asset')), findsOneWidget);

    // Same permissions, different record: one already out is not a thing
    // anybody can be handed.
    await pumpDetail(tester, id: 2);
    expect(find.byKey(const ValueKey('assign-asset')), findsNothing);
  });

  testWidgets('taking back belongs to assets.return, and to an asset '
      'somebody holds', (tester) async {
    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('return-asset')), findsNothing);

    await pumpDetail(tester, id: 2, permissions: const ['assets.view']);
    expect(find.byKey(const ValueKey('return-asset')), findsNothing);

    await pumpDetail(tester, id: 2);
    expect(find.byKey(const ValueKey('return-asset')), findsOneWidget);
  });

  testWidgets('moving the status belongs to assets.manage alone', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1, permissions: const ['assets.view']);
    expect(find.byKey(const ValueKey('change-status')), findsNothing);

    await pumpDetail(
      tester,
      id: 1,
      permissions: const ['assets.view', 'assets.manage'],
    );
    expect(find.byKey(const ValueKey('change-status')), findsOneWidget);
    // Watching one's own kit is not the right to write to the register.
    expect(find.byKey(const ValueKey('assign-asset')), findsNothing);
  });

  testWidgets('a retired asset offers no edit door', (tester) async {
    await pumpDetail(tester, id: 4);
    expect(find.byKey(const ValueKey('edit-asset')), findsNothing);

    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('edit-asset')), findsOneWidget);
  });

  testWidgets('handing out needs a holder before it sends anything', (
    tester,
  ) async {
    await pumpDetail(tester, id: 1);

    await tapIn(tester, find.byKey(const ValueKey('assign-asset')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('confirm-assign')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('assign-error')), findsOneWidget);
    expect(find.text('Choose who is taking it.'), findsOneWidget);
    expect(script.assignCalls, 0);
    expect(find.byKey(const ValueKey('confirm-assign')), findsOneWidget);
  });

  testWidgets('a hand-over is sent with its holder, its date and its '
      'condition', (tester) async {
    await pumpDetail(tester, id: 1);

    await tapIn(tester, find.byKey(const ValueKey('assign-asset')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('assign-employee')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(ListTile, 'Anu Kmani'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('confirm-assign')));
    await advance(tester);

    expect(script.assignCalls, 1);
    expect(script.lastAssignId, 1);
    expect(script.lastAssignBody!['employee_id'], 7);
    expect(script.lastAssignBody!['assigned_date'], isNotEmpty);
    expect(script.lastAssignBody!['assigned_condition'], Asset.conditionNew);

    // And the screen re-reads rather than optimistically redrawing: the
    // server's answer is what now describes the row.
    expect(find.text('Anu Kmani'), findsOneWidget);
    expect(find.text('Assigned'), findsOneWidget);
    expect(find.byKey(const ValueKey('assign-asset')), findsNothing);
    expect(find.byKey(const ValueKey('return-asset')), findsOneWidget);
  });

  testWidgets('a hand-back carries the condition that came back, and the row '
      'moves with it', (tester) async {
    await pumpDetail(tester, id: 2);

    await tapIn(tester, find.byKey(const ValueKey('return-asset')));
    await tester.pumpAndSettle();

    // Nothing about a hand-back is known until somebody looks, so the
    // sheet will not send one on a guess.
    await tester.tap(find.byKey(const ValueKey('confirm-return')));
    await tester.pumpAndSettle();

    expect(find.text('Say what condition it came back in.'), findsOneWidget);
    expect(script.returnCalls, 0);

    await tester.tap(find.byKey(const ValueKey('return-condition')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Poor'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('confirm-return')));
    await advance(tester);

    expect(script.returnCalls, 1);
    expect(script.lastReturnId, 2);
    expect(script.lastReturnBody!['returned_condition'], Asset.conditionPoor);

    // Poor comes back, so it goes to maintenance rather than on the shelf —
    // drawn twice (the chip, and the line under "Currently with"), which is
    // the point: the status is not left to one place to say it.
    expect(find.text('In maintenance'), findsWidgets);
    expect(find.text('Poor'), findsOneWidget);
    expect(find.byKey(const ValueKey('return-asset')), findsNothing);
  });

  testWidgets('a status move sends the status chosen, not the one it started '
      'on', (tester) async {
    await pumpDetail(tester, id: 1);

    await tapIn(tester, find.byKey(const ValueKey('change-status')));
    await tester.pumpAndSettle();

    // `assigned` is not offered: a status that could say "somebody holds
    // this" with no hand-over behind it is the write this module exists to
    // prevent.
    expect(find.byKey(const ValueKey('status-no-targets')), findsNothing);
    expect(
      find.widgetWithText(DropdownMenuItem<String>, 'Assigned'),
      findsNothing,
    );

    await tester.tap(find.byKey(const ValueKey('status-target')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'In maintenance'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('status-notes')),
      'Fan is jammed.',
    );
    await tester.tap(find.byKey(const ValueKey('confirm-status')));
    await advance(tester);

    expect(script.statusCalls, 1);
    expect(script.lastStatusId, 1);
    expect(script.lastStatus, Asset.statusMaintenance);
    expect(script.lastStatusNotes, 'Fan is jammed.');
    expect(find.text('In maintenance'), findsWidgets);
  });

  testWidgets('a refusal naming the state stays on screen after the sheet '
      'has gone', (tester) async {
    script.assignError = const ApiException(
      statusCode: 409,
      message: 'That asset is already out with somebody.',
    );

    await pumpDetail(tester, id: 1);

    await tapIn(tester, find.byKey(const ValueKey('assign-asset')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('assign-employee')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(ListTile, 'Anu Kmani'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('confirm-assign')));
    await advance(tester);

    expect(find.byKey(const ValueKey('assign-error')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-detail-banner')),
        matching: find.text('That asset is already out with somebody.'),
      ),
      findsOneWidget,
    );
    // Nothing moved: a refusal is a refusal, not a partial write.
    expect(find.text('Available'), findsOneWidget);
    expect(script.assignCalls, 1);
  });

  testWidgets('a record that has moved is an error with a way back, not a '
      'blank screen', (tester) async {
    script.findError = notFound404;
    await pumpDetail(tester, id: 99);

    expect(find.byKey(const ValueKey('asset-detail-error')), findsOneWidget);
    expect(find.byKey(const ValueKey('asset-detail-retry')), findsOneWidget);
  });

  testWidgets('a record this session may not open says so, and offers no '
      'retry', (tester) async {
    script.findError = forbidden403;
    await pumpDetail(tester, id: 1);

    expect(find.text('You may not open this asset.'), findsOneWidget);
    expect(find.byKey(const ValueKey('asset-detail-retry')), findsNothing);
  });
}
