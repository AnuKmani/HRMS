<?php

namespace App\Services\SiteReport;

use App\Models\DailySiteReport;
use App\Models\Site;
use App\Models\User;
use App\Support\Visibility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of `daily_site_reports` and its four child tables.
 *
 * Four rules live here, and all four are ones a controller would get wrong:
 *
 *  - **who wrote it** comes from the session (`created_by`), never the
 *    payload. The same argument as the activity service, with authorship
 *    standing in for authorship-of-presence.
 *  - **one per site per date.** The request asks the question first so the
 *    answer is a field error naming `report_date`; this catches the race
 *    that question cannot see — two supervisors, one site, one date, half a
 *    second apart — and turns the driver's 23000 into the same field error
 *    rather than a 500 with a constraint name in the message. The unique
 *    index is what makes the answer *true*; the two handlers are what make
 *    it *readable*.
 *  - **total_manpower is derived, not declared.** When manpower rows are
 *    part of the payload the total is their sum, whatever the client also
 *    sent — a PDF that printed "40" over rows adding up to 26 would be a
 *    document nobody could reproduce. When no rows are sent (a report being
 *    corrected in one field), the declared total stands.
 *  - **children are replaced wholesale.** Delete and re-insert inside one
 *    transaction rather than diffed: a daily report is edited as a whole
 *    document, a diff that leaves one orphan line would be far harder to
 *    notice than a reorder, and the row counts here are small.
 *
 * `status` is written only by this class: `draft` on create, `submitted`
 * on submit. `approved_at` is never written — approval is a later phase and
 * the column exists precisely so that phase is a feature and not a
 * migration.
 */
class DailySiteReportService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): DailySiteReport
    {
        if ($user->employee === null) {
            abort(403, 'This account is not linked to an employee record.');
        }

        $site = $this->resolveSite($data);

        $this->authorizeSite($user, $site);

        return $this->guardDuplicate(function () use ($user, $data, $site) {
            return DB::transaction(function () use ($user, $data, $site) {
                $report = new DailySiteReport;
                $report->created_by = $user->id;
                $report->fill($this->attributes($data, $site));
                $report->status = DailySiteReport::STATUS_DRAFT;
                $report->save();

                $this->syncChildren($report, $data);

                return $report;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, DailySiteReport $report, array $data): DailySiteReport
    {
        $this->assertEditable($report);

        $site = $this->resolveSite($data, $report->site_id);

        $this->authorizeSite($user, $site);

        return $this->guardDuplicate(function () use ($report, $data, $site) {
            return DB::transaction(function () use ($report, $data, $site) {
                $report->fill($this->attributes($data, $site));
                $report->save();

                $this->syncChildren($report, $data);

                return $report;
            });
        });
    }

    /**
     * draft -> submitted. Nothing else moves, and nothing moves back.
     *
     * Submitting the document is a statement that both narratives are
     * written, so an empty `work_completed` — which the store rules would
     * already have refused — is the one content check worth restating here:
     * a document that says nothing should not become official by being
     * sent.
     */
    public function submit(User $user, DailySiteReport $report): DailySiteReport
    {
        $this->assertEditable($report);

        $site = $report->site;

        if ($site !== null) {
            $this->authorizeSite($user, $site);
        }

        if (trim((string) $report->work_planned) === '' || trim((string) $report->work_completed) === '') {
            abort(409, 'Write what was planned and what was completed before submitting.');
        }

        $report->fill([
            'status' => DailySiteReport::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $report->save();

        return $report;
    }

    /**
     * Attributes a payload may address. `created_by` is not one of them —
     * it is set on create from the session and never again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Site $site): array
    {
        $attributes = [
            'project_id' => $site->project_id,
            'site_id' => $site->id,
        ];

        foreach ([
            'report_date',
            'total_manpower',
            'work_planned',
            'work_completed',
            'safety_observations',
            'delays',
            'issues',
            'remarks',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        if (array_key_exists('total_manpower', $attributes)) {
            $attributes['total_manpower'] = (int) $attributes['total_manpower'];
        }

        return $attributes;
    }

    /**
     * Replace whichever child sets the payload carried, and re-derive the
     * headline total from them.
     *
     * A set that is *absent* from `$data` is left alone — that is a partial
     * update saying "I only changed the delays" — while one that is present,
     * even as an empty array, means "this document now has none of these".
     *
     * @param  array<string, mixed>  $data
     */
    private function syncChildren(DailySiteReport $report, array $data): void
    {
        if (array_key_exists('manpower', $data)) {
            $rows = array_values((array) $data['manpower']);

            $report->manpower()->delete();

            foreach ($rows as $index => $row) {
                $report->manpower()->create([
                    'category' => (string) $row['category'],
                    'count' => (int) $row['count'],
                    'sort_order' => $index,
                ]);
            }

            $report->total_manpower = array_sum(array_map(
                fn (array $row) => (int) $row['count'],
                $rows,
            ));
        }

        if (array_key_exists('materials', $data)) {
            $report->materials()->delete();

            foreach (array_values((array) $data['materials']) as $index => $row) {
                $report->materials()->create([
                    'material_name' => (string) $row['material_name'],
                    'quantity' => $row['quantity'],
                    'unit' => (string) $row['unit'],
                    'remarks' => $row['remarks'] ?? null,
                    'sort_order' => $index,
                ]);
            }
        }

        if (array_key_exists('equipment', $data)) {
            $report->equipment()->delete();

            foreach (array_values((array) $data['equipment']) as $index => $row) {
                $report->equipment()->create([
                    'equipment_name' => (string) $row['equipment_name'],
                    'quantity' => (int) $row['quantity'],
                    'operating_hours' => $row['operating_hours'] ?? null,
                    'condition' => $row['condition'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                    'sort_order' => $index,
                ]);
            }
        }

        $report->save();
    }

    /**
     * Translate the unique index's 23000 into the same field error the
     * request's `Rule::unique` would have produced.
     *
     * Only the `dsr_site_date_unique` constraint is caught. Rethrowing
     * anything else — including a foreign key this code should never trip —
     * keeps a genuine fault visible instead of being mislabelled as a
     * duplicate date.
     *
     * @param  callable(): mixed  $operation
     *
     * @throws ValidationException
     */
    private function guardDuplicate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            $message = (string) $exception->getMessage();

            $isDuplicate = (($exception->errorInfo[0] ?? null) === '23000')
                && str_contains($message, 'dsr_site_date_unique');

            if (! $isDuplicate) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'report_date' => 'There is already a daily site report for this site and date.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSite(array $data, ?int $fallback = null): Site
    {
        $siteId = $data['site_id'] ?? $fallback;

        $site = $siteId === null ? null : Site::query()->find($siteId);

        if ($site === null) {
            abort(422, 'That site no longer exists.');
        }

        return $site;
    }

    private function authorizeSite(User $user, Site $site): void
    {
        if (! Visibility::mayReportAt($user, $site)) {
            abort(403, 'You can only file a report for a site you are assigned to.');
        }
    }

    private function assertEditable(DailySiteReport $report): void
    {
        if (! $report->isEditable()) {
            abort(409, 'Only a draft can be edited. This report has already been submitted.');
        }
    }
}
