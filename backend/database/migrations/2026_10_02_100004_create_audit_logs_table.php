<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of every sensitive mutation.
     *
     * **Write-once.** There is no `updated_at`: an audit trail that can be
     * edited is an accusation nobody can answer, so the row is written
     * inside the same transaction as the change it describes and never
     * touched again. Eloquent's `const UPDATED_AT = null` on the model
     * enforces that from the application side as well.
     *
     * `old_values` / `new_values` are **redacted before they get here**.
     * AuditLogger strips passwords, tokens, file paths and bank details
     * first, so the table is safe to hand to an `audit.view` holder without
     * becoming a second copy of everything the rest of the system protects.
     *
     * `user_id` is nullable on purpose: a scheduled job has no caller, and
     * inventing one (or attributing it to whatever account the worker
     * happens to hold) would be worse than the honest null.
     *
     * `auditable_type` stores the full class name rather than a morph map,
     * because the audit trail outlives refactors of the models it points
     * at and a renamed class is a fact worth preserving.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 40)->index();
            $table->string('module', 40)->index();
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 400)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
