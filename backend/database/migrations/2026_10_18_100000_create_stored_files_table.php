<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File contents for the `database` storage driver — used where the host has
 * no lasting disk (Vercel). Unused, and empty, everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->string('path', 500)->primary();
            $table->longText('contents');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('last_modified')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
