<?php

namespace App\Services\Payroll;

use App\Models\SalaryCertificateRequest;
use App\Services\SettingsService;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * A salary certificate as a document - the same on-demand, never-stored
 * contract as {@see SalarySlipPdf} and Phase 7's site report, for the same
 * reason: a stored certificate is a snapshot of a row, and `employees.salary`
 * is a live column that legitimately changes (a raise, a correction) between
 * two renderings. Rendering fresh means the document always says what the
 * record says now, and there is no file for a URL to point at.
 *
 * **What it prints, and why.** Company name, employee identity, designation,
 * department, joining date, current salary, issue date and a reference - the
 * eight facts a bank or a visa office asks for. The reference is derived
 * from the primary key (see SalaryCertificateRequest::reference()) so it is
 * unique without a sequence to mint, and it is printed exactly as it will be
 * quoted back in correspondence.
 *
 * **What it does not do.** It does not sign anything. There is no signature
 * image, no certificate authority and no cryptographic signature - a
 * document claiming otherwise would be a lie a reader could reasonably rely
 * on. Instead the footer says plainly that it is system-generated and awaits
 * an authorised signatory, and there is a printed signature line for a
 * person to actually sign. That is honest about the level of assurance the
 * document carries, and it is what the spec asks for.
 *
 * `html()` is separate from `response()` so the suite can assert on the
 * facts printed rather than on compressed bytes.
 */
final class SalaryCertificatePdf
{
    public function __construct(private readonly SettingsService $settings) {}

    public function response(SalaryCertificateRequest $request): Response
    {
        $bytes = Pdf::loadHTML($this->html($request), 'UTF-8')
            ->setPaper('a4', 'portrait')
            ->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filename($request).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function html(SalaryCertificateRequest $request): string
    {
        $employee = $request->employee;
        $currency = $this->settings->string('system.currency', 'INR');
        $dateFormat = $this->settings->string('system.date_format', 'd/m/Y');
        $company = $this->settings->string('reporting.company_name', (string) config('app.name'));

        $issued = $request->generated_at ?? $request->approved_at ?? $request->created_at;
        $ref = $request->reference();

        $name = $employee?->full_name ?? '—';
        $salary = $employee?->salary;

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            .$this->styles()
            .'</style></head><body>'

            .'<div class="company">'.$this->e($company).'</div>'
            .'<div class="rule"></div>'
            .'<h1>SALARY CERTIFICATE</h1>'
            .'<div class="ref">Reference: '.$this->e($ref).' &nbsp;&middot;&nbsp; Issued: '
            .$this->e($issued?->format($dateFormat) ?? '—').'</div>'

            .'<p>To whom it may concern,</p>'

            .'<p>This is to certify that <strong>'.$this->e($name).'</strong>'
            .($employee?->employee_code ? ' (Employee code: '.$this->e($employee->employee_code).')' : '')
            .' is employed with <strong>'.$this->e($company).'</strong>'
            .($employee?->designation ? ' as <strong>'.$this->e($employee->designation->name).'</strong>' : '')
            .($employee?->department ? ' in the '.$this->e($employee->department->name).' department' : '')
            .($employee?->joining_date
                ? ', and has been in service since <strong>'
                    .$this->e($employee->joining_date->format($dateFormat)).'</strong>'
                : '')
            .', as on the date of this certificate.</p>'

            .'<table class="facts">'
            .'<tr><th>Employee name</th><td>'.$this->e($name).'</td></tr>'
            .'<tr><th>Employee code</th><td>'.$this->e($employee?->employee_code ?? '—').'</td></tr>'
            .'<tr><th>Designation</th><td>'.$this->e($employee?->designation?->name ?? '—').'</td></tr>'
            .'<tr><th>Department</th><td>'.$this->e($employee?->department?->name ?? '—').'</td></tr>'
            .'<tr><th>Date of joining</th><td>'
            .$this->e($employee?->joining_date?->format($dateFormat) ?? '—').'</td></tr>'
            .'<tr><th>Current monthly salary</th><td class="salary">'
            .($salary === null || $salary === ''
                ? 'Not on record'
                : $this->e($currency.' '.Money::decimal($salary)))
            .'</td></tr>'
            .'<tr><th>Issued on</th><td>'.$this->e($issued?->format($dateFormat) ?? '—').'</td></tr>'
            .'</table>'

            .'<p class="fine">This certificate is generated from the organisation&rsquo;s '
            .'payroll records on the date shown above and reflects the salary of record at '
            .'that time. It is not digitally signed. It becomes valid when signed below by an '
            .'authorised signatory of '.$this->e($company).'.</p>'

            .'<table class="sign"><tr>'
            .'<td><div class="line"></div>Authorised signatory</td>'
            .'<td class="right"><div class="line"></div>Date</td>'
            .'</tr></table>'

            .'</body></html>';
    }

    public function filename(SalaryCertificateRequest $request): string
    {
        return 'salary-certificate-'.$request->reference().'.pdf';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function styles(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111;line-height:1.7;}'
            .'.company{font-size:19px;font-weight:bold;}'
            .'.rule{border-bottom:2px solid #333;margin:6px 0 20px;}'
            .'h1{font-size:16px;letter-spacing:3px;text-align:center;margin:26px 0 6px;}'
            .'.ref{text-align:center;color:#666;font-size:10px;margin-bottom:26px;}'
            .'.facts{width:82%;margin:20px 0;border-collapse:collapse;}'
            .'.facts th{text-align:left;width:38%;font-weight:normal;color:#666;'
            .'padding:6px 12px 6px 0;border-bottom:1px dotted #ccc;vertical-align:top;}'
            .'.facts td{padding:6px 0;border-bottom:1px dotted #ccc;}'
            .'.salary{font-weight:bold;}'
            .'.fine{margin-top:22px;color:#666;font-size:9.5px;}'
            .'.sign{margin-top:46px;width:100%;border-collapse:collapse;}'
            .'.sign td{width:50%;vertical-align:bottom;padding-right:30px;font-size:10px;color:#666;}'
            .'.sign .right{text-align:right;padding-right:0;padding-left:30px;}'
            .'.line{border-bottom:1px solid #333;height:34px;}';
    }
}
