<?php

namespace App\Http\Requests;

use App\Models\Timesheet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/timesheets/generate
 *
 * Ask the server to re-derive a period from attendance.
 *
 * Deliberately does not accept a list of timesheet rows. There is no way to
 * *author* a timesheet through this API at all — the whole point of a derived
 * snapshot is that its only source is `attendances`, so the request names a
 * window (and optionally one employee) and TimesheetService fills it from
 * rows that already exist.
 *
 * `employee_id` is optional; omitting it means "every attendance I can see",
 * which is decided by Visibility exactly as the list endpoint does.
 */
class GenerateTimesheetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate', Timesheet::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'Enter the start of the range as YYYY-MM-DD.',
            'to.date_format' => 'Enter the end of the range as YYYY-MM-DD.',
            'to.after_or_equal' => 'The end of the range must be on or after its start.',
        ];
    }
}
