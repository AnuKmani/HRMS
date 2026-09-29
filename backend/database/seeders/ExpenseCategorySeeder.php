<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * The expense categories the system ships with.
     *
     * Looked up by `code`, never by name, so re-seeding updates a label an
     * operator has reworded rather than adding a second "Travel".
     *
     * The two per-category rules are deliberately *not* uniform, because
     * the argument for both answers is true of the same system:
     *
     *   requires_receipt  Travel, Transport, Site Expense and
     *                     Accommodation are money that was handed over
     *                     somewhere — a fare, a bill, a docket — so a
     *                     claim without evidence is refused at submit.
     *                     Food and Other are the two cases where the
     *                     amount and the employee's own description are
     *                     the whole record.
     *
     *   maximum_amount    One ceiling, on Food, as a worked example of the
     *                     field rather than as a policy this application
     *                     has opinions about: an operator is expected to
     *                     set their own. NULL everywhere else means "no
     *                     ceiling", which is not the same as 0.
     *
     * Values here are configuration, not business rules: every one of them
     * is read back from the table by ExpenseService at runtime.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'TRAVEL',
                'name' => 'Travel',
                'description' => 'Travel costs — flights, fares and trips made for work.',
                'requires_receipt' => true,
                'maximum_amount' => null,
            ],
            [
                'code' => 'TRANSPORT',
                'name' => 'Transport',
                'description' => 'Local transport — taxis, fuel and vehicle hire.',
                'requires_receipt' => true,
                'maximum_amount' => null,
            ],
            [
                'code' => 'SITE',
                'name' => 'Site Expense',
                'description' => 'Costs incurred for a site — materials, tools and site services.',
                'requires_receipt' => true,
                'maximum_amount' => null,
            ],
            [
                'code' => 'FOOD',
                'name' => 'Food',
                'description' => 'Meals and refreshments. Sample ceiling — change it to suit your policy.',
                'requires_receipt' => false,
                'maximum_amount' => '1000.00',
            ],
            [
                'code' => 'ACCOMMODATION',
                'name' => 'Accommodation',
                'description' => 'Lodging and overnight stays.',
                'requires_receipt' => true,
                'maximum_amount' => null,
            ],
            [
                'code' => 'OTHER',
                'name' => 'Other',
                'description' => 'Anything that fits nowhere above.',
                'requires_receipt' => false,
                'maximum_amount' => null,
            ],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                $category + ['status' => ExpenseCategory::STATUS_ACTIVE],
            );
        }
    }
}
