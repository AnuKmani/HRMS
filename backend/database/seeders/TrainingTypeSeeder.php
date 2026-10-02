<?php

namespace Database\Seeders;

use App\Models\TrainingType;
use Illuminate\Database\Seeder;

class TrainingTypeSeeder extends Seeder
{
    /**
     * The kinds of training this system ships knowing about.
     *
     * Matched by `code`, never by name, so re-seeding updates a label an
     * operator has reworded rather than adding a second "Working at
     * Heights". The eight rows below are a *vocabulary* — a starting
     * vocabulary, not a closed one: adding "Confined Space Entry" is an
     * insert, and nothing in this repository has to be recompiled to accept
     * it, because no screen and no service ever branches on a type's code.
     *
     * Deliberately *not* a set of programs. These are the categories; a
     * program is an offering somebody chose to run ("Working at Heights,
     * March cohort, run by Gulf Safety"), and inventing a catalogue of
     * those would put rows in a fresh installation that nobody there had
     * decided on. HR creates programs through `POST /api/v1/training-programs`.
     *
     * Re-running this seeder *does* overwrite a value an operator changed —
     * the same trade-off DocumentTypeSeeder makes, and for the same reason:
     * a seeder that silently skips a row it already wrote can never bring a
     * drifted install back to the shipped default.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'SAFETY_INDUCTION',
                'name' => 'Safety Induction',
                'description' => 'Site and workplace safety induction.',
            ],
            [
                'code' => 'HSE',
                'name' => 'HSE Training',
                'description' => 'Health, safety and environment training.',
            ],
            [
                'code' => 'WORKING_AT_HEIGHTS',
                'name' => 'Working at Heights',
                'description' => 'Harnesses, ladders, scaffolds and fall protection.',
            ],
            [
                'code' => 'FIRST_AID',
                'name' => 'First Aid',
                'description' => 'First aid, CPR and emergency response.',
            ],
            [
                'code' => 'ELECTRICAL_SAFETY',
                'name' => 'Electrical Safety',
                'description' => 'Isolation, lock-out/tag-out and safe electrical work.',
            ],
            [
                'code' => 'EQUIPMENT_CERTIFICATION',
                'name' => 'Equipment Certification',
                'description' => 'Certification to operate a specific class of equipment.',
            ],
            [
                'code' => 'TECHNICAL',
                'name' => 'Technical Training',
                'description' => 'Trade, method and standards training.',
            ],
            [
                'code' => 'OTHER',
                'name' => 'Other',
                'description' => 'Anything that fits nowhere above.',
            ],
        ];

        foreach ($types as $type) {
            TrainingType::query()->updateOrCreate(
                ['code' => $type['code']],
                $type + ['status' => TrainingType::STATUS_ACTIVE],
            );
        }
    }
}
