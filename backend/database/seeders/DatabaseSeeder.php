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
            DevelopmentDataSeeder::class,
        ]);
    }
}
