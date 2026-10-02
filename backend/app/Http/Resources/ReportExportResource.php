<?php

namespace App\Http\Resources;

use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued export, as its owner sees it.
 *
 * `path` is hidden on the model and never named here: the file lives in
 * private storage under a UUID nobody was told, and the only way to read
 * it is `GET /report-exports/{id}/file`, which re-checks ownership. A
 * resource that returned the path would turn "guess a 36-character UUID"
 * into "read one list response", and the download route would be the only
 * protection left on a payroll register.
 *
 * `download_url` is only present when there is something to download —
 * `null` for a row still building or a row that failed, so a client never
 * offers a link that will answer 404 or hand back an empty file.
 */
class ReportExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReportExport $export */
        $export = $this->resource;

        $ready = $export->status === ReportExport::STATUS_READY;

        return [
            'id' => $export->id,
            'report_key' => $export->report_key,
            'format' => $export->format,
            'status' => $export->status,
            'filters' => $export->filters ?? [],
            'row_count' => $export->row_count,
            'error' => $export->error,
            'ready' => $ready,
            'download_url' => $ready
                ? '/api/v1/report-exports/'.$export->id.'/file'
                : null,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
        ];
    }
}
