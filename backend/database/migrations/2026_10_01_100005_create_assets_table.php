<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One piece of company property.
     *
     * Two vocabularies on the row, deliberately separate, because they
     * answer different questions:
     *
     *  - **`status`** is where the asset sits in its lifecycle —
     *    `available`, `assigned`, `maintenance`, `damaged`, `lost`,
     *    `retired`. It is what the next action is *allowed to be*, and
     *    AssetService::changeStatus() is the only thing that writes it.
     *  - **`current_condition`** is what condition it is physically in —
     *    `new`, `good`, `fair`, `poor`. An asset can be `assigned` and
     *    `poor`, or `available` and `new`. Keeping them apart is what lets
     *    a return record "came back scratched but usable" without inventing
     *    a status that means two things.
     *
     * **`asset_code` is the barcode, eventually.** It is unique, it is
     * assigned once and never reissued (a retired laptop's code is not
     * handed to the next one — that is how an audit trail stops being an
     * audit trail), and it is a plain `string` so a Code 128 label, a QR
     * payload or an RFID tag can all carry exactly the value the screen
     * shows. Nothing in this phase prints one; see docs/API_DOCUMENTATION.md
     * for the note on future scanning.
     *
     * **`purchase_cost` is money and is treated as such** — DECIMAL(12,2),
     * never a float, never through the client's arithmetic — and it is one
     * of the fields AssetResource withholds from a reader without
     * `assets.manage`. What a laptop cost is not something every holder of
     * `assets.view` needs to be handed.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 60)->unique();
            $table->foreignId('asset_type_id')
                ->constrained('asset_types')
                ->restrictOnDelete();

            $table->string('name', 200);
            $table->string('description', 1000)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();

            $table->string('current_condition', 20)->default('good');
            $table->string('status', 20)->default('available')->index();
            $table->string('notes', 1000)->nullable();

            $table->timestamps();

            $table->index('name');
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
