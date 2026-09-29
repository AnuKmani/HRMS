<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense categories — the configurable half of an expense claim.
     *
     * Nothing about a category is hard-coded anywhere else in the app:
     * "does this kind of claim need a receipt?", "what is the ceiling on
     * this kind of claim?", "is this kind even available?" are all read
     * from this table by ExpenseService at create, update and submit time.
     * Adding a category is therefore a row, not a deploy.
     *
     * `code` is the stable handle a report or an integration keys off —
     * names get reworded ("Site Expense" -> "Site & Material"), codes do
     * not, and the unique index is what lets a seeder be re-run without
     * duplicating a category it already wrote.
     *
     * `requires_receipt` is a per-category rule rather than a global one
     * because the two halves of the same argument are both true: a taxi
     * fare needs paper, a per-diem allowance does not, and one boolean
     * cannot answer both.
     *
     * `maximum_amount` is NULLable on purpose — "no ceiling" is a real
     * answer (Travel has one) and 0 would read as "nothing may be
     * claimed". DECIMAL(12,2) rather than a float, exactly as every other
     * money column in this database: money is not a binary fraction.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->string('description', 500)->nullable();

            // active | inactive. An inactive category cannot be chosen for
            // a new claim; categories already used by history stay put.
            $table->string('status', 20)->default('active')->index();

            $table->boolean('requires_receipt')->default(false);
            $table->decimal('maximum_amount', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
