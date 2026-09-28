<?php

/**
 * Verify the sick-certificate → LOP pipeline the way production runs it.
 *
 *     cd /path/to/hrms/backend
 *     DB_DATABASE=hrms_testing QUEUE_CONNECTION=database \
 *         php ../scripts/verify-lop-pipeline.php setup
 *
 *     php artisan schedule:test --name="App\Jobs\EnforceSickCertificateDeadlines"
 *     php artisan queue:work --stop-when-empty
 *     php ../scripts/verify-lop-pipeline.php state
 *
 * Why this exists: `php artisan test` runs the job in-process with
 * QUEUE_CONNECTION=sync, which proves the conversion rule but can never prove
 * that the scheduler dispatches, that a row lands in the `jobs` table, or
 * that a separate worker process is what eventually runs it. Those three are
 * the parts that break silently, so they are checked here against a real
 * queue and a real artisan worker.
 *
 * SAFETY: this refuses to do anything unless the configured database is
 * exactly `hrms_testing`. There is no flag to override it — the point of the
 * guard is that a mistyped DB_DATABASE cannot reach development data.
 *
 * Commands:
 *   setup          seed the Phase 6 furniture, create one overdue certificate-less
 *                  sick request and print its id
 *   state          print a state fingerprint (leave, balance, approvals, queue)
 *   handle         call the job handler twice in-process — the "two workers
 *                  grabbed the same row" case with every lock bypassed
 *   dispatch-twice dispatch the job twice — proves ShouldBeUnique collapses
 *                  the second one
 */

$backend = dirname(__DIR__).'/backend';

require $backend.'/vendor/autoload.php';

$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\EnforceSickCertificateDeadlines;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveRequestService;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\DB;

if (config('database.connections.mysql.database') !== 'hrms_testing') {
    fwrite(STDERR, sprintf(
        "REFUSED: this script only runs against hrms_testing, not %s.\n",
        (string) config('database.connections.mysql.database'),
    ));

    exit(2);
}

$command = $argv[1] ?? 'state';

if ($command === 'setup') {
    foreach ([
        RoleSeeder::class,
        PermissionSeeder::class,
        RolePermissionSeeder::class,
        SettingSeeder::class,
        LeaveTypeSeeder::class,
        ApprovalWorkflowSeeder::class,
    ] as $seeder) {
        (new $seeder)->run();
    }

    $type = LeaveType::query()->where('code', 'SL')->firstOrFail();

    $leave = DB::transaction(function () use ($type) {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee->update(['user_id' => $user->id]);

        $service = app(LeaveRequestService::class);

        // One working day of sick leave — small enough to sit inside the
        // seeded entitlement, so nothing has to be adjusted for it.
        $draft = $service->create($user, [
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-28',
            'end_date' => '2026-09-28',
            'reason' => 'Operational verification of deadline enforcement.',
        ]);

        $service->submit($user, $draft);

        return $draft->fresh();
    });

    // Simulate the deadline passing. The scheduler runs in a different
    // process, so Carbon cannot be travelled across it — back-dating the
    // column is exactly what "two days later" looks like to the job's query.
    DB::table('leave_requests')
        ->where('id', $leave->id)
        ->update(['certificate_due_at' => now()->subDay()->toDateString()]);

    $leave = $leave->fresh();

    echo 'LEAVE_ID='.$leave->id.PHP_EOL;
    echo 'STATUS='.$leave->status.PHP_EOL;
    echo 'CERT_DUE='.$leave->certificate_due_at->toDateString().PHP_EOL;
    echo 'REQUESTED_DAYS='.$leave->requested_days.PHP_EOL;

    exit(0);
}

if ($command === 'state') {
    foreach (LeaveRequest::query()->orderBy('id')->get([
        'id', 'status', 'lop_days', 'certificate_due_at',
        'certificate_checked_at', 'lop_applied_at', 'requested_days',
    ]) as $l) {
        echo sprintf(
            "leave#%d status=%s days=%s lop_days=%s checked=%s applied=%s due=%s\n",
            $l->id,
            $l->status,
            $l->requested_days,
            $l->lop_days,
            $l->certificate_checked_at ?? '-',
            $l->lop_applied_at ?? '-',
            $l->certificate_due_at?->toDateString() ?? '-',
        );
    }

    foreach (LeaveBalance::query()->orderBy('id')->get(['id', 'entitlement', 'used', 'pending']) as $b) {
        echo "balance#{$b->id} entitlement={$b->entitlement} used={$b->used} pending={$b->pending}\n";
    }

    echo 'leave_rows='.LeaveRequest::query()->count().PHP_EOL;
    echo 'balance_rows='.LeaveBalance::query()->count().PHP_EOL;
    echo 'approval_records='.DB::table('approval_records')->count().PHP_EOL;
    echo 'approval_records_waiting='.DB::table('approval_records')->where('status', 'waiting')->count().PHP_EOL;
    echo 'lop_rows='.LeaveRequest::query()->where('status', 'lop')->count().PHP_EOL;
    echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
    echo 'failed_jobs='.DB::table('failed_jobs')->count().PHP_EOL;

    exit(0);
}

if ($command === 'handle') {
    // Deliberately bypasses ShouldBeUnique: this is the worst case, two
    // handlers on the same row with every lock already defeated.
    $job = new EnforceSickCertificateDeadlines;
    echo 'converted_first='.$job->handle(app(LeaveRequestService::class)).PHP_EOL;
    echo 'converted_second='.$job->handle(app(LeaveRequestService::class)).PHP_EOL;

    exit(0);
}

if ($command === 'dispatch-twice') {
    EnforceSickCertificateDeadlines::dispatch();
    EnforceSickCertificateDeadlines::dispatch();
    echo 'jobs='.DB::table('jobs')->count().PHP_EOL;

    exit(0);
}

fwrite(STDERR, "usage: verify-lop-pipeline.php setup|state|handle|dispatch-twice\n");
exit(1);
