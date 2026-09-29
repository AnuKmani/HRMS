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
            DevelopmentDataSeeder::class,
        ]);
    }
}
