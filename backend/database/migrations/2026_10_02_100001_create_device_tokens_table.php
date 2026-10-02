<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per *install*, not per user and not per token.
     *
     * `device_identifier` is the stable id the app generates once and keeps
     * in secure storage; `fcm_token` is the volatile thing Firebase hands
     * back and rotates without warning. Keying the unique index on the pair
     * means a token rotation is an UPDATE of a row we already own rather
     * than an ever-growing pile of dead rows — and keying `fcm_token` as
     * unique as well means the same token can never be registered by two
     * accounts, which is exactly what a reinstall-then-sign-into-someone-
     * else does.
     *
     * `active` is the stale-token answer. Firebase tells us a token is gone
     * (`UNREGISTERED`) exactly once; we flip this flag and never send to it
     * again. A row that is inactive is not deleted, because "this handset
     * stopped receiving pushes on the 3rd" is worth more than a shorter
     * table.
     *
     * `fcm_token` is capped at 512 rather than `text`: real tokens are
     * ~150-250 characters, and a bounded column can carry a unique index
     * (512 x 4 bytes = 2048, under InnoDB's 3072-byte limit) where a `text`
     * column could not without a prefix length.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('device_identifier', 100);
            $table->string('platform', 20)->default('android');
            $table->string('fcm_token', 512)->unique();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'device_identifier']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
