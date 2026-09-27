<?php

namespace App\Http\Requests\Concerns;

use App\Models\Employee;
use App\Models\Site;
use Illuminate\Validation\Validator;

/**
 * Cross-field rules shared by employee create and update.
 *
 * Four checks that do not fit in a declarative rule:
 *
 *  - **salary** may only be touched by someone who may read it (see
 *    EmployeePolicy::manageSalary). Writing a figure you are forbidden to
 *    look at is the same disclosure with an extra step;
 *  - **a manager cannot be yourself**, which would make the reporting line
 *    terminate in a loop of one;
 *  - **a manager's own line must not already contain this employee** — the
 *    two-person version of the same loop, and the one an import or a
 *    reorganisation actually produces;
 *  - **the primary site must belong to the primary project**, because
 *    `employee_site_assignments` carries both and insists they agree. An
 *    employee filed against a site their project does not own would be
 *    invisible to the very supervisor the assignment is supposed to reach.
 */
trait ValidatesEmployeeRelations
{
    /**
     * Absent rather than empty when the caller has no salary permission: the
     * field is not theirs to send, so it never reaches the validator and
     * `$request->validated()` cannot carry it into the model.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function salaryRules(): array
    {
        if (! $this->user()?->can('employees.salary.view')) {
            return [];
        }

        return [
            // DECIMAL(12,2). `decimal:0,2` is the precision half: money is
            // stored to two places, so accepting five would silently round
            // and hand payroll a figure nobody typed.
            'salary' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * @param  int|null  $employeeId  null on create
     */
    protected function checkEmployeeRelations(Validator $validator, ?int $employeeId = null): void
    {
        $this->checkSalaryPermission($validator);
        $this->checkReportingLine($validator, $employeeId);
        $this->checkPrimarySiteProject($validator);
    }

    private function checkSalaryPermission(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! array_key_exists('salary', $this->all())) {
                return;
            }

            if ($this->user()?->can('employees.salary.view')) {
                return;
            }

            $validator->errors()->add(
                'salary',
                'You do not have permission to change the salary.',
            );
        });
    }

    private function checkReportingLine(Validator $validator, ?int $employeeId): void
    {
        $validator->after(function (Validator $validator) use ($employeeId) {
            $managerId = $this->input('reporting_manager_id');

            if ($managerId === null || $managerId === '') {
                return;
            }

            $managerId = (int) $managerId;

            if ($employeeId !== null && $managerId === $employeeId) {
                $validator->errors()->add(
                    'reporting_manager_id',
                    'An employee cannot report to themselves.',
                );

                return;
            }

            // Only meaningful once the row exists: before that there is no
            // line for anyone's chain to come back to.
            if ($employeeId === null) {
                return;
            }

            $cursor = $managerId;
            $guard = 0;

            while ($cursor !== null && $guard < 25) {
                if ((int) $cursor === $employeeId) {
                    $validator->errors()->add(
                        'reporting_manager_id',
                        'That reporting line would create a loop.',
                    );

                    return;
                }

                $next = Employee::query()->whereKey($cursor)->value('reporting_manager_id');
                $cursor = $next === null ? null : (int) $next;
                $guard++;
            }
        });
    }

    private function checkPrimarySiteProject(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Employee|null $employee */
            $employee = $this->route('employee');

            $siteId = $this->input('primary_site_id', $employee?->primary_site_id);
            $projectId = $this->input('primary_project_id', $employee?->primary_project_id);

            if ($siteId === null || $siteId === '') {
                return;
            }

            if ($projectId === null || $projectId === '') {
                $validator->errors()->add(
                    'primary_project_id',
                    'Select the project this site belongs to.',
                );

                return;
            }

            $owningProject = Site::query()
                ->whereKey($siteId)
                ->value('project_id');

            if ($owningProject !== null && (int) $owningProject !== (int) $projectId) {
                $validator->errors()->add(
                    'primary_site_id',
                    'The selected site does not belong to the selected project.',
                );
            }
        });
    }
}
