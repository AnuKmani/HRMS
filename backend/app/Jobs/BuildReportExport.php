<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\ReportRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Builds one queued report export into private storage.
 *
 * The row it reads is the whole specification: report key, format,
 * filters, and the person who asked. Nothing is taken from the client
 * that fired the request — which is what makes it safe to queue. A job
 * that carried the caller's filters in its payload would be re-running a
 * query whose conditions were written by whoever's token was in flight,
 * minutes after that token may have been revoked or that person's roles
 * changed.
 *
 * **The permission is re-checked here, not remembered from the
 * controller.** Between `POST /reports/…/exports` and a worker picking
 * the job up, an HR admin can be demoted. Building a payroll register for
 * somebody who lost `payroll.view` forty seconds ago is the same leak with
 * a delay, and the delay is exactly where a real revocation lands.
 *
 * Retries: three, with backoff. A transient database blip is worth
 * retrying; a report that is too big, an unknown key or a revoked
 * permission is not, so those are caught, written to the row as `failed`,
 * and swallowed — the queue would otherwise spend its retries proving the
 * same thing three times and the user would see `pending` the whole way.
 */
class BuildReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $reportExportId) {}

    public function handle(ReportExporter $exporter): void
    {
        // The definition is resolved by hand rather than injected: it
        // comes from a key stored in a *row*, and Laravel cannot type-hint
        // something that does not exist until that row has been read.
        $export = ReportExport::query()->find($this->reportExportId);

        if ($export === null) {
            // Deleted while queued — a swept row, or an operator cleanup.
            // Nothing to build, and nothing to report about.
            return;
        }

        $user = $export->user;

        if ($user === null) {
            $this->fail_export($export, 'The account that requested this export no longer exists.');

            return;
        }

        $definition = ReportRegistry::get($export->report_key);

        if ($definition === null) {
            $this->fail_export($export, 'This report no longer exists.');

            return;
        }

        if (! $user->can($definition->permission)) {
            $this->fail_export($export, 'The account that requested this export no longer has permission to run it.');

            return;
        }

        try {
            $filters = ReportFilters::narrow($definition, (array) ($export->filters ?? []));

            $absolute = Storage::disk('local')->path($export->path);
            Storage::disk('local')->makeDirectory(dirname($export->path));

            $rows = $exporter->exportToFile(
                $definition,
                $filters,
                $export->format,
                $absolute,
                $user,
                $this->maxRows($export->format),
            );
        } catch (ValidationException $exception) {
            $this->fail_export($export, trim(implode(' ', $exception->errors()['format'] ?? ['Export failed.'])));

            return;
        } catch (Throwable $exception) {
            // Back to `failed` before the retry: a row that reads
            // `pending` for the length of three backoffs is a row a
            // support person will chase for no reason.
            $this->fail_export($export, 'Export failed while building the file.');

            Log::warning('report_export.build_failed', [
                'export_id' => $export->id,
                'report_key' => $export->report_key,
                'format' => $export->format,
                'user_id' => $user->id,
                // The exception's own message only — a query builder's
                // message can carry bindings, and bindings are rows.
                'reason' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $export->update([
            'status' => ReportExport::STATUS_READY,
            'row_count' => $rows,
            'error' => null,
            'completed_at' => now(),
        ]);

        Log::info('report_export.completed', [
            'export_id' => $export->id,
            'report_key' => $export->report_key,
            'format' => $export->format,
            'rows' => $rows,
            'user_id' => $user->id,
        ]);
    }

    /**
     * The ceiling for this format — see `hrms.reporting`. A PDF is held to
     * the inline cap because a six-thousand-row PDF is not a document;
     * CSV and XLSX get the larger queue-specific number because they are
     * exactly the formats that exist for the big cases.
     */
    private function maxRows(string $format): int
    {
        if ($format === ReportExport::FORMAT_PDF) {
            return (int) config('hrms.reporting.export_max_rows');
        }

        return (int) config('hrms.reporting.queue_max_rows');
    }

    private function fail_export(ReportExport $export, string $reason): void
    {
        $export->update([
            'status' => ReportExport::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 500),
            'completed_at' => now(),
        ]);

        Log::info('report_export.failed', [
            'export_id' => $export->id,
            'report_key' => $export->report_key,
            'format' => $export->format,
            'user_id' => $export->user_id,
            'reason' => $reason,
        ]);
    }
}
