<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bring back employees removed with the old Delete button, which hid
 * people who still had history. They return as they were; anyone who has
 * really left is then marked as left from the app.
 *
 *   php artisan hrms:restore-employees            (list them)
 *   php artisan hrms:restore-employees --all      (restore every one)
 *   php artisan hrms:restore-employees --id=12    (restore one)
 */
class RestoreDeletedEmployees extends Command
{
    protected $signature = 'hrms:restore-employees {--all : Restore every deleted employee} {--id=* : Restore these employee ids}';

    protected $description = 'List or restore employees that were deleted';

    public function handle(): int
    {
        $deleted = Employee::onlyTrashed()->orderBy('id')->get();

        if ($deleted->isEmpty()) {
            $this->info('No deleted employees.');

            return self::SUCCESS;
        }

        $ids = array_map('intval', (array) $this->option('id'));
        $chosen = $this->option('all') ? $deleted : $deleted->whereIn('id', $ids);

        if ($chosen->isEmpty()) {
            $this->table(['ID', 'Code', 'Name', 'Email'], $deleted->map(fn (Employee $e) => [
                $e->id, $e->employee_code, $e->full_name, $e->email,
            ]));
            $this->line('Restore with --all, or --id=<ID> (repeatable).');

            return self::SUCCESS;
        }

        foreach ($chosen as $employee) {
            $employee->restore();
            if ($employee->user_id !== null) {
                User::query()->whereKey($employee->user_id)->update(['status' => User::STATUS_ACTIVE]);
            }
            $this->info("Restored {$employee->employee_code} {$employee->full_name}");
        }

        return self::SUCCESS;
    }
}
