<?php

namespace Tests\Feature\Concerns;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\TrainingProgram;
use App\Models\TrainingType;
use App\Models\User;
use Database\Seeders\AssetTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\TrainingTypeSeeder;
use Illuminate\Http\UploadedFile;

/**
 * The Phase 11 furniture: two vocabularies, a catalogue row, an asset, and
 * a way of producing an enrolment without repeating a five-field payload in
 * every test.
 *
 * Six seeders, always in this order — permissions need roles, and both new
 * modules need the vocabularies their forms are built from. Deliberately
 * *not* reusing `BuildsDocuments`: that seeds the document types and the
 * onboarding requirements this module never reads, and a Phase 11 test that
 * seeded them would be spending its lines on furniture it is not proving
 * anything about. `BuildsLeaveStack` and `BuildsExpenseStack` are excluded
 * for the same reason.
 *
 * **No program and no asset is invented by the seeder**, because the seeder
 * does not have either. The two seeded tables are *vocabularies* — the
 * words a catalogue entry is filed under — and a program is an offering
 * somebody chose to run. So every helper here writes one row, explicitly,
 * with numbers the caller can see.
 */
trait BuildsTrainingAssets
{
    use SignsInAccounts;

    protected function seedTrainingAssetStack(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(TrainingTypeSeeder::class);
        $this->seed(AssetTypeSeeder::class);
    }

    /* --------------------------------------------------------- vocabularies */

    protected function trainingType(string $code): TrainingType
    {
        return TrainingType::query()->where('code', $code)->firstOrFail();
    }

    protected function assetType(string $code): AssetType
    {
        return AssetType::query()->where('code', $code)->firstOrFail();
    }

    /* ------------------------------------------------------------ catalogue */

    /**
     * A program, written straight into the table rather than through the
     * API — most tests need a course to exist before they can say anything
     * interesting about sitting it, and the API's own catalogue behaviour
     * is covered in its own tests.
     *
     * The defaults are chosen so that *nothing about expiry is ambiguous*:
     * a certificate, valid for a year, and nothing here dates it — the row
     * that carries dates is the enrolment, which is where a test that cares
     * about the warning window builds one.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTrainingProgram(array $overrides = []): TrainingProgram
    {
        $sequence = TrainingProgram::query()->count() + 1;

        return TrainingProgram::query()->create($overrides + [
            'training_type_id' => $this->trainingType('HSE')->id,
            'code' => 'HSE-101-'.$sequence,
            'name' => 'HSE Level 1',
            'provider' => 'Gulf Safety',
            'duration_days' => 2,
            'certificate_required' => true,
            'certificate_validity_days' => 365,
            'status' => TrainingProgram::STATUS_ACTIVE,
        ]);
    }

    /**
     * An asset, with a code nobody has used yet.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAsset(array $overrides = []): Asset
    {
        $sequence = Asset::query()->count() + 1;

        return Asset::query()->create($overrides + [
            'asset_code' => 'AST-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'asset_type_id' => $this->assetType('LAPTOP')->id,
            'name' => 'ThinkPad T14',
            'manufacturer' => 'Lenovo',
            'model' => 'T14 Gen 3',
            'purchase_date' => '2025-01-15',
            'purchase_cost' => 4200.00,
            'current_condition' => Asset::CONDITION_GOOD,
            'status' => Asset::STATUS_AVAILABLE,
        ]);
    }

    /**
     * A hand-over written straight into the table, for the tests that need
     * a *particular* history — including the one that plants an active row
     * while the asset still says `available`, which is precisely the state
     * a status column alone would miss and the row lock exists to catch.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAssignment(
        Asset $asset,
        Employee $employee,
        array $overrides = [],
    ): AssetAssignment {
        return AssetAssignment::query()->create($overrides + [
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_date' => now()->toDateString(),
            'assigned_condition' => $asset->current_condition,
            'assigned_by' => $this->anyUserId(),
            'status' => AssetAssignment::STATUS_ACTIVE,
        ]);
    }

    /**
     * Any user at all, for the rows that record *who* and where no caller
     * has signed in. Created on first use so a test that never attributes a
     * hand-over never pays for one.
     */
    protected function anyUserId(): int
    {
        return User::query()->first()?->id ?? User::factory()->create()->id;
    }

    /* ---------------------------------------------------------- enrolments */

    /**
     * An enrolment written straight into the table.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTraining(
        Employee $employee,
        TrainingProgram $program,
        array $overrides = [],
    ): EmployeeTraining {
        return EmployeeTraining::query()->create($overrides + [
            'employee_id' => $employee->id,
            'training_program_id' => $program->id,
            'enrollment_date' => now()->toDateString(),
            'status' => EmployeeTraining::STATUS_ENROLLED,
            'created_by' => $this->anyUserId(),
        ]);
    }

    /**
     * A payload that satisfies every rule StoreEmployeeTrainingRequest has.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function enrollPayload(
        Employee $employee,
        TrainingProgram $program,
        array $overrides = [],
    ): array {
        // `$overrides` on the **left**: PHP's `+` keeps the operand it is
        // called on, so `$defaults + $overrides` silently lets a default
        // win every time the two share a key — which is exactly how a test
        // meant to file under a *retired* type ends up filing under the
        // seeded active one and asserting a 422 it never earned.
        return $overrides + [
            'employee_id' => $employee->id,
            'training_program_id' => $program->id,
            'enrollment_date' => now()->toDateString(),
        ];
    }

    /**
     * A payload that satisfies every rule StoreAssetRequest has.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function assetPayload(array $overrides = []): array
    {
        $sequence = Asset::query()->count() + 1;

        return $overrides + [
            'asset_code' => 'NEW-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'asset_type_id' => $this->assetType('LAPTOP')->id,
            'name' => 'Dell Latitude 5540',
            'purchase_cost' => 3800.00,
        ];
    }

    /**
     * A payload that satisfies every rule StoreTrainingProgramRequest has.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function programPayload(array $overrides = []): array
    {
        $sequence = TrainingProgram::query()->count() + 1;

        return $overrides + [
            'training_type_id' => $this->trainingType('WORKING_AT_HEIGHTS')->id,
            'code' => 'WAH-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Working at Heights',
            'provider' => 'Gulf Safety',
            'duration_days' => 1,
            'certificate_required' => true,
            'certificate_validity_days' => 365,
        ];
    }

    /* ---------------------------------------------------------------- files */

    /**
     * A plausible PDF, in the shape leave certificates and employment
     * documents already use: a signature inside the first kilobyte and
     * nothing else. The same bytes, so a training certificate is proved to
     * travel through the same EmployeeDocumentStore a passport does.
     */
    protected function certificatePdf(string $name = 'working-at-heights.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\nstartxref\n%%EOF\n",
        );
    }
}
