<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Site;
use App\Services\Attendance\GeofenceResult;
use App\Services\Attendance\GeofenceService;
use App\Services\SettingsService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server's answer to "is this device at this site?".
 *
 * Every rejection carries a code and a sentence that tells the person
 * holding the phone what to do — a bare 422 for "you are 400 m away" or for
 * "your GPS does't know where you are" would be two very different problems
 * reported identically.
 */
class GeofenceTest extends TestCase
{
    use RefreshDatabase;

    private GeofenceService $geofence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geofence = app(GeofenceService::class);
    }

    private function site(?float $lat = 12.9716, ?float $lng = 77.5946, ?float $radius = 100): Site
    {
        return Site::factory()->create([
            'project_id' => Project::factory(),
            'latitude' => $lat,
            'longitude' => $lng,
            'geofence_radius' => $radius,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);
    }

    public function test_a_point_inside_the_radius_is_allowed_and_its_distance_is_measured(): void
    {
        // ~11.1 m north of the site centre, comfortably inside 100 m.
        $result = $this->geofence->evaluate($this->site(), 12.9717, 77.5946, 5.0);

        $this->assertTrue($result->allowed);
        $this->assertSame(GeofenceResult::CODE_OK, $result->code);
        $this->assertNotNull($result->distanceMetres);
        $this->assertLessThan(20, $result->distanceMetres);
        $this->assertSame(100, $result->metadata()['radius_metres']);
    }

    public function test_a_point_outside_the_radius_is_rejected_with_the_distance(): void
    {
        // ~111 m north — past a 100 m fence.
        $result = $this->geofence->evaluate($this->site(), 12.9726, 77.5946, 5.0);

        $this->assertFalse($result->allowed);
        $this->assertSame(GeofenceResult::CODE_OUTSIDE, $result->code);
        $this->assertGreaterThan(100, $result->distanceMetres);
        $this->assertStringContainsString('m from', $result->message);
    }

    public function test_the_fence_is_exactly_as_wide_as_the_site_says_not_as_wide_as_a_constant(): void
    {
        // The same 111 m step: outside a 100 m fence, inside a 200 m one.
        $tight = $this->geofence->evaluate($this->site(radius: 100), 12.9726, 77.5946, 5.0);
        $wide = $this->geofence->evaluate($this->site(radius: 200), 12.9726, 77.5946, 5.0);

        $this->assertFalse($tight->allowed);
        $this->assertTrue($wide->allowed);
    }

    public function test_the_zero_zero_fix_is_rejected_as_invalid_not_as_far_away(): void
    {
        $result = $this->geofence->evaluate($this->site(), 0.0, 0.0, 5.0);

        $this->assertFalse($result->allowed);
        $this->assertSame(GeofenceResult::CODE_INVALID_COORDINATES, $result->code);
    }

    public function test_an_out_of_range_coordinate_is_rejected_before_the_distance_is_computed(): void
    {
        $result = $this->geofence->evaluate($this->site(), 129.7, 77.5946, 5.0);

        $this->assertFalse($result->allowed);
        $this->assertSame(GeofenceResult::CODE_INVALID_COORDINATES, $result->code);
        $this->assertNull($result->distanceMetres);
    }

    public function test_a_fix_that_does_not_know_where_it_is_is_rejected_with_advice(): void
    {
        $result = $this->geofence->evaluate($this->site(), 12.9716, 77.5946, 400.0);

        $this->assertFalse($result->allowed);
        $this->assertSame(GeofenceResult::CODE_POOR_ACCURACY, $result->code);
        $this->assertStringContainsString('accuracy', $result->message);
        $this->assertSame(400.0, $result->metadata()['accuracy_metres']);
    }

    public function test_the_accuracy_ceiling_comes_from_config_and_is_not_a_literal_here(): void
    {
        $configured = (float) config('hrms.attendance.max_gps_accuracy_metres');

        $justInside = $this->geofence->evaluate($this->site(), 12.9716, 77.5946, $configured - 1);
        $justOutside = $this->geofence->evaluate($this->site(), 12.9716, 77.5946, $configured + 1);

        $this->assertTrue($justInside->allowed);
        $this->assertSame(GeofenceResult::CODE_POOR_ACCURACY, $justOutside->code);
    }

    public function test_an_absent_accuracy_reading_is_not_treated_as_a_bad_one(): void
    {
        // A device that reports no accuracy field still gets judged on its
        // position; inventing a number here would refuse honest check-ins.
        $result = $this->geofence->evaluate($this->site(), 12.9716, 77.5946, null);

        $this->assertTrue($result->allowed);
        $this->assertNull($result->accuracyMetres);
    }

    public function test_a_site_with_no_location_says_so_instead_of_measuring_nothing(): void
    {
        $result = $this->geofence->evaluate($this->site(lat: null, lng: null), 12.9716, 77.5946, 5.0);

        $this->assertFalse($result->allowed);
        $this->assertSame(GeofenceResult::CODE_SITE_NOT_CONFIGURED, $result->code);
        $this->assertFalse($result->hasSiteCoordinates);
        $this->assertNull($result->distanceMetres);
    }

    public function test_a_site_without_a_radius_falls_back_to_the_seeded_default(): void
    {
        $this->seed(SettingSeeder::class);

        $configured = (int) app(SettingsService::class)->int('attendance.default_geofence_radius_m', -1);
        $site = $this->site(radius: null);

        $result = $this->geofence->evaluate($site, 12.9717, 77.5946, 5.0);

        $this->assertTrue($result->allowed);
        $this->assertGreaterThan(0, $configured);
        $this->assertSame($configured, $result->metadata()['radius_metres']);
    }

    public function test_the_distance_formula_agrees_with_the_site_s_own_geofence_helper(): void
    {
        // Both GeofenceService and Site::withinGeofence() must reach the same
        // edge — two implementations that disagreed would let a check-in be
        // accepted by one and refused by the other.
        $site = $this->site(radius: 150);

        $this->assertTrue($site->withinGeofence(12.9717, 77.5946));
        $this->assertTrue($this->geofence->evaluate($site, 12.9717, 77.5946, 5.0)->allowed);

        $this->assertFalse($site->withinGeofence(12.9746, 77.5946));
        $this->assertFalse($this->geofence->evaluate($site, 12.9746, 77.5946, 5.0)->allowed);
    }
}
