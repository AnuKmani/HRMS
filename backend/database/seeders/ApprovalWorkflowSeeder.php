<?php

namespace Database\Seeders;

use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use Illuminate\Database\Seeder;

class ApprovalWorkflowSeeder extends Seeder
{
    /**
     * The approval chains shipped with the system.
     *
     * Four, and each exists because it is a *different* answer rather than a
     * variation on one:
     *
     *   LEAVE-STD  Employee -> Supervisor -> Project Manager -> HR
     *              The full chain from the specification, and the default a
     *              leave request falls back to when its type names none.
     *
     *   LEAVE-FAST Employee -> HR
     *              The short chain, available to be assigned to a leave type
     *              (LeaveType.approval_workflow_id). It is NOT the default:
     *              seeding two defaults would leave
     *              ApprovalWorkflowService::forSubject() choosing between
     *              them, and a tie-break is a rule nobody wrote down.
     *
     *   OT-STD     Employee -> Supervisor -> Project Manager -> HR / Payroll
     *              Same shape as LEAVE-STD for the first two steps, then a
     *              *permission* step rather than a role: "HR / Payroll" is a
     *              function, and `overtime.approve` is held by HR Admin,
     *              Payroll Admin and the managers — exactly the set that
     *              should be able to make extra minutes payable.
     *
     *   EXP-STD    Employee -> Supervisor -> Finance / HR
     *              Two links rather than three, because money has a shorter
     *              question than absence does: did the line manager accept
     *              that this was spent, and does the back office accept that
     *              it was spent on the company? The second link is a
     *              *permission* step (`expenses.manage` — HR Admin, Payroll
     *              Admin, Finance) rather than a role, so a deployment that
     *              renames its finance team still has a chain that works, and
     *              Project Manager — who holds `expenses.approve` for the
     *              supervisor links of other chains but no `expenses.manage` —
     *              cannot sign off the payment.
     *
     * The Supervisor step is `reporting_manager`, resolved from the subject's
     * own employee at submit time, so it is a person rather than a title.
     *
     * Re-seeding is safe: each workflow is looked up by (subject_type, code)
     * and its steps replaced wholesale, so editing a chain here and running
     * `php artisan db:seed` reproduces the definition instead of appending a
     * second copy of it.
     *
     * @var array<int, array<string, mixed>>
     */
    public const WORKFLOWS = [
        [
            'code' => 'LEAVE-STD',
            'name' => 'Standard leave approval',
            'subject_type' => ApprovalWorkflow::SUBJECT_LEAVE,
            'is_default' => true,
            'description' => 'Supervisor, then Project Manager, then HR.',
            'steps' => [
                ['sequence' => 1, 'name' => 'Supervisor', 'approver_type' => ApprovalWorkflowStep::TYPE_REPORTING_MANAGER],
                ['sequence' => 2, 'name' => 'Project Manager', 'approver_type' => ApprovalWorkflowStep::TYPE_ROLE, 'approver_role' => 'Project Manager'],
                ['sequence' => 3, 'name' => 'HR', 'approver_type' => ApprovalWorkflowStep::TYPE_ROLE, 'approver_role' => 'HR Admin'],
            ],
        ],
        [
            'code' => 'LEAVE-FAST',
            'name' => 'Direct to HR',
            'subject_type' => ApprovalWorkflow::SUBJECT_LEAVE,
            'is_default' => false,
            'description' => 'One step: HR. Assign it to a leave type that needs no line-manager sign-off.',
            'steps' => [
                ['sequence' => 1, 'name' => 'HR', 'approver_type' => ApprovalWorkflowStep::TYPE_ROLE, 'approver_role' => 'HR Admin'],
            ],
        ],
        [
            'code' => 'OT-STD',
            'name' => 'Standard overtime approval',
            'subject_type' => ApprovalWorkflow::SUBJECT_OVERTIME,
            'is_default' => true,
            'description' => 'Supervisor, then Project Manager, then whoever holds overtime.approve — HR or Payroll.',
            'steps' => [
                ['sequence' => 1, 'name' => 'Supervisor', 'approver_type' => ApprovalWorkflowStep::TYPE_REPORTING_MANAGER],
                ['sequence' => 2, 'name' => 'Project Manager', 'approver_type' => ApprovalWorkflowStep::TYPE_ROLE, 'approver_role' => 'Project Manager'],
                ['sequence' => 3, 'name' => 'HR / Payroll', 'approver_type' => ApprovalWorkflowStep::TYPE_PERMISSION, 'approver_permission' => 'overtime.approve'],
            ],
        ],
        [
            'code' => 'EXP-STD',
            'name' => 'Standard expense approval',
            'subject_type' => ApprovalWorkflow::SUBJECT_EXPENSE,
            'is_default' => true,
            'description' => 'Supervisor, then whoever holds expenses.manage — Finance or HR.',
            'steps' => [
                ['sequence' => 1, 'name' => 'Supervisor', 'approver_type' => ApprovalWorkflowStep::TYPE_REPORTING_MANAGER],
                ['sequence' => 2, 'name' => 'Finance / HR', 'approver_type' => ApprovalWorkflowStep::TYPE_PERMISSION, 'approver_permission' => 'expenses.manage'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::WORKFLOWS as $definition) {
            $steps = $definition['steps'];

            // No dependence on `is_default` being unique at the database
            // level: clear every default for this subject, then set the one
            // this definition asks for.
            //
            // The conditional matters. WORKFLOWS carries two leave chains and
            // only LEAVE-STD is the default; clearing unconditionally would
            // run once per entry, so LEAVE-FAST's pass would flip LEAVE-STD
            // back off again and leave the subject with no default at all —
            // which surfaces not here but at the first submitted leave
            // request, as "No approval workflow is configured". Only the
            // entry that is asserting a default clears the others.
            if (! empty($definition['is_default'])) {
                ApprovalWorkflow::query()
                    ->where('subject_type', $definition['subject_type'])
                    ->update(['is_default' => false]);
            }

            $workflow = ApprovalWorkflow::query()->updateOrCreate(
                [
                    'subject_type' => $definition['subject_type'],
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'is_default' => (bool) ($definition['is_default'] ?? false),
                    'status' => ApprovalWorkflow::STATUS_ACTIVE,
                ],
            );

            // Replace rather than append: a step removed from WORKFLOWS should
            // disappear from the database too, and a chain whose steps were
            // merged rather than replaced would grow every time it was seeded.
            $workflow->steps()->delete();

            foreach ($steps as $step) {
                $workflow->steps()->create($step);
            }
        }
    }
}
