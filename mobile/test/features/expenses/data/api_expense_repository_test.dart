import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/expenses/data/api_expense_repository.dart';

import '../../../support/phase4.dart' show forbidden403;
import '../../../support/site_reports.dart' show ScriptedApiClient;

/// Frames, as the phone stages them. The bytes are irrelevant to every
/// assertion here — what is being checked is *what leaves*.
Uint8List frame(List<int> bytes) => Uint8List.fromList(bytes);

/// One claim, as `ExpenseResource` sends it.
Map<String, dynamic> expenseJson({int id = 4, String amount = '250.00'}) =>
    <String, dynamic>{
      'id': id,
      'employee_id': 9,
      'employee': <String, dynamic>{'full_name': 'Anu Kmani'},
      'expense_category_id': 1,
      'category': <String, dynamic>{
        'id': 1,
        'name': 'Travel',
        'requires_receipt': true,
        'maximum_amount': null,
      },
      'site_id': 3,
      'site': <String, dynamic>{'id': 3, 'name': 'Block A'},
      'project_id': null,
      'project': null,
      'expense_date': '2026-09-28',
      'amount': amount,
      'currency': 'AED',
      'description': 'Taxi fare.',
      'status': 'draft',
      'is_draft': true,
      'is_open': true,
      'receipt_count': 0,
      'requires_receipt': true,
      'maximum_amount': null,
      'current_approval_step': null,
      'approval_chain': null,
      'submitted_at': null,
      'approved_at': null,
      'rejected_at': null,
      'cancelled_at': null,
    };

ApiEnvelope one(Object? data) => ApiEnvelope(message: '', data: data);

/// The paginated envelope, as `PaginatedResponse` sends one.
ApiEnvelope page(Object items, {int current = 1, int last = 1}) => ApiEnvelope(
  message: '',
  data: <String, dynamic>{
    'items': items,
    'meta': <String, dynamic>{
      'current_page': current,
      'last_page': last,
      'per_page': 15,
      'total': 40,
    },
  },
);

void main() {
  late ScriptedApiClient client;
  late ApiExpenseRepository repository;

  setUp(() {
    client = ScriptedApiClient();
    repository = ApiExpenseRepository(client);
  });

  group('what leaves the phone', () {
    test(
      'the list carries the filter and the page as query parameters',
      () async {
        client.reply = page([expenseJson()], current: 2, last: 5);

        final result = await repository.list(
          page: 2,
          query: const <String, Object?>{
            'status': 'pending',
            'site_id': 3,
            // The window `applyFilters()` actually reads: `from`/`to`, with
            // `date_from` as its alias. Sending a name the controller has
            // never heard of would look like filtering and quietly return
            // every claim in the database.
            'from': '2026-09-01',
            'to': '2026-09-30',
          },
        );

        expect(client.last!.verb, 'GET');
        expect(client.last!.path, '/expenses');
        expect(client.last!.query, <String, Object?>{
          'status': 'pending',
          'site_id': 3,
          'from': '2026-09-01',
          'to': '2026-09-30',
          'page': 2,
        });

        expect(result.items, hasLength(1));
        expect(result.currentPage, 2);
        expect(result.items.first.amount, '250.00');
      },
    );

    test('a new claim sends neither `employee_id` nor `status`', () async {
      client.reply = one(expenseJson());

      await repository.create(const <String, Object?>{
        'expense_date': '2026-09-28',
        'expense_category_id': 1,
        'amount': '250.00',
        'currency': 'AED',
        'description': 'Taxi fare.',
        'site_id': 3,
        'project_id': null,
      });

      expect(client.last!.verb, 'POST');
      expect(client.last!.path, '/expenses');

      final body = client.last!.body! as Map<String, Object?>;

      // Both are `prohibited` in `StoreExpenseRequest`: the claim belongs to
      // whoever is signed in, and it starts life as a draft. Sending either
      // would not save a round trip, it would buy a 422.
      expect(body.keys, isNot(contains('employee_id')));
      expect(body.keys, isNot(contains('status')));
      expect(body['expense_category_id'], 1);
      expect(body['amount'], '250.00');
    });

    test('an edit goes to PUT on the claim\'s own path', () async {
      client.reply = one(expenseJson());

      await repository.update(4, const <String, Object?>{'amount': '300.00'});

      expect(client.last!.verb, 'PUT');
      expect(client.last!.path, '/expenses/4');
      expect(client.last!.body, <String, Object?>{'amount': '300.00'});
    });

    test('the four transitions are four endpoints, not one string', () async {
      for (final verb in ['submit', 'approve', 'cancel']) {
        client.reply = one(expenseJson());

        switch (verb) {
          case 'submit':
            await repository.submit(4);
          case 'approve':
            await repository.approve(4, remarks: 'Checked against policy.');
          default:
            await repository.cancel(4);
        }

        expect(client.last!.verb, 'POST', reason: verb);
        expect(client.last!.path, '/expenses/4/$verb');
      }
    });

    test(
      'an empty remark is omitted rather than sent as an empty string',
      () async {
        client.reply = one(expenseJson());

        await repository.submit(4);

        expect(client.last!.body, <String, Object?>{});
      },
    );

    test('a refusal carries its reason through to the API', () async {
      client.reply = one(expenseJson());

      await repository.reject(4, remarks: 'Site not on this project.');

      expect(client.last!.path, '/expenses/4/reject');
      expect(client.last!.body, <String, Object?>{
        'remarks': 'Site not on this project.',
      });
    });

    test('an approval\'s remarks are sent when there are any', () async {
      client.reply = one(expenseJson());

      await repository.approve(4, remarks: 'Approved up to 250.00.');

      expect(client.last!.body, <String, Object?>{
        'remarks': 'Approved up to 250.00.',
      });
    });
  });

  group('receipts', () {
    test('go up as one multipart batch named `receipts`', () async {
      client.reply = one(expenseJson());

      await repository.addReceipts(4, <Uint8List>[
        frame(const <int>[1, 2, 3]),
        frame(const <int>[4, 5, 6]),
      ]);

      expect(client.last!.verb, 'POST');
      expect(client.last!.path, '/expenses/4/receipts');

      final batch = client.last!.files!['receipts']! as List<MultipartFile>;

      expect(batch, hasLength(2));
      expect(batch.first.filename, 'receipt-1.jpg');
      expect(batch.last.filename, 'receipt-2.jpg');
    });

    test('are read by id, and never through a URL', () async {
      final bytes = await repository.receipt(4, 9);

      expect(client.last!.verb, 'BYTES');
      expect(client.last!.path, '/expenses/4/receipts/9');
      expect(bytes, isNotEmpty);
    });

    test('are deleted by id on their own claim', () async {
      client.reply = one(expenseJson());

      await repository.removeReceipt(4, 9);

      expect(client.last!.verb, 'DELETE');
      expect(client.last!.path, '/expenses/4/receipts/9');
    });
  });

  group('the category vocabulary', () {
    test('is read as a plain collection, not as a page of rows', () async {
      // `GET /expense-categories` answers `{success, message, data: [...]}`
      // — no `items`, no `meta`. Running it through `PageResult.fromEnvelope`
      // would read a `meta` that is not there and report an empty page for
      // a list that arrived in full.
      client.reply = ApiEnvelope(
        message: 'Expense categories retrieved.',
        data: <dynamic>[
          <String, dynamic>{
            'id': 1,
            'name': 'Travel',
            'code': 'TRAVEL',
            'status': 'active',
            'requires_receipt': true,
            'maximum_amount': null,
          },
          <String, dynamic>{
            'id': 4,
            'name': 'Food',
            'code': 'FOOD',
            'status': 'active',
            'requires_receipt': false,
            'maximum_amount': '1000.00',
          },
        ],
      );

      final rows = await repository.categories();

      expect(client.last!.path, '/expense-categories');
      expect(rows, hasLength(2));
      expect(rows.first.name, 'Travel');
      expect(rows.first.requiresReceipt, isTrue);
      expect(rows.last.maximumAmount, '1000.00');
    });
  });

  group('what a failure looks like on the way out', () {
    test('an unauthenticated session stays unauthenticated', () async {
      // The repository is not allowed to soften this into an empty list:
      // "there are no claims" and "you may not ask" are different answers,
      // and only one of them sends a person looking for a login screen.
      const unauthenticated = ApiException(
        statusCode: 401,
        message: 'Unauthenticated.',
      );
      client.error = unauthenticated;

      await expectLater(repository.list(), throwsA(same(unauthenticated)));
    });

    test('a refused read stays refused', () async {
      client.error = forbidden403;

      await expectLater(repository.find(4), throwsA(same(forbidden403)));
    });

    test(
      'a refusal on upload is not mistaken for a successful filing',
      () async {
        client.error = forbidden403;

        await expectLater(
          repository.addReceipts(4, <Uint8List>[]),
          throwsA(same(forbidden403)),
        );
        expect(client.last!.verb, 'POST');
      },
    );

    test(
      'a payload that is not an object is reported, not swallowed',
      () async {
        client.reply = const ApiEnvelope(message: '', data: <dynamic>[1, 2]);

        await expectLater(
          repository.find(4),
          throwsA(same(unexpectedShapeException)),
        );
      },
    );
  });
}
