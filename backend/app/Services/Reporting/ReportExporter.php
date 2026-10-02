<?php

namespace App\Services\Reporting;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a filtered report into a file: CSV, XLSX or PDF.
 *
 * All three go through the same paging loop, because they are the same
 * report — a CSV that read a different query from the PDF beside it would
 * be two documents with one name. The difference is only in how a row is
 * written, and that difference is `match ($format)`, not a second query
 * builder.
 *
 * **Everything is written to a file first, never straight to the socket.**
 * openspout's `openToBrowser()` sets its own headers and clears PHP's
 * output buffer, which would race with the headers Laravel has already
 * sent for a streamed response; and dompdf cannot produce a byte until it
 * has the whole document. Writing to a path and then streaming that file
 * gives one code path for the request-time export and the queued one, and
 * it keeps a 40,000-row CSV off the heap — the file is on disk, and the
 * chunk callback holds five hundred rows at a time.
 *
 * **Money is a number, not a formatted string.** A CSV cell of
 * `AED 125,000.00` is a word to Excel and cannot be summed; `125000.00`
 * is a figure and can. The currency sits in its own column where the
 * report has one (expenses) and is AED where it does not — the payroll
 * register's columns are labelled `Basic`, `Gross`, `Net`, and the
 * document does not claim a currency it was not told.
 *
 * Dates are written as ISO `Y-m-d` strings rather than as Excel date
 * cells: ISO sorts as a date does, survives a CSV round trip unchanged,
 * and never depends on the reader's locale to mean the 3rd of April
 * rather than the 4th of March.
 */
class ReportExporter
{
    private const CHUNK = 500;

    public function __construct(
        private readonly ReportService $reports,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Build the file at `$path`. Returns the number of rows written.
     *
     * The row cap is re-counted here rather than trusted from the
     * controller: the queued export runs minutes later, on data that has
     * moved, and a job that discovers a 90,000-row report only when it is
     * already 80,000 rows into a file is a job that has to fail after
     * doing the work. Counting first means it fails before it.
     *
     * @param  array<string, string>  $filters
     *
     * @throws ValidationException
     */
    public function exportToFile(
        ReportDefinition $definition,
        array $filters,
        string $format,
        string $path,
        User $requestedBy,
        int $maxRows,
    ): int {
        $total = $this->reports->count($definition, $filters);

        if ($total > $maxRows) {
            throw ValidationException::withMessages([
                'format' => sprintf(
                    '%s rows exceeds the %d-row limit for a %s export. Narrow the filters (a date range is usually enough) and try again.',
                    number_format($total),
                    $maxRows,
                    $format,
                ),
            ]);
        }

        return match ($format) {
            ReportExport::FORMAT_CSV => $this->csv($definition, $filters, $path),
            ReportExport::FORMAT_XLSX => $this->xlsx($definition, $filters, $path),
            ReportExport::FORMAT_PDF => $this->pdf($definition, $filters, $path, $requestedBy, $total),
            default => throw ValidationException::withMessages([
                'format' => 'Unknown export format. Use csv, xlsx or pdf.',
            ]),
        };
    }

    /**
     * The relative path on the private disk for a new export.
     *
     * Under the owning user's directory and named by UUID, so two people
     * exporting the same report cannot collide, nobody can guess a path
     * from a report key alone, and a later sweep of `exports/` can expire
     * by prefix without parsing anything.
     */
    public function pathFor(User $user, string $format): string
    {
        return sprintf('exports/%d/%s.%s', $user->id, (string) Str::uuid(), $format);
    }

    /* ------------------------------------------------------------- formats */

    /**
     * @param  array<string, string>  $filters
     */
    private function csv(ReportDefinition $definition, array $filters, string $path): int
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw ValidationException::withMessages(['format' => 'Could not open a file to write the export to.']);
        }

        try {
            // A UTF-8 BOM, because the alternative is Excel deciding that
            // a name with an accent in it is a different encoding and
            // printing a replacement character into somebody's payroll
            // register. The cost is three bytes.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $definition->headings());

            return $this->reports->chunk($definition, $filters, self::CHUNK, function (array $rows) use ($handle, $definition): void {
                foreach ($rows as $row) {
                    fputcsv($handle, $this->values($definition, $row));
                }
            });
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function xlsx(ReportDefinition $definition, array $filters, string $path): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues($definition->headings()));

            return $this->reports->chunk($definition, $filters, self::CHUNK, function (array $rows) use ($writer, $definition): void {
                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues($this->cells($definition, $row)));
                }
            });
        } finally {
            // Always close: an XLSX left open is a zip without its central
            // directory, and a half-written file is worse than no file —
            // it opens, shows a header row and silently stops.
            $writer->close();
        }
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function pdf(
        ReportDefinition $definition,
        array $filters,
        string $path,
        User $requestedBy,
        int $total,
    ): int {
        $rows = [];

        $written = $this->reports->chunk($definition, $filters, self::CHUNK, function (array $slice) use (&$rows): void {
            array_push($rows, ...$slice);
        });

        file_put_contents($path, Pdf::loadHTML($this->html($definition, $filters, $rows, $requestedBy, $total), 'UTF-8')
            ->setPaper('a4', 'landscape')
            ->output());

        return $written;
    }

    /* ----------------------------------------------------------- formatting */

    /**
     * The row as CSV cells: raw, in column order, nothing formatted.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    private function values(ReportDefinition $definition, array $row): array
    {
        $values = [];

        foreach ($definition->columns as $column) {
            $values[] = $row[$column['key']] ?? null;
        }

        return $values;
    }

    /**
     * The row as spreadsheet cells: numerics as numbers so a column can be
     * summed, everything else as the string it already is.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    private function cells(ReportDefinition $definition, array $row): array
    {
        $numeric = ['number', 'money', 'minutes'];
        $cells = [];

        foreach ($definition->columns as $column) {
            $value = $row[$column['key']] ?? null;

            if ($value !== null && in_array($column['type'], $numeric, true) && is_numeric($value)) {
                $cells[] = $value + 0;

                continue;
            }

            $cells[] = $value === null ? '' : (string) $value;
        }

        return $cells;
    }

    /**
     * The whole document as HTML. Every value is escaped: department
     * names, site addresses and expense descriptions are typed by people,
     * and a report that renders `<script>` because somebody named a site
     * after a joke is a report that became an attack.
     *
     * @param  array<string, string>  $filters
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function html(
        ReportDefinition $definition,
        array $filters,
        array $rows,
        User $requestedBy,
        int $total,
    ): string {
        $company = e($this->settings->string('reporting.company_name', (string) config('app.name')));
        $headings = '';

        foreach ($definition->columns as $column) {
            $headings .= '<th>'.e($column['label']).'</th>';
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr>';

            foreach ($definition->columns as $column) {
                $class = $column['type'] === 'money' ? ' class="n"' : '';
                $body .= '<td'.$class.'>'.$this->pdfValue($row[$column['key']] ?? null, $column['type']).'</td>';
            }

            $body .= '</tr>';
        }

        // A report with no rows still prints: "no records matched" is a
        // fact the person filing it needs to see, and a blank page looks
        // like a broken printer.
        if ($body === '') {
            $body = '<tr><td class="empty" colspan="'.count($definition->columns).'">No records matched these filters.</td></tr>';
        }

        $generated = now()->format('Y-m-d H:i');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#111;margin:14px}
            h1{font-size:15px;margin:0 0 2px}
            .sub{font-size:10px;color:#444;margin:0 0 10px}
            .meta{font-size:9px;color:#333;margin:0 0 10px;border-bottom:1px solid #ccc;padding-bottom:8px}
            table{width:100%;border-collapse:collapse}
            th{background:#f0f0f0;text-align:left;font-size:8px;text-transform:uppercase;letter-spacing:.4px;
               border:1px solid #ccc;padding:4px 5px}
            td{border:1px solid #ddd;padding:3px 5px;font-size:9px;vertical-align:top}
            td.n{text-align:right;font-family:DejaVu Sans Mono,monospace}
            td.empty{text-align:center;color:#666;padding:16px;font-style:italic}
            .foot{margin-top:12px;font-size:8px;color:#666;border-top:1px solid #ccc;padding-top:6px}
        </style></head><body>
        <h1>'.$company.' — '.e($definition->title).'</h1>
        <p class="sub">'.e($definition->description).'</p>
        <p class="meta">
            Filters: '.e(ReportFilters::describe($filters)).'<br>
            Rows: '.number_format($total).' &nbsp;·&nbsp; Generated: '.$generated.'
            &nbsp;·&nbsp; By: '.e($requestedBy->name).'<br>
            Ref: '.e($definition->key).'
        </p>
        <table><thead><tr>'.$headings.'</tr></thead><tbody>'.$body.'</tbody></table>
        <p class="foot">System-generated document. Values are figures as stored; no currency symbol is
        implied where the report carries none. No signature — this printout is not signed and must not be
        treated as one.</p>
        </body></html>';
    }

    /**
     * One cell of the PDF. Money gets two decimals and a thousands
     * separator (the reader of a PDF has no SUM button), dates stay ISO
     * for the same reason they do in a CSV, and null becomes an em dash
     * rather than the word "null".
     */
    private function pdfValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2, '.', ','),
            'number' => is_numeric($value) ? number_format((float) $value, 0, '.', ',') : e((string) $value),
            'minutes' => is_numeric($value) ? (string) (int) $value : e((string) $value),
            default => e((string) $value),
        };
    }

    /**
     * Stream a finished file back to the caller, then remove it.
     *
     * The request-time export is a temporary file nobody needs after the
     * bytes have left the building; leaving it in `sys_get_temp_dir()` is
     * how a server fills with payroll documents nobody can be shown to
     * have kept. The unlink happens *after* the last read, inside the
     * stream, because there is no code that runs after a response is
     * sent.
     *
     * @param  array<string, string>  $headers
     */
    public function download(string $path, string $filename, array $headers = []): StreamedResponse
    {
        return response()->stream(function () use ($path): void {
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                while (! feof($handle)) {
                    $chunk = fread($handle, 1024 * 1024);

                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($handle);
                @unlink($path);
            }
        }, 200, $headers + [
            'Content-Type' => $this->mimeType($filename),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Stream a finished queued export out of private storage. Unlike the
     * request-time file this one stays: the row that produced it is still
     * `ready`, and "download it again" is what a person who closed the
     * tab does next.
     */
    public function downloadStored(string $relativePath, string $filename): StreamedResponse
    {
        return Storage::disk('local')->download($relativePath, $filename, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function mimeType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
