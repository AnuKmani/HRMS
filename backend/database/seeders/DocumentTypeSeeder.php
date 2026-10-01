<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * The document types the system ships with.
     *
     * Looked up by `code`, never by name, so re-seeding updates a label an
     * operator has reworded rather than adding a second "Emirates ID".
     *
     * The three `requires_*` flags and the warning window are deliberately
     * *not* uniform, because they are properties of the document rather than
     * of this seeder: a passport has a number, an issue date and an expiry
     * that somebody should hear about well in advance; a certificate has
     * none of the first and usually no expiry at all. Changing Emirates ID
     * notice from 60 to 45 days is an edit to one row, not to a service.
     *
     * Values here are configuration, not business rules: every one of them
     * is read back from the table at runtime by EmployeeDocumentService,
     * DocumentExpiryService and the onboarding checklist.
     *
     * Re-running this seeder *does* overwrite a value an operator changed —
     * the same trade-off SettingSeeder makes, and for the same reason: a
     * seeder that silently skips a row it already wrote cannot ever bring a
     * drifted install back to the shipped default.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'PASSPORT',
                'name' => 'Passport',
                'description' => 'National passport — the primary identity document.',
                'requires_document_number' => true,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 180,
            ],
            [
                'code' => 'EMIRATES_ID',
                'name' => 'Emirates ID',
                'description' => 'UAE national identity card.',
                'requires_document_number' => true,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 90,
            ],
            [
                'code' => 'VISA',
                'name' => 'Visa',
                'description' => 'Residence or employment visa.',
                'requires_document_number' => true,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 90,
            ],
            [
                'code' => 'EMPLOYMENT_CONTRACT',
                'name' => 'Employment Contract',
                'description' => 'The signed contract of employment.',
                'requires_document_number' => false,
                'requires_issue_date' => true,
                'requires_expiry_date' => false,
                'expiry_warning_days' => 0,
            ],
            [
                'code' => 'LABOUR_DOCUMENTS',
                'name' => 'Labour Documents',
                'description' => 'Labour card, offer letter and related permits.',
                'requires_document_number' => true,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 60,
            ],
            [
                'code' => 'CERTIFICATE',
                'name' => 'Certificate',
                'description' => 'Education, experience or qualification certificate.',
                'requires_document_number' => false,
                'requires_issue_date' => true,
                'requires_expiry_date' => false,
                'expiry_warning_days' => 0,
            ],
            [
                'code' => 'MEDICAL_DOCUMENT',
                'name' => 'Medical Document',
                'description' => 'Medical fitness certificate and health records.',
                'requires_document_number' => false,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 30,
            ],
            [
                'code' => 'TRAINING_CERTIFICATE',
                'name' => 'Training Certificate',
                'description' => 'Safety induction, certification and course completion.',
                'requires_document_number' => false,
                'requires_issue_date' => true,
                'requires_expiry_date' => true,
                'expiry_warning_days' => 60,
            ],
            [
                'code' => 'OTHER',
                'name' => 'Other',
                'description' => 'Anything that fits nowhere above.',
                'requires_document_number' => false,
                'requires_issue_date' => false,
                'requires_expiry_date' => false,
                'expiry_warning_days' => 0,
            ],
        ];

        foreach ($types as $order => $type) {
            DocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                $type + [
                    'status' => DocumentType::STATUS_ACTIVE,
                    'sort_order' => ($order + 1) * 10,
                ],
            );
        }
    }
}
