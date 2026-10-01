<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where somebody gets paid — deliberately its own table.
     *
     * Bank details are the one piece of onboarding data that is *not* on the
     * employee record, and the reason is not tidiness. `EmployeeResource` is
     * returned by endpoints a great many roles can reach: the directory, the
     * timesheet pickers, the approval screens. A column that has to be
     * remembered-not-to-echoed in every one of those is a column that will
     * one day be echoed, so the answer is that the value does not live there
     * at all.
     *
     * Same reasoning for the routes: `GET` and `PUT`
     * `/api/v1/employees/{employee}/bank-account` carry **no coarse
     * `permission:` gate** and are decided entirely by
     * EmployeeBankAccountPolicy, in the same way `GET /employees/{id}`
     * is — the gate has to admit an ordinary employee reading their own
     * record, which is exactly the case where a `permission:` middleware
     * would have to be absent and the policy therefore has to be present.
     *
     * Two columns are `encrypted` casts rather than plain text. A database
     * dump, a leaked backup or a copy of `hrms_laravel` taken for debugging
     * should not hand over everybody's account numbers, and the cost of
     * that is one operational rule: **APP_KEY must not be rotated casually**,
     * because the value cannot be decrypted without it. See
     * docs/SECURITY.md §"Bank details".
     *
     * One row per employee (`employee_id` unique). A second account for the
     * same person is out of scope for Phase 10, and a unique index that says
     * so is better than a `is_primary` flag two rows could both claim.
     */
    public function up(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->unique()
                ->constrained('employees')
                ->restrictOnDelete();

            $table->string('account_holder_name', 150);
            $table->string('bank_name', 150);

            // Encrypted at rest. Sized generously: the stored value is
            // ciphertext + MAC + base64, not the 34 characters typed in.
            $table->string('iban', 255);
            $table->string('account_number', 255)->nullable();
            $table->string('swift_bic', 255)->nullable();

            $table->string('currency', 3)->default('AED');
            $table->string('status', 20)->default('active');

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
    }
};
