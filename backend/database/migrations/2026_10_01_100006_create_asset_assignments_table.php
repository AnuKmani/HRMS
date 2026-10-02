<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every hand-over of an asset, and every hand-back.
     *
     * **History is append-only and that is the point of the table.** A
     * return writes `returned_date`, `returned_condition` and `returned_by`
     * *onto the existing row* and flips `status` to `returned`; it never
     * deletes it, never opens a replacement, and never rewrites what the
     * asset was handed out in. So "who had this laptop in March, and what
     * condition did it leave in?" is answerable in November from the same
     * rows the March answer came from.
     *
     * There is therefore **no partial unique index** on "one active
     * assignment per asset" — MySQL does not have them, and a second column
     * (`returned_date IS NULL`) cannot be made unique. The invariant lives
     * in AssetService::assign() instead: a transaction, `lockForUpdate()`
     * on the asset, and a re-read of any active row *inside* the lock. Two
     * simultaneous assigns of the same asset both take the lock, the second
     * one waits, re-reads, and finds the assignment the first one wrote —
     * which is the same shape EmployeeTrainingService and the payroll
     * period lock use, and the same reason they can both promise "at most
     * one" rather than hoping for it.
     *
     * `assigned_condition` is required; `returned_condition` is not, because
     * an asset still out on hire has no return condition and filling it in
     * with a guess would be worse than an honest null.
     */
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets')
                ->restrictOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->date('assigned_date');
            $table->date('expected_return_date')->nullable();
            $table->date('returned_date')->nullable();

            $table->string('assigned_condition', 20)->default('good');
            $table->string('returned_condition', 20)->nullable();

            $table->foreignId('assigned_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('returned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // `active` | `returned`. Deliberately not `returned` +
            // `lost` + `damaged`: whether the asset came back is a fact
            // about the *hand-back*, and what condition it came back in is
            // already `returned_condition`. One question, one column.
            $table->string('status', 20)->default('active')->index();
            $table->string('remarks', 1000)->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index('assigned_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
};
