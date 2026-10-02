import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/assets/domain/asset.dart';
import 'package:mobile/features/assets/presentation/asset_list_screen.dart';

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
        typeCode: 'LAPTOP',
        typeName: 'Laptop',
        status: Asset.statusAvailable,
        currentCondition: Asset.conditionGood,
      ),
      assetRow(
        id: 2,
        assetCode: 'PHONE-00011',
        name: 'iPhone 15',
        typeId: 2,
        typeCode: 'MOBILE_PHONE',
        typeName: 'Mobile phone',
        status: Asset.statusAssigned,
        currentCondition: Asset.conditionFair,
        hasHolder: true,
        holderName: 'Meera Nair',
        isAssignable: false,
      ),
      assetRow(
        id: 3,
        assetCode: 'LIFT-00003',
        name: 'Genie lift',
        typeId: 3,
        typeCode: 'SAFETY_EQUIPMENT',
        typeName: 'Safety equipment',
        status: Asset.statusMaintenance,
        currentCondition: Asset.conditionPoor,
        isAssignable: false,
      ),
    ]);
    script.typeRows = [
      assetTypeRow(id: 1, code: 'LAPTOP', name: 'Laptop'),
      assetTypeRow(id: 2, code: 'MOBILE_PHONE', name: 'Mobile phone'),
      assetTypeRow(id: 3, code: 'SAFETY_EQUIPMENT', name: 'Safety equipment'),
    ];
  });

  Future<void> pumpRegister(
    WidgetTester tester, {
    List<String> permissions = const ['assets.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase11(
        permissions: permissions,
        assets: script,
        child: const MaterialApp(home: AssetListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without assets.view is never asked for a register', (
    tester,
  ) async {
    await pumpRegister(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('new-asset')), findsNothing);
    expect(find.byKey(const ValueKey('asset-history-door')), findsNothing);
  });

  testWidgets('a row says what it is, who has it, and both its states', (
    tester,
  ) async {
    await pumpRegister(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-1')),
        matching: find.text('Dell Latitude 5540'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-1')),
        matching: find.text('TOOL-00042 · Laptop · Good'),
      ),
      findsOneWidget,
    );

    // Status and condition are two chips from two columns, and the
    // condition is spelled out beside the status rather than being left to
    // it — an asset can be `assigned` and `poor` at the same time.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-1')),
        matching: find.text('Available'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-1')),
        matching: find.text('Good'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an out-on-loan asset names the holder in its row', (
    tester,
  ) async {
    await pumpRegister(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-2')),
        matching: find.text('PHONE-00011 · Mobile phone · Meera Nair · Fair'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-2')),
        matching: find.text('Assigned'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an asset in the workshop says so in its own words', (
    tester,
  ) async {
    await pumpRegister(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-3')),
        matching: find.text('In maintenance'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('asset-row-3')),
        matching: find.text('Poor'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the register door belongs to assets.create', (tester) async {
    await pumpRegister(tester, permissions: const ['assets.view']);
    expect(find.byKey(const ValueKey('new-asset')), findsNothing);

    await pumpRegister(
      tester,
      permissions: const ['assets.view', 'assets.create'],
    );
    expect(find.byKey(const ValueKey('new-asset')), findsOneWidget);
  });

  testWidgets('the hand-over log door belongs to assets.history.view', (
    tester,
  ) async {
    // Watching one's own kit is not the same question as reading who has
    // held *what* across the register — the log is a second permission on
    // top of the first, not a second button for it.
    await pumpRegister(tester, permissions: const ['assets.view']);
    expect(find.byKey(const ValueKey('asset-history-door')), findsNothing);

    await pumpRegister(
      tester,
      permissions: const ['assets.view', 'assets.history.view'],
    );
    expect(find.byKey(const ValueKey('asset-history-door')), findsOneWidget);
  });

  testWidgets('the type filter travels as a query parameter', (tester) async {
    await pumpRegister(tester);

    expect(script.lastQuery!['asset_type_id'], isNull);

    await tapIn(tester, find.byKey(const ValueKey('asset-type-filter')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Mobile phone'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['asset_type_id'], '2');
  });

  testWidgets('status and condition are separate filters, and both reach the '
      'server', (tester) async {
    await pumpRegister(tester);

    await tapIn(tester, find.byKey(const ValueKey('asset-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Assigned'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'assigned');

    await tapIn(tester, find.byKey(const ValueKey('asset-condition-filter')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Poor'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    // Both keys survive: an asset can be `assigned` and `poor` at the same
    // time, and a filter that folded them into one would be answering a
    // question nobody asked.
    expect(script.lastQuery!['status'], 'assigned');
    expect(script.lastQuery!['condition'], 'poor');
  });

  testWidgets('an empty register says what would appear here', (tester) async {
    script = ScriptedAssets();
    script.typeRows = [assetTypeRow(id: 1, code: 'LAPTOP', name: 'Laptop')];
    await pumpRegister(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No assets yet.'), findsOneWidget);
    expect(
      find.text(
        'Laptops, phones, tools and safety kit appear here once they are '
        'registered.',
      ),
      findsOneWidget,
    );
    // The type list arrives whether or not there are rows, so a brand new
    // operator can still narrow before they have anything to narrow.
    expect(script.typesCalls, greaterThan(0));
  });

  testWidgets('a refusal — 401 or 403 — is a message, not a blank page', (
    tester,
  ) async {
    script.listError = const ApiException(
      statusCode: 403,
      message: 'This action is forbidden.',
    );
    await pumpRegister(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text('This action is forbidden.'), findsOneWidget);
  });

  testWidgets('while the register is in flight there is a spinner', (
    tester,
  ) async {
    final held = Completer<void>();
    script.holdList = held;

    await pumpRegister(tester);

    expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);
    expect(find.byKey(const ValueKey('list-empty')), findsNothing);

    held.complete();
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    script.holdList = null;
  });
}
