<?php

namespace Database\Seeders;

use App\Models\AssetType;
use Illuminate\Database\Seeder;

class AssetTypeSeeder extends Seeder
{
    /**
     * The kinds of company asset this system ships knowing about.
     *
     * Matched by `code`, never by name — the same contract
     * DocumentTypeSeeder and TrainingTypeSeeder keep — and, like them, it
     * is a *vocabulary* rather than an inventory: nobody's laptop is
     * invented here, only the word a laptop is filed under.
     *
     * Nothing in the application branches on a type's `code`, so a tenth
     * kind is a row and a reworded kind is an update rather than a second
     * "Tool" sitting beside the first.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'LAPTOP',
                'name' => 'Laptop',
                'description' => 'Laptops, workstations and docking hardware.',
            ],
            [
                'code' => 'MOBILE_PHONE',
                'name' => 'Mobile Phone',
                'description' => 'Handsets and SIM-bearing devices.',
            ],
            [
                'code' => 'TABLET',
                'name' => 'Tablet',
                'description' => 'Tablets and slates.',
            ],
            [
                'code' => 'TOOL',
                'name' => 'Tool',
                'description' => 'Hand and power tools.',
            ],
            [
                'code' => 'SAFETY_EQUIPMENT',
                'name' => 'Safety Equipment',
                'description' => 'PPE, harnesses, gas detectors and fire equipment.',
            ],
            [
                'code' => 'MEASURING_EQUIPMENT',
                'name' => 'Measuring Equipment',
                'description' => 'Levels, theodolites, multimeters and test gear.',
            ],
            [
                'code' => 'OTHER',
                'name' => 'Other',
                'description' => 'Anything that fits nowhere above.',
            ],
        ];

        foreach ($types as $type) {
            AssetType::query()->updateOrCreate(
                ['code' => $type['code']],
                $type + ['status' => AssetType::STATUS_ACTIVE],
            );
        }
    }
}
