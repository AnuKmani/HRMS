<?php

namespace App\Services\SiteReport;

use App\Models\DailySiteReport;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The daily site report as a document.
 *
 * **Generated on demand, never stored.** The endpoint builds, renders and
 * streams in one request and does not write a `.pdf` anywhere. That is the
 * whole design decision: a stored file is a copy of a row that can still
 * change, and the only way to stop a draft becoming stale next to its own
 * PDF is to not have one — so `GET .../pdf` always renders what the row says
 * *now*, and there is no cache-invalidation problem because there is no
 * cache. The cost is a few hundred milliseconds of dompdf on each request,
 * which is the right trade for a document a person opens a handful of times
 * a week.
 *
 * **No public URL, ever.** The response is `no-store`, carries a
 * `Content-Disposition` and no `Content-Location`, and sits behind
 * `daily_site_reports.pdf` *plus* the row-level policy — a permission that
 * only grants PDFs for reports the same account could already read.
 *
 * **Photographs are embedded, not linked.** dompdf will happily fetch an
 * `http://` URL if `enable_remote` is on, and a private disk path is not a
 * URL at all, so each photo is read through ReportPhotoStore's containment
 * check and inlined as a `data:` URI — which dompdf decodes locally and
 * never resolves over the network. Capped at {@see self::MAX_PHOTOS}: a
 * report with twenty frames would otherwise build a twenty-megabyte
 * document nobody can open on a site laptop, and six is enough to
 * illustrate one site-day.
 *
 * `html()` is separate from `response()` on purpose: the *content* is
 * asserted directly by the test suite, while the *transport* is asserted
 * through the endpoint. Asserting on bytes of a compressed PDF would test
 * dompdf rather than this class.
 */
final class DailySiteReportPdf
{
    /** Photographs embedded in the document, at most. */
    public const MAX_PHOTOS = 6;

    public function __construct(
        private readonly ReportPhotoStore $photos,
        private readonly SettingsService $settings,
    ) {}

    public function response(DailySiteReport $report): Response
    {
        $bytes = Pdf::loadHTML($this->html($report), 'UTF-8')
            ->setPaper('a4', 'portrait')
            ->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            // `inline`, not `attachment`: the person asked to *read* it, and
            // the browser's save dialog is one click away from that anyway.
            'Content-Disposition' => 'inline; filename="'.$this->filename($report).'"',
            // Generated fresh every time — a report a supervisor is editing
            // right now must not be handed back from somebody's proxy.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The whole document as HTML. Every value is escaped: work descriptions,
     * remarks and photo captions are free text typed on a phone, and a
     * `<script>` that reaches a PDF is still a script that reached a parser.
     */
    public function html(DailySiteReport $report): string
    {
        $company = $this->settings->string('reporting.company_name', (string) config('app.name'));
        $dateFormat = $this->settings->string('system.date_format', 'd/m/Y');

        $date = $report->report_date?->format($dateFormat) ?? '—';
        $siteName = $report->site?->name ?? '—';
        $projectName = $report->project
            ? $report->project->name.($report->project->code ? ' ('.$report->project->code.')' : '')
            : '—';
        $preparedBy = $report->creator?->name ?? '—';

        $sections = [
            'Work planned' => $report->work_planned,
            'Work completed' => $report->work_completed,
            'Safety observations' => $report->safety_observations,
            'Delays' => $report->delays,
            'Issues' => $report->issues,
            'Remarks' => $report->remarks,
        ];

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'.$this->styles().'</style></head><body>'
            .$this->heading($company, $report, $dateFormat)
            .$this->meta([
                'Project' => $projectName,
                'Site' => $siteName,
                'Date' => $date,
                'Prepared by' => $preparedBy,
                'Status' => ucfirst($report->status),
                'Submitted' => $report->submitted_at?->format($dateFormat.' H:i') ?? 'Not yet submitted',
                'Reference' => '#'.$report->id,
            ])
            .$this->manpower($report)
            .$this->narratives($sections)
            .$this->table('Materials used', ['Material', 'Quantity', 'Unit', 'Remarks'], $report->materials->map(fn ($row) => [
                $row->material_name,
                $row->quantity,
                $row->unit,
                $row->remarks ?? '',
            ])->all())
            .$this->table('Equipment used', ['Item', 'Qty', 'Hours', 'Condition', 'Remarks'], $report->equipment->map(fn ($row) => [
                $row->equipment_name,
                $row->quantity,
                $row->operating_hours ?? '—',
                $row->condition ?? '—',
                $row->remarks ?? '',
            ])->all())
            .$this->photos($report)
            .$this->footer($report)
            .'</body></html>';
    }

    /**
     * `daily-site-report-6-28092026.pdf` — a name for the download dialog,
     * minted from the row. No path, no directory, nothing a client could
     * redirect.
     */
    public function filename(DailySiteReport $report): string
    {
        return sprintf(
            'daily-site-report-%d-%s.pdf',
            $report->getKey(),
            $report->report_date?->format('dmY') ?? 'undated',
        );
    }

    private function heading(string $company, DailySiteReport $report, string $dateFormat): string
    {
        $date = $report->report_date?->format($dateFormat) ?? '';

        return '<div class="doc-head">'
            .'<div class="company">'.e($company).'</div>'
            .'<div class="title">Daily Site Report</div>'
            .'<div class="subtitle">'.e($report->site?->name ?? '').' &middot; '.e($date).'</div>'
            .'</div>';
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function meta(array $rows): string
    {
        $html = '<table class="meta">';

        foreach ($rows as $label => $value) {
            $html .= '<tr><th>'.e($label).'</th><td>'.e($value).'</td></tr>';
        }

        return $html.'</table>';
    }

    private function manpower(DailySiteReport $report): string
    {
        $rows = $report->manpower;

        $html = '<div class="section"><div class="section-title">Manpower</div>'
            .'<div class="total">Total head count: <strong>'.(int) $report->total_manpower.'</strong></div>';

        if ($rows->isEmpty()) {
            return $html.'<p class="muted">No categories recorded.</p></div>';
        }

        $html .= '<table><tr><th>Category</th><th class="num">Count</th></tr>';

        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row->category).'</td><td class="num">'.(int) $row->count.'</td></tr>';
        }

        return $html.'</table></div>';
    }

    /**
     * The prose half of the document. An empty section is printed as "Not
     * recorded" rather than omitted, because a reader cannot tell the
     * difference between "there were no delays" and "the person writing this
     * skipped the field" unless the document says which it is.
     *
     * @param  array<string, string|null>  $sections
     */
    private function narratives(array $sections): string
    {
        $html = '<div class="section"><div class="section-title">Work &amp; site record</div>';

        foreach ($sections as $label => $value) {
            $text = trim((string) $value);

            $html .= '<div class="block">'
                .'<div class="block-label">'.e($label).'</div>'
                .'<div class="block-body">'.($text === '' ? '<span class="muted">Not recorded</span>' : nl2br(e($text))).'</div>'
                .'</div>';
        }

        return $html.'</div>';
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function table(string $title, array $headers, array $rows): string
    {
        $html = '<div class="section"><div class="section-title">'.e($title).'</div>';

        if ($rows === []) {
            return $html.'<p class="muted">None recorded.</p></div>';
        }

        $html .= '<table><tr>';

        foreach ($headers as $header) {
            $html .= '<th>'.e($header).'</th>';
        }

        $html .= '</tr>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.e((string) $cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table></div>';
    }

    private function photos(DailySiteReport $report): string
    {
        $photos = $report->photos->take(self::MAX_PHOTOS);

        $html = '<div class="section"><div class="section-title">Photographs</div>';

        if ($photos->isEmpty()) {
            return $html.'<p class="muted">None attached.</p></div>';
        }

        $html .= '<table class="photos"><tr>';

        foreach ($photos as $index => $photo) {
            $src = $this->embed($photo->path);

            if ($src === null) {
                continue;
            }

            $caption = trim((string) $photo->caption);
            $label = $caption !== '' ? $caption : 'Photo '.($index + 1);

            $html .= '<td class="photo">'
                .'<img src="'.$src.'" alt="" />'
                .'<div class="caption">'.e($label).'</div>'
                .'</td>';
        }

        $html .= '</tr></table>';

        $hidden = $report->photos->count() - $photos->count();

        if ($hidden > 0) {
            $html .= '<p class="muted">'.$hidden.' more photograph(s) attached to this report.</p>';
        }

        return $html.'</div>';
    }

    /**
     * A `data:` URI for a stored photograph, or null if it is not there.
     *
     * The containment check is ReportPhotoStore's, not an ad-hoc one: the
     * same rule that decides whether the API may stream a file decides
     * whether the PDF may read it, so a corrupted row cannot turn a document
     * generator into a file reader.
     */
    private function embed(string $path): ?string
    {
        if (! $this->photos->exists($path)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }

    private function footer(DailySiteReport $report): string
    {
        return '<div class="doc-foot">'
            .'Generated '.e(now()->format('d/m/Y H:i:s'))
            .' &middot; Daily site report #'.$report->getKey()
            .' &middot; '.e($report->site?->name ?? '')
            .'</div>';
    }

    private function styles(): string
    {
        return '
            @page { margin: 16mm 14mm; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1c1c; }
            .doc-head { border-bottom: 2px solid #1c1c1c; padding-bottom: 8px; margin-bottom: 14px; }
            .company { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #555; }
            .title { font-size: 20px; font-weight: bold; margin-top: 4px; }
            .subtitle { font-size: 11px; color: #555; margin-top: 2px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
            th, td { border: 1px solid #c8c8c8; padding: 5px 6px; text-align: left; vertical-align: top; }
            th { background: #f0f0f0; font-weight: bold; }
            table.meta th { width: 32%; background: #f7f7f7; }
            .num { text-align: right; }
            .section { margin-top: 14px; page-break-inside: avoid; }
            .section-title { font-size: 12px; font-weight: bold; text-transform: uppercase;
                letter-spacing: 1px; border-bottom: 1px solid #c8c8c8; padding-bottom: 3px; margin-bottom: 7px; }
            .total { font-size: 12px; margin-bottom: 6px; }
            .block { margin-bottom: 8px; page-break-inside: avoid; }
            .block-label { font-weight: bold; color: #333; margin-bottom: 2px; }
            .block-body { line-height: 1.45; }
            .muted { color: #777; font-style: italic; }
            table.photos td.photo { width: 50%; text-align: center; border: none; padding: 4px; }
            table.photos img { width: 100%; max-height: 180px; }
            .caption { font-size: 9px; color: #555; margin-top: 2px; }
            .doc-foot { margin-top: 18px; border-top: 1px solid #c8c8c8; padding-top: 6px;
                font-size: 9px; color: #777; }
        ';
    }
}
