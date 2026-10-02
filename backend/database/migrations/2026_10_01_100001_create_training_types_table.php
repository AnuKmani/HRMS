<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vocabulary of *kinds* of training.
     *
     * A reference table rather than a PHP enum or a validation `in:` list,
     * for the same reason `document_types` is a table: "Safety Induction",
     * "HSE Training", "Working at Heights" are words a company edits, not
     * facts a deployer's code knows. Adding a kind is an insert; nothing in
     * this repository has to be recompiled to accept one.
     *
     * Programs point *at* these rows (`training_programs.training_type_id`),
     * so a program is "a Working at Heights course run in March" and the
     * type survives the cohort. Deliberately separate from the program:
     * the type is the category, the program is the offering.
     *
     * `code` is the stable identity and is what the seeder matches on, so a
     * label an operator has reworded is updated rather than duplicated.
     */
    public function up(): void
    {
        Schema::create('training_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // `active` | `retired`. Retired keeps the historical rows that
            // name it readable while taking it out of every new form's
            // picker — the same two-state rule document_types uses.
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_types');
    }
};
