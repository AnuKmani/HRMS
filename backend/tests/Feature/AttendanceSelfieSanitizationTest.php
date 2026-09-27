<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a selfie is allowed to be, and who is allowed to look at it.
 *
 * Two halves, because they are two different promises:
 *
 *  - **Sanitisation** (§sanitise) is about the bytes. An image is accepted
 *    only if it decodes, is within the pixel budget and weighs no more than
 *    the ceiling; what lands on disk is `SelfieSanitizer`'s JPEG, never the
 *    upload — so the EXIF block, and with it any GPS fix or camera
 *    identification, does not come along, and neither does the filename the
 *    client chose. This has to hold for a hand-crafted client that has
 *    never heard of Flutter, which is exactly the client the app-side
 *    stripping cannot speak for.
 *
 *  - **Access** (§access) is about the file afterwards. It is written to a
 *    disk with no public URL, named by the server, reachable only through
 *    one authenticated, policy-checked route, and never described by a
 *    path in a response.
 *
 * Everything runs against `hrms_testing`, and `Storage::fake('local')` so
 * that not one byte of a fixture photograph is written into the real
 * private storage.
 */
class AttendanceSelfieSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Site $site;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 09:05:00'));

        // Nothing below may leave a photograph in the real storage.
        Storage::fake('local');

        $this->project = Project::factory()->create();

        $this->site = Site::factory()->create([
            'project_id' => $this->project->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        $this->employee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');
        $this->employee->update(['user_id' => $this->user->id]);
    }

    /* ---------------------------------------------------------- fixtures */

    /**
     * A check-in as the posted employee. Overrides replace the defaults, so
     * `'selfie' => ...` swaps only the photograph.
     *
     * @param  array<string, mixed>  $extra
     */
    private function checkIn(array $extra = []): TestResponse
    {
        return $this->post('/api/v1/attendance/check-in', $extra + [
            'site_id' => $this->site->id,
            'latitude' => 12.9717,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 240, 240),
        ]);
    }

    /**
     * The bytes of a plain, metadata-free JPEG — a starting point to attach
     * metadata to, since PHP can write pixels but not an EXIF block.
     */
    private function jpegBytes(int $width = 64, int $height = 64): string
    {
        $source = UploadedFile::fake()->image('selfie.jpg', $width, $height);

        return (string) file_get_contents($source->getRealPath());
    }

    /**
     * Splice a well-formed APP1 (EXIF) segment immediately after SOI, seeded
     * with strings that would be conspicuous in any photograph: a GPS fix, a
     * make, a model. The TIFF directory inside is deliberately not valid —
     * the assertion is that metadata does not survive re-encoding, not that
     * a particular parser can read it.
     */
    private function withExif(string $jpeg): string
    {
        $payload = 'Exif'."\0\0"
            .'GPS:28.6138,77.2090;Make=TestCamera;Model=PixelTest;HRMS_EXIF_MARKER';

        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * @return array{path: string, bytes: string}
     */
    private function storedSelfie(): array
    {
        $files = Storage::disk('local')->allFiles();

        $this->assertCount(1, $files, 'Expected exactly one file on the private disk.');

        return [
            'path' => $files[0],
            'bytes' => (string) Storage::disk('local')->get($files[0]),
        ];
    }

    /* --------------------------------------------------------- sanitise */

    public function test_a_valid_jpeg_is_accepted_and_stored_under_a_server_minted_name(): void
    {
        Sanctum::actingAs($this->user);

        $this->checkIn()->assertCreated();

        $attendance = Attendance::query()->sole();
        $path = (string) $attendance->check_in_selfie_path;

        $this->assertMatchesRegularExpression(
            '#^attendance-selfies/'.$this->employee->id.'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#',
            $path,
        );

        $stored = $this->storedSelfie();

        $this->assertSame($path, $stored['path']);
        $this->assertStringStartsWith("\xFF\xD8", $stored['bytes'], 'The stored file is not a JPEG.');
        $this->assertStringEndsWith("\xFF\xD9", $stored['bytes'], 'The stored JPEG is truncated.');
    }

    public function test_the_client_chosen_filename_never_reaches_the_disk(): void
    {
        Sanctum::actingAs($this->user);

        $upload = UploadedFile::fake()->createWithContent(
            'my holiday selfie.jpg',
            $this->jpegBytes(120, 120),
        );

        $this->checkIn(['selfie' => $upload])->assertCreated();

        $stored = $this->storedSelfie();

        $this->assertStringNotContainsString('holiday', $stored['path']);
        $this->assertStringNotContainsString(' ', $stored['path']);
        $this->assertStringEndsWith('.jpg', $stored['path']);
    }

    public function test_a_png_is_accepted_and_re_encoded_as_a_jpeg(): void
    {
        Sanctum::actingAs($this->user);

        $this->checkIn([
            'selfie' => UploadedFile::fake()->image('selfie.png', 240, 240),
        ])->assertCreated();

        $stored = $this->storedSelfie();

        // The PNG was an acceptable *input*; it is not an acceptable thing
        // to store. One format on disk, whatever arrived.
        $this->assertStringEndsWith('.jpg', $stored['path']);
        $this->assertStringStartsWith("\xFF\xD8", $stored['bytes'], 'The PNG was stored as a PNG.');
        $this->assertStringNotContainsString("\x89PNG", $stored['bytes']);

        $attendance = Attendance::query()->sole();
        $this->assertStringEndsWith('.jpg', (string) $attendance->check_in_selfie_path);
    }

    public function test_a_file_that_is_not_an_image_is_refused_before_anything_is_written(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->checkIn([
            'selfie' => UploadedFile::fake()->createWithContent(
                'selfie.jpg',
                'this is not a photograph',
            ),
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ['That file is not a readable image.'],
            $response->json('errors.selfie'),
        );

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_non_image_renamed_to_an_image_extension_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        // A PDF, and the header it is entitled to keep. The extension and
        // the declared type both say "image"; the bytes do not.
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n%%EOF\n";

        $response = $this->checkIn([
            'selfie' => UploadedFile::fake()->createWithContent('holiday.png', $pdf),
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ['That file is not a readable image.'],
            $response->json('errors.selfie'),
        );

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_non_image_renamed_to_an_image_extension_is_refused_by_the_mime_sniff_too(): void
    {
        // The same payload, this time as a real upload rather than a fake
        // one: Symfony guesses the type from the bytes rather than from the
        // name, which is what production actually does. Two independent
        // answers, both "no".
        $path = tempnam(sys_get_temp_dir(), 'hrms-selfie-');
        file_put_contents($path, 'MZ'.str_repeat("\x00", 64).'not an image at all');

        $upload = new UploadedFile($path, 'camera.jpg', null, null, true);

        Sanctum::actingAs($this->user);

        try {
            $response = $this->checkIn(['selfie' => $upload]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(422);
        $this->assertArrayHasKey('selfie', $response->json('errors'));

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_oversized_image_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $tooBig = (int) config('hrms.storage.selfie_max_kilobytes') + 100;

        $response = $this->checkIn([
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 300, 300)->size($tooBig),
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ['That selfie is too large. Retake it — the app compresses before sending.'],
            $response->json('errors.selfie'),
        );

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_image_larger_than_the_pixel_budget_is_refused(): void
    {
        // 240 x 240 is a perfectly ordinary selfie and a perfectly ordinary
        // bomb: the budget, not the byte count, is what decides how much
        // buffer one request may ask for.
        config(['hrms.storage.selfie_max_pixels' => 1000]);

        Sanctum::actingAs($this->user);

        $response = $this->checkIn();

        $response->assertStatus(422);
        $this->assertSame(
            ['That image is too large to process. Take a smaller one.'],
            $response->json('errors.selfie'),
        );

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_exif_and_camera_metadata_do_not_survive_sanitisation(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'selfie.jpg',
            $this->withExif($this->jpegBytes(160, 120)),
        );

        $this->assertStringContainsString('HRMS_EXIF_MARKER', (string) file_get_contents($upload->getRealPath()));

        Sanctum::actingAs($this->user);

        $this->checkIn(['selfie' => $upload])->assertCreated();

        $stored = $this->storedSelfie();

        $this->assertStringStartsWith("\xFF\xD8", $stored['bytes']);
        $this->assertStringNotContainsString('HRMS_EXIF_MARKER', $stored['bytes']);
        $this->assertStringNotContainsString('28.6138,77.2090', $stored['bytes']);
        $this->assertStringNotContainsString('TestCamera', $stored['bytes']);
        $this->assertStringNotContainsString('PixelTest', $stored['bytes']);
        $this->assertStringNotContainsString('Exif', $stored['bytes']);
    }

    public function test_only_the_sanitised_image_is_stored_never_the_original_upload(): void
    {
        $original = $this->withExif($this->jpegBytes(160, 120));

        Sanctum::actingAs($this->user);

        $this->checkIn([
            'selfie' => UploadedFile::fake()->createWithContent('selfie.jpg', $original),
        ])->assertCreated();

        $stored = $this->storedSelfie();

        $this->assertNotSame($original, $stored['bytes'], 'The upload was stored verbatim.');
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    /* ------------------------------------------------------------ access */

    public function test_a_stored_selfie_is_reachable_only_through_the_policy_checked_route(): void
    {
        Sanctum::actingAs($this->user);

        $this->checkIn()->assertCreated();

        $attendance = Attendance::query()->sole();
        $path = (string) $attendance->check_in_selfie_path;

        // One file, in the employee's own directory, under a name the client
        // had no hand in.
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression(
            '#^attendance-selfies/'.$this->employee->id.'/[0-9a-f-]{36}\.jpg$#',
            $files[0],
        );

        // The row itself describes the photograph without describing where
        // it is: no path, ever, in any response.
        $detail = $this->getJson('/api/v1/attendance/'.$attendance->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($detail['has_selfie']);
        $this->assertStringNotContainsString('check_in_selfie_path', json_encode($detail));
        $this->assertStringNotContainsString('attendance-selfies', json_encode($detail));
        $this->assertStringNotContainsString($path, json_encode($detail));

        // And the one route that does serve it says so in its headers.
        $response = $this->get('/api/v1/attendance/'.$attendance->id.'/selfie');

        $response->assertOk();
        $this->assertStringStartsWith('image/jpeg', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));
    }

    public function test_an_unauthenticated_request_cannot_fetch_a_selfie(): void
    {
        $attendance = Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:00:00',
            'check_out_at' => null,
            'check_in_selfie_path' => 'attendance-selfies/'.$this->employee->id.'/somebody.jpg',
            'status' => Attendance::STATUS_PRESENT,
        ]);

        Storage::disk('local')->put(
            $attendance->check_in_selfie_path,
            $this->jpegBytes(),
        );

        $this->getJson('/api/v1/attendance/'.$attendance->id.'/selfie')->assertUnauthorized();
        $this->get('/api/v1/attendance/'.$attendance->id.'/selfie')->assertUnauthorized();
    }

    public function test_another_employee_cannot_fetch_this_selfie(): void
    {
        Sanctum::actingAs($this->user);
        $this->checkIn()->assertCreated();

        $attendance = Attendance::query()->sole();

        $colleague = User::factory()->create();
        $colleague->assignRole('Employee');
        Sanctum::actingAs($colleague);

        $this->getJson('/api/v1/attendance/'.$attendance->id.'/selfie')->assertForbidden();

        // Their own attendance list still does not name this record, so
        // there is no id to guess at either.
        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');
        $this->assertNotContains($attendance->id, $ids);
    }
}
