<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The handful of ways a validated attendance payload is read, in one place.
 *
 * Shared by AttendanceService and SiteVisitService so "where does
 * `client_event_id` come from?" and "what is the accuracy field?" have one
 * answer each instead of two that could drift.
 *
 * Every method here *reads* client input. None of them trusts it: the
 * identity always comes from the session, coordinates are cast but not
 * validated (GeofenceService validates), and `source` refuses a client's
 * claim to be `manual`, which is a status only a back-office override may
 * write.
 */
final class ClientInput
{
    private function __construct() {}

    /**
     * The employee behind the token. Never a request field — see
     * docs/SECURITY.md: `employee_id` in a check-in body is ignored by
     * design, so a tampered payload can only ever record *their own* day.
     */
    public static function employeeFor(User $user): Employee
    {
        $employee = $user->employee;

        if ($employee === null) {
            abort(403, 'Your account is not linked to an employee record, so attendance is not available.');
        }

        return $employee;
    }

    public static function eventId(array $data): ?string
    {
        $value = $data['client_event_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Coordinates and accuracy, cast to the types the maths expects.
     *
     * Range, finiteness and quality are GeofenceService's job — keeping the
     * two apart means a site visit and a check-in cannot end up with
     * different notions of what "a valid latitude" is.
     *
     * @return array{latitude: float, longitude: float, accuracy: float|null}
     */
    public static function point(array $data): array
    {
        return [
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'accuracy' => isset($data['accuracy']) && $data['accuracy'] !== null
                ? (float) $data['accuracy']
                : null,
        ];
    }

    /**
     * `online` / `offline`. A client may not claim `manual`: that word means
     * "a person with override rights wrote this", and Phase 5 has no such
     * path at all.
     */
    public static function source(array $data): string
    {
        $value = $data['source'] ?? null;

        return in_array($value, Attendance::SOURCES, true) && $value !== Attendance::SOURCE_MANUAL
            ? $value
            : Attendance::SOURCE_ONLINE;
    }

    public static function deviceReference(array $data): ?string
    {
        $value = $data['device_reference'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 100);
    }

    public static function selfieFile(array $data): UploadedFile
    {
        $file = $data['selfie'] ?? null;

        if (! $file instanceof UploadedFile) {
            // The FormRequest makes it required and validated, so reaching
            // here means a caller bypassed it. Fail loudly rather than write
            // an attendance row with no evidence behind it.
            abort(422, 'A selfie is required to record this.');
        }

        return $file;
    }

    public static function optionalSelfie(array $data): ?UploadedFile
    {
        $file = $data['selfie'] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }
}
