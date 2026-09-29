<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Base configurable business rules. Nothing in the app may hard-code
     * these — read them through SettingsService instead.
     *
     * `value` here is the factory default; `php artisan tinker` or the
     * settings screen can change it at runtime without a deploy.
     *
     * @var array<int, array<string, string|bool>>
     */
    public const SETTINGS = [
        // --- attendance -------------------------------------------------
        [
            'key' => 'attendance.grace_period_minutes',
            'value' => '10',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'Attendance grace period',
            'description' => 'Minutes after shift start before a check-in is marked late.',
        ],
        [
            'key' => 'attendance.overtime_threshold_minutes',
            'value' => '30',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'Overtime threshold',
            'description' => 'Minutes worked beyond minimum_working_hours before overtime accrues.',
        ],
        [
            'key' => 'attendance.default_geofence_radius_m',
            'value' => '100',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'Default geofence radius (m)',
            'description' => 'Fallback radius in metres when a site has no radius of its own.',
        ],
        [
            'key' => 'attendance.late_arrival_grace_count',
            'value' => '3',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'Late arrivals before escalation',
            'description' => 'Consecutive late check-ins that trigger an HR notification.',
        ],

        // --- leave ------------------------------------------------------
        [
            // 2 days, the default the specification asks for. A leave type
            // with its own `document_deadline_days` overrides this — see
            // LeaveType::documentDeadlineDays().
            'key' => 'leave.sick_certificate_deadline_days',
            'value' => '2',
            'type' => 'integer',
            'group' => 'leave',
            'label' => 'Sick certificate deadline',
            'description' => 'Days after returning from sick leave to upload a medical certificate.',
        ],
        [
            'key' => 'leave.max_consecutive_days_without_certificate',
            'value' => '2',
            'type' => 'integer',
            'group' => 'leave',
            'label' => 'Certificate required after N days',
            'description' => 'Sick leave longer than this many days always requires a certificate.',
        ],

        // --- notifications ---------------------------------------------
        [
            'key' => 'notification.reminder_offset_minutes',
            'value' => '15',
            'type' => 'integer',
            'group' => 'notification',
            'label' => 'Shift reminder lead time',
            'description' => 'Minutes before shift start to push a check-in reminder.',
        ],
        [
            'key' => 'notification.daily_digest_time',
            'value' => '09:00',
            'type' => 'time',
            'group' => 'notification',
            'label' => 'Daily digest time',
            'description' => 'Local time the daily HR summary is dispatched.',
        ],
        [
            'key' => 'notification.sick_cert_expiry_warning_days',
            'value' => '7',
            'type' => 'integer',
            'group' => 'notification',
            'label' => 'Document expiry warning (days)',
            'description' => 'Days before an expiry date that a reminder is sent.',
        ],

        // --- working hours ---------------------------------------------
        [
            'key' => 'working_hours.default',
            'value' => '{"start":"09:00","end":"18:00","daily_hours":8,"break_minutes":60,"working_days":["monday","tuesday","wednesday","thursday","friday"]}',
            'type' => 'json',
            'group' => 'working_hours',
            'label' => 'Default working hours',
            'description' => 'Organisation-wide default schedule. Sites may point at a different working_hours row instead of copying these values.',
        ],

        // --- payroll -----------------------------------------------------
        // Phase 8, plus the repayment floor below. Nothing in this block is
        // a number anybody hard-codes: the pay run reads every setting
        // through SettingsService, so retuning one is an UPDATE and not a
        // deploy. `rounding` is deliberately absent from this list - the
        // precision money is stored at is a property of the schema
        // (DECIMAL(12,2)) rather than a rule an operator could break by
        // setting it to 7.
        [
            // `fixed` divides the month by `payroll.lop_divisor` (30 by
            // default - the calendar-month convention). `working_days`
            // divides it by the number of working days the period actually
            // contains, counted by LeaveDayCalculator from
            // `working_hours.default` plus the holiday calendar.
            //
            // Both are legitimate and which is correct is a contractual
            // question, not an engineering one - which is exactly why it is
            // data rather than a constant. See docs/API_DOCUMENTATION.md
            // for the formulas each one produces.
            'key' => 'payroll.lop_divisor_mode',
            'value' => 'fixed',
            'type' => 'string',
            'group' => 'payroll',
            'label' => 'LOP divisor mode',
            'description' => 'How a month is divided to price one lost day: `fixed` (a constant) or `working_days` (the period\'s own working days).',
        ],
        [
            'key' => 'payroll.lop_divisor',
            'value' => '30',
            'type' => 'decimal',
            'group' => 'payroll',
            'label' => 'Fixed LOP divisor',
            'description' => 'Divisor used when the mode above is `fixed`. Also the base for the daily rate that prices overtime.',
        ],
        [
            // The floor a repayment may not push net salary through. Read by
            // PayrollCalculationService when it sizes how much of a due
            // installment this run can actually take, so 0 (the development
            // default) means "never pay less than nothing" and a configured
            // 1000 means "somebody must still walk away with 1000.00".
            //
            // It caps *postponable* deductions only - loan and salary-
            // advance installments, which are owed to the company and can
            // wait for next month. Attendance, unpaid leave and approved
            // adjustments are facts about work already done or not done;
            // rewriting them to protect a floor would falsify the payslip,
            // so they are reported honestly even when they alone take the
            // row below it.
            //
            // UAE statutory wage-protection rules are deliberately not
            // wired in here: which floor is lawful is a jurisdictional
            // question, and applying one silently would be worse than
            // applying none. Validate before production.
            'key' => 'payroll.minimum_net_salary',
            'value' => '0',
            'type' => 'decimal',
            'group' => 'payroll',
            'label' => 'Minimum net salary',
            'description' => 'Loan and salary-advance repayments are capped so this run never pays less than this amount. Attendance and approved deductions are never rewritten.',
        ],
        [
            // A generic multiplier rather than a statutory one. The UAE labour
            // rules (150% basic for overtime, 200% between 22:00 and 04:00)
            // are *not* wired in here, because applying them silently to a
            // company they do not govern would be worse than applying
            // nothing: set 1.5 / 2.0 yourself once payroll configuration has
            // been validated for the jurisdiction actually being paid under.
            'key' => 'payroll.overtime_rate_multiplier',
            'value' => '1.5',
            'type' => 'decimal',
            'group' => 'payroll',
            'label' => 'Overtime rate multiplier',
            'description' => 'Applied to the derived hourly rate for approved, payroll-eligible overtime minutes.',
        ],

        // --- reporting --------------------------------------------------
        [
            // The heading printed on every server-generated document —
            // today that is the daily site report PDF, and nothing else.
            // It lives in settings rather than in config/app.php because a
            // company name is a business value an operator edits at 4pm
            // before a tender, not something that should need a deploy.
            'key' => 'reporting.company_name',
            'value' => 'HRMS',
            'type' => 'string',
            'group' => 'reporting',
            'label' => 'Company name',
            'description' => 'Company name printed in the heading of generated reports.',
        ],

        // --- system -----------------------------------------------------
        [
            'key' => 'system.date_format',
            'value' => 'd/m/Y',
            'type' => 'string',
            'group' => 'system',
            'label' => 'Display date format',
            'description' => 'PHP date format used when rendering dates to users.',
        ],
        [
            'key' => 'system.currency',
            'value' => 'INR',
            'type' => 'string',
            'group' => 'system',
            'label' => 'Currency',
            'description' => 'ISO currency code used across payroll and expenses.',
        ],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $setting) {
            // updateOrCreate (not create) so re-seeding never fails on the
            // unique key and never clobbers an operator's tuned value.
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                $setting + ['is_editable' => true],
            );
        }

        // Settings are read through a cached service — bust it after writing.
        app(SettingsService::class)->refresh();
    }
}
