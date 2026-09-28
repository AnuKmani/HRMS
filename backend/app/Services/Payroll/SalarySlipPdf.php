<?php

namespace App\Services\Payroll;

use App\Models\Payroll;
use App\Services\SettingsService;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * One month's pay, as a document.
 *
 * Follows the Phase 7 rules exactly, because a payslip is the same kind of
 * object as a site report: something a person opens, reads and possibly
 * prints, that must always say what the row says *now*.
 *
 * **Generated on demand, never stored.** A stored payslip is a copy of a
 * row, and payroll rows are legitimately recalculated while they are still
 * `draft` or `calculated`. Rendering fresh each time means there is no
 * stale-PDF problem to solve, no storage to secure and no download URL that
 * could leak - which is why nothing here touches a disk. The cost is a few
 * hundred milliseconds of dompdf, paid once per document a person opens.
 *
 * **No public URL.** `no-store`, `inline`, `nosniff`, a filename derived
 * from the row - and it sits behind `salary_slips.view` *and* the row-level
 * policy, so the permission only grants documents for payrolls the same
 * account could already read.
 *
 * **Money is printed from the row, never re-derived.** `basic`, `divisor`,
 * `lop_days` and every item amount are read straight back off the columns,
 * so the document a person screenshots in March still explains itself after
 * an operator retunes the divisor in April. Where a per-day or per-hour rate
 * is shown it comes from the item's own `rate`, which was frozen when the
 * item was written.
 *
 * `html()` is separate from `response()` for the same reason as Phase 7:
 * the suite asserts on the content of the markup, the endpoint asserts on
 * the transport, and neither has to parse compressed PDF bytes to do it.
 */
final class SalarySlipPdf
{
    public function __construct(private readonly SettingsService $settings) {}

    public function response(Payroll $payroll): Response
    {
        $bytes = Pdf::loadHTML($this->html($payroll), 'UTF-8')
            ->setPaper('a4', 'portrait')
            ->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filename($payroll).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The whole document as HTML. Every value is escaped - a purpose field,
     * an allowance label and a bonus description are all free text somebody
     * typed, and `<script>` reaching a PDF is still a script reaching a
     * parser.
     */
    public function html(Payroll $payroll): string
    {
        $employee = $payroll->employee;
        $currency = $this->settings->string('system.currency', 'INR');
        $dateFormat = $this->settings->string('system.date_format', 'd/m/Y');
        $company = $this->settings->string('reporting.company_name', (string) config('app.name'));

        $earnings = $payroll->items->where('type', 'earning');
        $deductions = $payroll->items->where('type', 'deduction');

        $rows = fn ($items) => $items->isEmpty()
            ? '<tr><td colspan="3" class="none">None</td></tr>'
            : $items->map(fn ($item) => '<tr>'
                .'<td>'.$this->e($item->description).'</td>'
                .'<td class="num">'.$this->qty($item).'</td>'
                .'<td class="num">'.$this->money($item->amount, $currency).'</td>'
                .'</tr>')->implode('');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            .$this->styles()
            .'</style></head><body>'

            .'<table class="head"><tr>'
            .'<td><div class="company">'.$this->e($company).'</div>'
            .'<div class="muted">Salary slip</div></td>'
            .'<td class="right">'
            .'<div class="period">'.$this->e($payroll->periodLabel()).'</div>'
            .'<div class="muted">'.$this->e($payroll->period_start?->format($dateFormat))
            .' &ndash; '.$this->e($payroll->period_end?->format($dateFormat)).'</div>'
            .'<div><span class="pill">'.strtoupper($this->e($payroll->status)).'</span></div>'
            .'</td></tr></table>'

            .'<table class="meta">'
            .'<tr><th>Employee</th><td>'.$this->e($employee?->full_name ?? 'Unknown').'</td>'
            .'<th>Employee code</th><td>'.$this->e($employee?->employee_code ?? '—').'</td></tr>'
            .'<tr><th>Department</th><td>'.$this->e($employee?->department?->name ?? '—').'</td>'
            .'<th>Designation</th><td>'.$this->e($employee?->designation?->name ?? '—').'</td></tr>'
            .'<tr><th>Joining date</th><td>'.$this->e($employee?->joining_date?->format($dateFormat) ?? '—').'</td>'
            .'<th>Generated</th><td>'.$this->e(now()->format($dateFormat)).'</td></tr>'
            .'</table>'

            .'<h2>Earnings</h2>'
            .'<table class="lines"><thead><tr><th>Description</th><th class="num">Qty</th>'
            .'<th class="num">Amount ('.$this->e($currency).')</th></tr></thead>'
            .'<tbody>'.$rows($earnings).'</tbody>'
            .'<tfoot><tr><th colspan="2">Gross salary</th>'
            .'<th class="num">'.$this->money($payroll->gross_salary, $currency).'</th></tr></tfoot></table>'

            .'<h2>Deductions</h2>'
            .'<table class="lines"><thead><tr><th>Description</th><th class="num">Qty</th>'
            .'<th class="num">Amount ('.$this->e($currency).')</th></tr></thead>'
            .'<tbody>'.$rows($deductions).'</tbody>'
            .'<tfoot><tr><th colspan="2">Total deductions</th>'
            .'<th class="num">'.$this->money($payroll->total_deductions, $currency).'</th></tr></tfoot></table>'

            .'<table class="totals">'
            .'<tr><th>Unpaid days</th><td class="num">'
            .$this->e($this->num($payroll->lop_days)).' &divide; '
            .$this->e($this->num($payroll->lop_divisor)).'</td></tr>'
            .'<tr><th>Overtime paid</th><td class="num">'
            .$this->e((string) (int) $payroll->overtime_minutes).' min</td></tr>'
            .'<tr class="net"><th>Net salary</th>'
            .'<td class="num">'.$this->money($payroll->net_salary, $currency).'</td></tr>'
            .'</table>'

            .'<p class="fine">This slip is generated from payroll record #'
            .(int) $payroll->getKey().' on demand and is not a signed document. '
            .'Figures are shown as at the date of generation; a slip regenerated '
            .'after a correction will reflect that correction.</p>'

            .'</body></html>';
    }

    public function filename(Payroll $payroll): string
    {
        return sprintf(
            'salary-slip-%s-%02d-%d.pdf',
            preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payroll->employee?->employee_code) ?: 'employee',
            (int) $payroll->payroll_month,
            (int) $payroll->payroll_year,
        );
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * A line's quantity, when it has one - days, hours or a count. Blank
     * rather than "1" for a flat figure: "Basic salary | 1 | 30,000.00" is
     * noise, and a column nobody reads is a column that trains people not
     * to read the ones that matter.
     */
    private function qty($item): string
    {
        if ($item->quantity === null || $item->quantity === '') {
            return '';
        }

        $quantity = (float) $item->quantity;

        if ((int) $quantity === $quantity) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    private function num($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function money($amount, string $currency): string
    {
        return $this->e($currency.' '.Money::decimal($amount));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function styles(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111;}'
            .'table{width:100%;border-collapse:collapse;}'
            .'.head td{padding:0 0 14px 0;vertical-align:top;}'
            .'.company{font-size:17px;font-weight:bold;}'
            .'.period{font-size:14px;font-weight:bold;}'
            .'.muted{color:#666;}'
            .'.right{text-align:right;}'
            .'.pill{background:#eee;border:1px solid #ccc;padding:2px 8px;border-radius:9px;font-size:9px;letter-spacing:1px;}'
            .'.meta th{width:16%;text-align:left;color:#666;font-weight:normal;padding:3px 8px 3px 0;}'
            .'.meta td{width:34%;padding:3px 0;}'
            .'h2{font-size:12px;margin:16px 0 5px;border-bottom:1px solid #333;padding-bottom:2px;}'
            .'.lines th{text-align:left;border-bottom:1px solid #333;padding:3px 0;font-size:10px;}'
            .'.lines td{padding:4px 0;border-bottom:1px dotted #ccc;vertical-align:top;}'
            .'.lines tfoot th{border-bottom:none;border-top:1px solid #333;padding-top:5px;}'
            .'.num{text-align:right;}'
            .'.none{color:#888;font-style:italic;padding:6px 0;}'
            .'.totals{margin-top:14px;width:46%;margin-left:auto;}'
            .'.totals th{text-align:left;font-weight:normal;color:#666;padding:4px 0;border-bottom:1px dotted #ccc;}'
            .'.totals td{padding:4px 0;border-bottom:1px dotted #ccc;}'
            .'.totals .net th,.totals .net td{font-weight:bold;font-size:14px;border-bottom:2px solid #333;border-top:1px solid #333;}'
            .'.fine{margin-top:18px;color:#666;font-size:9px;line-height:1.5;}';
    }
}
