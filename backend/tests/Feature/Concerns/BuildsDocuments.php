<?php

namespace Tests\Feature\Concerns;

use App\Models\DocumentType;
use App\Models\Employee;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OnboardingRequirementSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\UploadedFile;

/**
 * The Phase 10 furniture: the catalogue, the checklist, and a way of
 * producing a document without repeating a four-field payload in every test.
 *
 * Six seeders, always in this order — permissions need roles, the onboarding
 * requirements need the document types they point at, and
 * OnboardingRequirementSeeder throws rather than write a requirement whose
 * `document_type_id` would be null forever. A test that seeded a subset
 * would then be asserting against a catalogue nobody installed.
 *
 * Deliberately *not* reusing `BuildsLeaveStack` or `BuildsExpenseStack`.
 * Those seed leave types, workflows and expense categories that this module
 * never reads, and a Phase 10 test that seeded them would be spending its
 * lines on furniture it is not proving anything about.
 */
trait BuildsDocuments
{
    use SignsInAccounts;

    protected function seedDocumentStack(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->seed(OnboardingRequirementSeeder::class);
    }

    protected function documentType(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->firstOrFail();
    }

    /**
     * The catalogue as configured — used to prove that the flags on the row,
     * rather than a constant in a service, decide what the form demands.
     */
    protected function reconfigureType(string $code, array $attributes): void
    {
        DocumentType::query()
            ->where('code', $code)
            ->update($attributes + ['updated_at' => now()]);
    }

    /**
     * A plausible PDF, in the shape leave certificates and expense receipts
     * already use: a signature inside the first kilobyte and nothing else.
     */
    protected function samplePdf(string $name = 'signed-contract.pdf', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $content ?? "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\nstartxref\n%%EOF\n",
        );
    }

    /**
     * A camera-roll JPEG. `image()` writes real JPEG bytes, so it goes
     * through the same decode/re-encode the sanitizer performs on an
     * attendance selfie — which is the point: an uploaded document must
     * survive exactly the same trip.
     */
    protected function photograph(string $name = 'passport-scan.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 160, 120);
    }

    /**
     * A well-formed PASSPORT payload: every field the type requires, and an
     * expiry far enough out that it reports `valid` rather than anything the
     * warning window has an opinion about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => $this->documentType('PASSPORT')->id,
            'document_number' => 'Z7654321',
            'issue_date' => '2024-06-01',
            'expiry_date' => '2030-06-01',
        ], $overrides);
    }

    /**
     * File a document through the API as whoever the caller currently is,
     * and hand back the decoded `data` object. A photograph is attached
     * unless the payload names one, because a document with no file is a
     * different subject from the one most of these tests are about.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function fileDocument(array $payload = [], ?Employee $for = null): array
    {
        $data = $payload ?: $this->documentPayload();

        if (! array_key_exists('file', $data)) {
            $data['file'] = $this->photograph();
        }

        if ($for !== null) {
            $data['employee_id'] = $for->id;
        }

        return $this->post('/api/v1/employee-documents', $data)
            ->assertCreated()
            ->json('data');
    }

    /**
     * Everything an employee record has to hold before the two `data`
     * requirements count as satisfied. Written explicitly rather than left to
     * `optional()` in the factory, because a test whose outcome depended on
     * whether faker happened to fill in a phone number would be a coin flip.
     *
     * @return array<string, mixed>
     */
    protected function completePersonalDetails(): array
    {
        return [
            'phone' => '+971500000001',
            'nationality' => 'Indian',
            'photo_path' => 'employee-photos/profile.jpg',
        ];
    }

    /**
     * Put all eight seeded requirements in place for one person, through the
     * API rather than around it — the point is to reach the state, and a
     * fixture written straight into the tables would happily "complete" an
     * onboarding that the endpoints refuse.
     *
     * The caller must already be an HR seat (they need `documents.manage`,
     * `documents.verify` and `onboarding.manage`), and `$employee` must
     * *not* be that seat's own row: nobody signs off on their own paperwork,
     * which is a rule this helper would otherwise trip over in a confusing
     * place.
     */
    protected function satisfyEveryRequirementFor(Employee $employee): void
    {
        Employee::query()->whereKey($employee->id)->update($this->completePersonalDetails());

        foreach (['PASSPORT', 'EMIRATES_ID', 'VISA', 'EMPLOYMENT_CONTRACT', 'CERTIFICATE'] as $code) {
            $document = $this->fileDocument([
                'document_type_id' => $this->documentType($code)->id,
                'document_number' => 'REF-'.$code,
                'issue_date' => '2024-01-01',
                'expiry_date' => $code === 'EMPLOYMENT_CONTRACT' || $code === 'CERTIFICATE'
                    ? null
                    : '2030-01-01',
            ], $employee);

            $this->postJson('/api/v1/employee-documents/'.$document['id'].'/verify')->assertOk();
        }

        $this->putJson('/api/v1/employees/'.$employee->id.'/bank-account', [
            'account_holder_name' => $employee->first_name.' '.$employee->last_name,
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE070331234567890123456',
            'currency' => 'AED',
        ])->assertOk();
    }
}
