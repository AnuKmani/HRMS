import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/site_reports/data/api_daily_site_report_repository.dart';
import 'package:mobile/features/site_reports/data/api_site_activity_repository.dart';
import 'package:mobile/features/site_reports/domain/daily_site_report.dart';

import '../../../support/site_reports.dart';

/// Frames, as the phone stages them. The bytes are irrelevant to every
/// assertion here — what is being checked is *what leaves*, not what it
/// depicts.
Uint8List frame() => Uint8List.fromList(const <int>[1, 2, 3]);

/// One activity report, as the API writes it.
Map<String, dynamic> activityJson({int id = 7}) => <String, dynamic>{
  'id': id,
  'employee_id': 3,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'project_id': 10,
  'project': <String, dynamic>{'name': 'Riverfront Towers'},
  'site_id': 1,
  'site': <String, dynamic>{'name': 'Block A'},
  'report_date': '2026-09-28',
  'work_category': 'RCC',
  'work_performed': 'Slab pour on the third floor.',
  'progress_percentage': 60,
  'status': 'draft',
  'is_draft': true,
  'is_editable': true,
  'photo_count': 0,
};

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
  group('the activity report over HTTP', () {
    late ScriptedApiClient client;
    late ApiSiteActivityRepository repository;

    setUp(() {
      client = ScriptedApiClient();
      repository = ApiSiteActivityRepository(client);
    });

    test(
      'a page arrives as {items, meta} and the query it was asked for',
      () async {
        client.reply = page(<dynamic>[activityJson()], current: 3, last: 5);

        final result = await repository.list(
          page: 3,
          query: const <String, Object?>{'status': 'draft'},
        );

        expect(result.items.single.siteName, 'Block A');
        expect(result.currentPage, 3);
        expect(result.lastPage, 5);
        expect(result.hasNext, isTrue);

        final call = client.last!;
        expect(call.verb, 'GET');
        expect(call.path, '/site-activity-reports');
        expect(call.query, containsPair('status', 'draft'));
        expect(call.query, containsPair('page', 3));
      },
    );

    test('submit carries the fix and three keys, and nothing else', () async {
      client.reply = ApiEnvelope(message: '', data: activityJson(id: 9));

      await repository.submit(
        9,
        latitude: 12.9716,
        longitude: 77.5946,
        accuracy: 8.5,
      );

      final call = client.last!;
      expect(call.verb, 'POST');
      expect(call.path, '/site-activity-reports/9/submit');

      final body = call.body as Map<String, Object?>;
      expect(
        body.keys,
        unorderedEquals(<String>['latitude', 'longitude', 'gps_accuracy']),
      );
      expect(body['gps_accuracy'], 8.5);
    });

    test(
      'a create forwards the body unchanged, with no author on it',
      () async {
        client.reply = ApiEnvelope(message: '', data: activityJson());

        await repository.create(<String, Object?>{
          'site_id': 1,
          'project_id': 10,
          'report_date': '2026-09-28',
          'progress_percentage': 60,
        });

        final call = client.last!;
        expect(call.verb, 'POST');
        expect(call.path, '/site-activity-reports');
        expect(call.body, isA<Map<String, Object?>>());

        final body = call.body as Map<String, Object?>;
        expect(body, containsPair('site_id', 1));
        expect(body, isNot(contains('employee_id')));
        expect(body, isNot(contains('status')));
      },
    );

    test(
      'a response that is not an object is refused rather than guessed at',
      () async {
        client.reply = ApiEnvelope(message: '', data: <dynamic>[]);

        await expectLater(
          repository.find(7),
          throwsA(same(unexpectedShapeException)),
        );
      },
    );

    test(
      'photographs leave as one multipart batch of the frames given',
      () async {
        client.reply = ApiEnvelope(
          message: '',
          data: <dynamic>[
            <String, dynamic>{
              'id': 1,
              'sort_order': 0,
              'mime_type': 'image/jpeg',
            },
            <String, dynamic>{
              'id': 2,
              'sort_order': 1,
              'mime_type': 'image/jpeg',
            },
          ],
        );

        final photos = await repository.addPhotos(7, <Uint8List>[
          frame(),
          frame(),
        ], caption: 'The work front');

        expect(photos.map((photo) => photo.id), <int>[1, 2]);

        final call = client.last!;
        expect(call.verb, 'POST');
        expect(call.path, '/site-activity-reports/7/photos');
        expect(call.body, containsPair('caption', 'The work front'));

        final files = call.files!;
        expect(files.keys, <String>['photos']);

        final batch = files['photos'] as List<MultipartFile>;
        expect(batch, hasLength(2));
        expect(batch.first.filename, 'site-photo-1.jpg');
        expect(batch.last.filename, 'site-photo-2.jpg');
      },
    );

    test(
      'a bad upload shape is refused, not mapped onto empty evidence',
      () async {
        client.reply = ApiEnvelope(
          message: '',
          data: <String, dynamic>{
            'items': <dynamic>[],
            'meta': <String, dynamic>{},
          },
        );

        await expectLater(
          repository.addPhotos(7, <Uint8List>[frame()]),
          throwsA(same(unexpectedShapeException)),
        );
      },
    );

    test(
      'the site picker is its own endpoint, not the site directory',
      () async {
        client.reply = ApiEnvelope(
          message: '',
          data: <String, dynamic>{
            'items': <dynamic>[
              <String, dynamic>{
                'id': 1,
                'name': 'Block A',
                'code': 'S1',
                'project_id': 10,
                'project': <String, dynamic>{'name': 'Riverfront Towers'},
                'status': 'active',
              },
            ],
            'meta': <String, dynamic>{'current_page': 1, 'last_page': 1},
          },
        );

        final result = await repository.reportableSites(page: 1);

        expect(result.items.single.projectId, 10);
        expect(client.last!.path, '/site-activity-reports/reportable-sites');
      },
    );
  });

  group('the daily report over HTTP', () {
    late ScriptedApiClient client;
    late ApiDailySiteReportRepository repository;

    setUp(() {
      client = ScriptedApiClient();
      repository = ApiDailySiteReportRepository(client);
    });

    test('submit takes no body at all', () async {
      client.reply = ApiEnvelope(
        message: '',
        data: <String, dynamic>{
          'id': 4,
          'report_date': '2026-09-28',
          'total_manpower': 18,
          'status': 'submitted',
          'is_draft': false,
          'is_editable': false,
        },
      );

      final report = await repository.submit(4);

      expect(report.status, DailySiteReport.statusSubmitted);

      final call = client.last!;
      expect(call.verb, 'POST');
      expect(call.path, '/daily-site-reports/4/submit');
      expect(call.body, isNull);
    });

    test(
      'the PDF asks for bytes, and the envelope check is not in the way',
      () async {
        client.byteReply = Uint8List.fromList(const <int>[37, 80, 68, 70]);

        final bytes = await repository.pdf(4);

        expect(bytes, const <int>[37, 80, 68, 70]);

        final call = client.last!;
        expect(call.verb, 'BYTES');
        expect(call.path, '/daily-site-reports/4/pdf');
      },
    );

    test('rows go up as one batch under one key', () async {
      client.reply = ApiEnvelope(
        message: '',
        data: <dynamic>[
          <String, dynamic>{'id': 5, 'sort_order': 0},
        ],
      );

      await repository.addPhotos(4, <Uint8List>[frame(), frame()]);

      final batch = client.last!.files!['photos'] as List<MultipartFile>;
      expect(batch, hasLength(2));
      expect(client.last!.body, isNot(contains('caption')));
    });
  });
}
