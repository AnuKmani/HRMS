<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\OnboardingRequirement;
use Illuminate\Database\Seeder;

class OnboardingRequirementSeeder extends Seeder
{
    /**
     * What an employment file has to be made of before onboarding is done.
     *
     * Eight requirements covering the three ways one can be satisfied —
     * a document on file (`kind = document`), fields filled in on the
     * employee record (`kind = data`), and a bank account recorded
     * (`kind = bank`). See OnboardingRequirement and OnboardingService for
     * what each judgement actually reads.
     *
     * Deliberately *not* columns on `employees`. A row here can be retired
     * without touching anybody's record, a new one can be added without a
     * migration, and "why is this outstanding?" has an answer —
     * `missing`, `pending_verification`, `rejected` or `expired` — that a
     * boolean could never give.
     *
     * Document requirements are matched to their type by code rather than
     * by a hard-coded id, so this seeder can be run against any install in
     * any order of ids. It must run *after* DocumentTypeSeeder; the lookup
     * below fails loudly rather than silently writing a null
     * `document_type_id` that would make the requirement unsatisfiable
     * forever.
     *
     * `is_mandatory` is true for all eight because the spec calls them
     * required items — but it is a column, so an operator who decides a
     * particular workforce need not produce a training certificate flips
     * one row instead of editing a service.
     *
     * Re-running this seeder overwrites values an operator changed, for the
     * reason DocumentTypeSeeder gives.
     */
    public function run(): void
    {
        $requirements = [
            [
                'code' => 'personal_information',
                'name' => 'Personal information',
                'description' => 'Name, contact details, date of birth, nationality, joining date, department and designation.',
                'kind' => OnboardingRequirement::KIND_DATA,
                'document_type' => null,
                'employee_fields' => 'first_name,last_name,email,phone,date_of_birth,nationality,joining_date,department_id,designation_id',
            ],
            [
                'code' => 'passport',
                'name' => 'Passport',
                'description' => 'A verified copy of the employee passport.',
                'kind' => OnboardingRequirement::KIND_DOCUMENT,
                'document_type' => 'PASSPORT',
                'employee_fields' => null,
            ],
            [
                'code' => 'emirates_id',
                'name' => 'Emirates ID',
                'description' => 'A verified copy of the Emirates ID, both sides.',
                'kind' => OnboardingRequirement::KIND_DOCUMENT,
                'document_type' => 'EMIRATES_ID',
                'employee_fields' => null,
            ],
            [
                'code' => 'visa',
                'name' => 'Visa',
                'description' => 'A verified copy of the residence or employment visa.',
                'kind' => OnboardingRequirement::KIND_DOCUMENT,
                'document_type' => 'VISA',
                'employee_fields' => null,
            ],
            [
                'code' => 'employment_contract',
                'name' => 'Employment contract',
                'description' => 'The signed contract of employment, on file.',
                'kind' => OnboardingRequirement::KIND_DOCUMENT,
                'document_type' => 'EMPLOYMENT_CONTRACT',
                'employee_fields' => null,
            ],
            [
                'code' => 'bank_information',
                'name' => 'Bank information',
                'description' => 'The account salary is paid into. Recorded by HR — never part of the employee record itself.',
                'kind' => OnboardingRequirement::KIND_BANK,
                'document_type' => null,
                'employee_fields' => null,
            ],
            [
                'code' => 'employee_photo',
                'name' => 'Employee photo',
                'description' => 'A photograph of the employee for their record.',
                'kind' => OnboardingRequirement::KIND_DATA,
                'document_type' => null,
                'employee_fields' => 'photo_path',
            ],
            [
                'code' => 'certificates',
                'name' => 'Certificates',
                'description' => 'Education, experience or qualification certificates, verified.',
                'kind' => OnboardingRequirement::KIND_DOCUMENT,
                'document_type' => 'CERTIFICATE',
                'employee_fields' => null,
            ],
        ];

        foreach ($requirements as $order => $requirement) {
            $documentTypeId = null;

            if ($requirement['document_type'] !== null) {
                $documentTypeId = DocumentType::query()
                    ->where('code', $requirement['document_type'])
                    ->value('id');

                if ($documentTypeId === null) {
                    // Loudly, not silently: a requirement pointing at no
                    // type can never be satisfied, and an onboarding that
                    // can never complete is a bug nobody would trace back
                    // to a missing seeder call.
                    throw new \RuntimeException(
                        "Document type {$requirement['document_type']} must be seeded before onboarding requirements.",
                    );
                }
            }

            OnboardingRequirement::query()->updateOrCreate(
                ['code' => $requirement['code']],
                [
                    'name' => $requirement['name'],
                    'description' => $requirement['description'],
                    'kind' => $requirement['kind'],
                    'document_type_id' => $documentTypeId,
                    'employee_fields' => $requirement['employee_fields'],
                    'is_mandatory' => true,
                    'sort_order' => ($order + 1) * 10,
                    'status' => OnboardingRequirement::STATUS_ACTIVE,
                ],
            );
        }
    }
}
