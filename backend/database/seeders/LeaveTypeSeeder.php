<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * The five leave types the system starts with.
     *
     * Nothing here is a rule the application knows about — these are rows.
     * Changing Sick Leave's entitlement, enabling carry-forward on Annual
     * Leave or pointing a type at a different approval chain are all UPDATEs
     * against this table, and every number below is read through
     * LeaveType / LeaveBalanceService at request time rather than
     * reproduced anywhere in code.
     *
     * Two deliberate choices worth spelling out:
     *
     *  - **Unpaid Leave and Other allow a negative balance.** They carry no
     *    entitlement, so `remaining` starts at zero and the "never below
     *    zero" rule would refuse every request by construction. Flipping
     *    `allow_negative_balance` is how a type says "I am not a pot".
     *
     *  - **Sick Leave is the only type that demands a document, with a
     *    two-day deadline.** That matches the default the specification
     *    asks for and demonstrates the fallback: set
     *    `document_deadline_days` to 0 and the organisation-wide
     *    `leave.sick_certificate_deadline_days` setting answers instead.
     *
     * @var array<int, array<string, mixed>>
     */
    public const TYPES = [
        [
            'name' => 'Annual Leave',
            'code' => 'AL',
            'description' => 'Planned time off, accrued yearly and optionally carried forward.',
            'entitlement_days' => 12,
            'carry_forward_enabled' => true,
            'carry_forward_limit' => 5,
            'maximum_days_per_request' => 15,
            'is_paid' => true,
            'requires_document' => false,
            'document_deadline_days' => 0,
            'allow_negative_balance' => false,
        ],
        [
            'name' => 'Sick Leave',
            'code' => 'SL',
            'description' => 'Illness, with a medical certificate required after the deadline.',
            'entitlement_days' => 10,
            'carry_forward_enabled' => false,
            'carry_forward_limit' => 0,
            'maximum_days_per_request' => 10,
            'is_paid' => true,
            'requires_document' => true,
            'document_deadline_days' => 2,
            'allow_negative_balance' => false,
        ],
        [
            'name' => 'Emergency Leave',
            'code' => 'EL',
            'description' => 'Short-notice absence for an urgent personal matter.',
            'entitlement_days' => 5,
            'carry_forward_enabled' => false,
            'carry_forward_limit' => 0,
            'maximum_days_per_request' => 5,
            'is_paid' => true,
            'requires_document' => false,
            'document_deadline_days' => 0,
            'allow_negative_balance' => false,
        ],
        [
            'name' => 'Unpaid Leave',
            'code' => 'UL',
            'description' => 'Time off without pay. Not an entitlement, so it never runs out.',
            'entitlement_days' => 0,
            'carry_forward_enabled' => false,
            'carry_forward_limit' => 0,
            'maximum_days_per_request' => 30,
            'is_paid' => false,
            'requires_document' => false,
            'document_deadline_days' => 0,
            'allow_negative_balance' => true,
        ],
        [
            'name' => 'Other',
            'code' => 'OTH',
            'description' => 'Anything that does not fit the types above.',
            'entitlement_days' => 0,
            'carry_forward_enabled' => false,
            'carry_forward_limit' => 0,
            'maximum_days_per_request' => 30,
            'is_paid' => false,
            'requires_document' => false,
            'document_deadline_days' => 0,
            'allow_negative_balance' => true,
        ],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            // firstOrCreate, not create: re-seeding must not trip the unique
            // code, and must not silently drop an entitlement an operator has
            // already tuned. The seeder states the factory default once; what
            // came after belongs to whoever edited the row.
            LeaveType::query()->firstOrCreate(
                ['code' => $type['code']],
                $type + ['status' => LeaveType::STATUS_ACTIVE],
            );
        }
    }
}
