<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts attached to an expense — one row per file, and only facts
     * about a *private* file. Never a URL, never a public disk key, never
     * the bytes.
     *
     * `path` is a name under `storage/app/private/expense-receipts/`
     * minted by ExpenseReceiptStore from a UUID, never from the client's
     * filename. Nothing in any API response ever echoes it: the receipt
     * resource reports `id`, `original_name`, `mime_type` and
     * `size_bytes`, and the only way to the bytes is
     * GET /api/v1/expenses/{expense}/receipts/{receipt}, behind the
     * expense's own policy. `original_name` is kept because a person
     * recognises "IMG_0142.jpg" faster than a uuid, and it is stored as
     * data only — it is never used to open, serve or list anything.
     *
     * `mime_type` and `size_bytes` are recorded at upload time rather
     * than re-probed on every read: both are decided once, in
     * ExpenseReceiptStore, which is the only writer.
     *
     * `expense_id` cascades because a receipt is meaningless without the
     * claim it belongs to — unlike a certificate, which hangs off a
     * request that is never deleted either. Expenses themselves have no
     * delete endpoint: the lifecycle ends at cancelled or rejected, so
     * history remains readable.
     */
    public function up(): void
    {
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expense_id')
                ->constrained('expenses')
                ->cascadeOnDelete();

            $table->string('path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->foreignId('uploaded_by')
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
        Schema::dropIfExists('expense_receipts');
    }
};
