<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-app inbox: the record of what the system told somebody.
     *
     * This table is the *delivery record*, not the delivery. A push is a
     * best-effort signal to a handset that may be offline, asleep or gone;
     * the inbox row is the thing that is still there when they open the
     * app, and the only copy the "mark as read" and "unread badge" features
     * can be built on. FCM therefore delivers *from* this row (the queued
     * job carries its id) rather than around it, so a failed push never
     * means a lost notification.
     *
     * `data` is a **reference**, never a payload of facts. It holds ids and
     * a route (`{"route": "/leave/7", "leave_request_id": 7}`) so tapping a
     * notification can navigate somewhere the app re-fetches under its own
     * permissions. Nothing here stores a salary figure, a document's
     * contents, or anything else that would become a second, weaker copy of
     * data the API already guards.
     *
     * `type` is a dotted catalogue key (`leave.approved`,
     * `training.expiring`) rather than a PHP class name: the inbox has to
     * survive a refactor of the code that wrote it, and a string the client
     * switches on is the whole contract.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('type', 80)->index();
            $table->string('title', 191);
            $table->string('body', 500);
            $table->json('data')->nullable();

            /**
             * Optional "only say this once" key, e.g. `sick_cert:7`.
             *
             * Most notifications are events that happen once and need no
             * help. The ones driven by a *clock* do: a reminder job that
             * runs hourly and has nothing to stop it telling a person the
             * same thing every hour would be muted by lunchtime. The key
             * is set by the caller, scoped to the user, and the unique
             * index is the whole enforcement mechanism — no marker column
             * on whichever table the clock reads, no second query to keep
             * two flags in step.
             *
             * Nullable, and deliberately so: MySQL's unique index treats
             * NULLs as distinct, so rows with no key are unlimited while a
             * keyed row is exactly one per user.
             */
            $table->string('dedupe_key', 160)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The badge query: "mine, still unread", newest first.
            $table->index(['user_id', 'read_at']);
            $table->index('created_at');
            $table->unique(['user_id', 'dedupe_key'], 'notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
