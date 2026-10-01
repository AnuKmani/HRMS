import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/onboarding/domain/onboarding.dart';

import '../../../support/phase10.dart';

/// The two things the onboarding module must not lose: a list row and a
/// detail row are different payloads, and the five checklist states are five
/// different people's problem.
void main() {
  group('a directory row', () {
    test('a starter nobody has begun is `Not started`, not a missing row', () {
      final record = Onboarding.fromJson(
        onboardingRow(
          employeeId: 4,
          status: Onboarding.statusDraft,
          exists: false,
        ),
      );

      expect(record.exists, isFalse);
      expect(record.statusLabel, 'Not started');
      expect(record.summaryLabel, 'Not started');
      expect(record.hasChecklist, isFalse);
    });

    test('a row with no checklist reports no progress, never zero of zero', () {
      final record = Onboarding.fromJson(onboardingRow(employeeId: 4));

      // A `0 of 0` underneath a directory of joiners would read as "all
      // done" — the exact opposite of "not yet asked".
      expect(record.total, isNull);
      expect(record.satisfied, isNull);
      expect(record.hasProgress, isFalse);
      expect(record.progress, isNull);
      expect(record.progressLabel, '');
    });

    test('each stage has its own sentence', () {
      String labelOf(String status) =>
          Onboarding.fromJson(onboardingRow(employeeId: 4, status: status))
              .statusLabel;

      expect(labelOf(Onboarding.statusDraft), 'Not started');
      expect(
        labelOf(Onboarding.statusPendingDocuments),
        'Waiting on documents',
      );
      expect(labelOf(Onboarding.statusHrReview), 'HR review');
      expect(labelOf(Onboarding.statusCompleted), 'Completed');
    });
  });

  group('a detail payload', () {
    test('progress is a fraction of the totals the server sent', () {
      final record = Onboarding.fromJson(
        onboardingRow(employeeId: 4, total: 8, satisfied: 5),
      );

      expect(record.hasChecklist, isTrue);
      expect(record.progress, 0.625);
      expect(record.progressLabel, '5 of 8 requirements met');
    });

    test('the four stages are all listed, so a filter can name them', () {
      expect(
        Onboarding.statuses,
        containsAll(<String>[
          Onboarding.statusDraft,
          Onboarding.statusPendingDocuments,
          Onboarding.statusHrReview,
          Onboarding.statusCompleted,
        ]),
      );
    });
  });

  group('a checklist line', () {
    test('the five states read as five different answers', () {
      String labelOf(String state) => OnboardingChecklistItem.fromJson(
        checklistItem(code: 'passport', name: 'Passport', state: state),
      ).stateLabel;

      expect(labelOf(OnboardingChecklistItem.stateMissing), 'Missing');
      expect(
        labelOf(OnboardingChecklistItem.statePendingVerification),
        'Awaiting verification',
      );
      expect(labelOf(OnboardingChecklistItem.stateRejected), 'Rejected');
      expect(labelOf(OnboardingChecklistItem.stateExpired), 'Expired');
      expect(labelOf(OnboardingChecklistItem.stateSatisfied), 'Met');
    });

    test('a data requirement names the columns still empty', () {
      final item = OnboardingChecklistItem.fromJson(
        checklistItem(
          code: 'personal_information',
          name: 'Personal information',
          kind: OnboardingChecklistItem.kindData,
          state: OnboardingChecklistItem.stateMissing,
          missingFields: const <String>['phone', 'nationality'],
        ),
      );

      // "Personal information" alone is an unexplained red chip; naming the
      // two columns turns it into something a person can fix.
      expect(item.stateDetail, 'phone, nationality');
    });

    test('a rejected requirement shows why, from the document', () {
      final item = OnboardingChecklistItem.fromJson(
        checklistItem(
          code: 'emirates_id',
          name: 'Emirates ID',
          state: OnboardingChecklistItem.stateRejected,
          document: documentRow(
            id: 3,
            typeCode: 'EMIRATES_ID',
            typeName: 'Emirates ID',
            status: 'rejected',
            rejectionReason: 'The back of the card was cut off.',
          ),
        ),
      );

      expect(item.isRejected, isTrue);
      expect(item.stateDetail, 'The back of the card was cut off.');
      expect(item.document?.typeName, 'Emirates ID');
    });

    test('an expired requirement borrows the document\'s date words', () {
      final item = OnboardingChecklistItem.fromJson(
        checklistItem(
          code: 'visa',
          name: 'Visa',
          state: OnboardingChecklistItem.stateExpired,
          document: documentRow(
            id: 4,
            typeCode: 'VISA',
            typeName: 'Visa',
            status: 'expired',
            expiry: '2026-08-01',
            expiryState: 'expired',
            daysUntil: -61,
          ),
        ),
      );

      expect(item.isExpired, isTrue);
      expect(item.stateDetail, 'Expired 61 days ago');
    });

    test('a satisfied requirement has nothing to explain', () {
      final item = OnboardingChecklistItem.fromJson(
        checklistItem(
          code: 'employment_contract',
          name: 'Employment contract',
          state: OnboardingChecklistItem.stateSatisfied,
        ),
      );

      expect(item.isSatisfied, isTrue);
      expect(item.stateDetail, '');
    });
  });
}
