<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vocabulary of *kinds* of company asset.
     *
     * Same argument as `training_types`, and the same reason: "Laptop",
     * "Safety Equipment", "Measuring Equipment" are a company's words, not
     * this application's. `assets.asset_type_id` points here, so a kind can
     * be reworded or retired without touching a single asset row.
     */
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
