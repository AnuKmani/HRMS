<?php

namespace App\Services\Attendance;

use App\Models\EmployeeSiteAssignment;

/**
 * Why somebody may — or may not — stand at a site.
 *
 * `code` is stable enough for a client to branch on; `message` is written to
 * be shown to the person holding the phone, because "assignment not active"
 * tells a labourer nothing and "your posting at this site ended on 3 March —
 * ask your supervisor to reassign you" tells them exactly who to talk to.
 */
final class SiteAccessDecision
{
    public const CODE_ASSIGNMENT = 'assignment';

    public const CODE_PRIMARY_SITE = 'primary_site';

    public const CODE_EMPLOYEE_INACTIVE = 'employee_inactive';

    public const CODE_SITE_INACTIVE = 'site_inactive';

    public const CODE_ASSIGNMENT_NOT_ACTIVE = 'assignment_not_active';

    public const CODE_ASSIGNMENT_PROJECT_MISMATCH = 'assignment_project_mismatch';

    public const CODE_NOT_ASSIGNED = 'not_assigned';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?EmployeeSiteAssignment $assignment,
        public readonly ?string $how,
    ) {}

    public static function allow(
        string $code,
        string $how,
        ?EmployeeSiteAssignment $assignment = null,
    ): self {
        return new self(
            allowed: true,
            code: $code,
            message: null,
            assignment: $assignment,
            how: $how,
        );
    }

    public static function deny(string $code, string $message): self
    {
        return new self(
            allowed: false,
            code: $code,
            message: $message,
            assignment: null,
            how: null,
        );
    }
}
