<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: permissions must exist before roles can be granted them.
     * `DevelopmentDataSeeder` is sample structure only — it creates no
     * employees, users or salaries.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SettingSeeder::class,
            // Workflows before leave types: a type may name its chain, so the
            // chain has to exist by the time the type is written.
            ApprovalWorkflowSeeder::class,
            LeaveTypeSeeder::class,
            // Categories before anything that might reference one — an
            // expense row cannot exist without a category to name.
            ExpenseCategorySeeder::class,
            // Document types before onboarding requirements: a requirement
            // is matched to its type by code, and throws rather than
            // writing a null foreign key if the type is missing.
            DocumentTypeSeeder::class,
            OnboardingRequirementSeeder::class,
            // Two more vocabularies: a training program must be able to
            // name its kind, and an asset must be able to name its kind,
            // both from a row rather than from a constant. Programs and
            // assets themselves are never seeded — they are offerings and
            // property a company decides about, not a shipped vocabulary.
            TrainingTypeSeeder::class,
            AssetTypeSeeder::class,
            DevelopmentDataSeeder::class,
        ]);
    }
}
