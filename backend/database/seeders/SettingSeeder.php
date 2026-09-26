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
            'key' => 'leave.sick_certificate_deadline_days',
            'value' => '3',
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
