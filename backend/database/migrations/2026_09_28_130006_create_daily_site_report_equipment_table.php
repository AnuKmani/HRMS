<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipment used on a site-day — one row per item or type.
     *
     * Deliberately *not* an asset register: there is no asset tag, no
     * maintenance schedule, no depreciation and no ownership. This table
     * answers exactly two questions a daily report raises — what was on
     * site, and did it run — and nothing else, so Phase 8 can add a real
     * asset module without inheriting a half-built one that a site's daily
     * numbers already depend on.
     *
     * `operating_hours` is a meter reading, nullable because a concrete
     * pump that broke down at 10:00 has hours and a generator that was only
     * parked has none. `condition` is a free string for the same reason the
     * manpower category is: the vocabulary belongs to the plant.
     */
    public function up(): void
    {
        Schema::create('daily_site_report_equipment', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_site_report_id')
                ->constrained('daily_site_reports')
                ->cascadeOnDelete();

            $table->string('equipment_name', 150);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('operating_hours', 10, 2)->nullable();
            $table->string('condition', 30)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['daily_site_report_id', 'sort_order'], 'dsreq_report_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_site_report_equipment');
    }
};
