<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_yes_no_capabilities_without_signing_in(): void
    {
        Storage::fake(config('hrms.storage.private_disk', 'local'));

        $this->getJson('/api/v1/health/capabilities')
            ->assertOk()
            ->assertJsonPath('data.database', true)
            ->assertJsonPath('data.file_storage', true)
            ->assertJsonStructure(['data' => ['image_processing', 'pdf_dom', 'mbstring', 'fileinfo', 'temp_writable']]);
    }
}
