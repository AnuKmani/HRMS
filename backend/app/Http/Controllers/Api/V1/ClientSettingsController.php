<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/client-settings
 *
 * The smallest read-only window onto the settings table that a client needs
 * in order to *render* correctly — and deliberately not one byte wider.
 *
 * Why an endpoint exists at all: the mobile app used to guess the currency
 * it filed a claim in, and a guess is a hardcoded value wearing a costume.
 * A setting the server owns can be read, never written, through this route,
 * so the same day an operator changes the company currency, every device
 * offers the new one without a store release.
 *
 * **Allow-list, not a filter.** The payload is built from named keys rather
 * than by dropping the rows marked `is_editable = false`, because a denylist
 * is a list of things somebody remembered and an allowlist is a list of
 * things somebody intended. Sensitive rows — payroll floors, geofence
 * radii, notification timings — are not "excluded"; they were never
 * candidates.
 *
 * **No permission gate.** It sits behind `auth:sanctum` and nothing else,
 * like `attendance/today` and `holidays/index` before it: every signed-in
 * user needs the default currency to open a form, and gating a value that
 * is not secret behind a permission would mean a person sees the wrong
 * currency until somebody grants them a right they never asked for. The
 * response contains no personal, financial or operational data.
 */
class ClientSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function __invoke(): JsonResponse
    {
        $supported = $this->supportedCurrencies();
        $default = strtoupper($this->settings->string('system.currency'));

        // Two settings that an operator *could* leave disagreeing. Rather
        // than hand the form a default the API would then refuse, the
        // default is reconciled here: the company currency when it is on
        // the supported list, otherwise the first entry that is. With no
        // list configured the company currency stands on its own.
        if ($supported === []) {
            $supported = $default === '' ? [] : [$default];
        } elseif (! in_array($default, $supported, true)) {
            $default = $supported[0];
        }

        return ApiResponse::success('Client settings retrieved.', (object) [
            'default_currency' => $default,
            'supported_currencies' => $supported,
        ]);
    }

    /**
     * `system.supported_currencies`, upper-cased and de-duplicated so the
     * menu and the validator agree on what "AED" is spelled like.
     *
     * @return array<int, string>
     */
    private function supportedCurrencies(): array
    {
        $codes = [];

        foreach ($this->settings->json('system.supported_currencies') as $code) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }

            $codes[] = strtoupper(trim($code));
        }

        return array_values(array_unique($codes));
    }
}
