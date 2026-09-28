<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materials consumed on a site-day — one row per line item.
     *
     * Normalised because a daily report is read comparatively: "cement used
     * on Tuesday vs Wednesday" is a query, and a comma-joined note cannot be
     * asked a question. Deliberately *not* an inventory: there is no stock
     * level, no part number, no supplier, no valuation and no link to
     * anything else in the system, because Phase 7 records what a site
     * consumed on a day and nothing more. `material_name` is free text for
     * the same reason the manpower category is — the vocabulary belongs to
     * the trade, not to the schema.
     *
     * DECIMAL(12,3) rather than an integer: three tonnes and two hundred
     * and fifty kilos of aggregate are both real quantities on the same day,
     * and rounding one of them to keep a column tidy is a wrong number in a
     * document somebody will read back.
     */
    public function up(): void
    {
        Schema::create('daily_site_report_materials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_site_report_id')
                ->constrained('daily_site_reports')
                ->cascadeOnDelete();

            $table->string('material_name', 150);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('unit', 30);
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['daily_site_report_id', 'sort_order'], 'dsrmat_report_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_site_report_materials');
    }
};
