<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The `database` disk keeps uploads in a table, so they survive on hosts
 * whose disk is wiped between requests.
 */
class DatabaseStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_round_trip_through_the_database_disk(): void
    {
        config(['filesystems.disks.local' => ['driver' => 'database', 'throw' => false]]);
        Storage::forgetDisk('local');
        $disk = Storage::disk('local');

        $bytes = random_bytes(2048)."\x00\xFF binary";
        $disk->put('selfies/1/a.jpg', $bytes);

        $this->assertTrue($disk->exists('selfies/1/a.jpg'));
        $this->assertSame($bytes, $disk->get('selfies/1/a.jpg'));
        $this->assertSame(strlen($bytes), $disk->size('selfies/1/a.jpg'));
        $this->assertSame(['selfies/1/a.jpg'], $disk->allFiles('selfies'));

        $stored = UploadedFile::fake()->image('receipt.png', 20, 20)->store('receipts/9', 'local');
        $this->assertTrue($disk->exists($stored));

        $response = $disk->response('selfies/1/a.jpg');
        ob_start();
        $response->sendContent();
        $this->assertSame($bytes, ob_get_clean());

        $disk->delete('selfies/1/a.jpg');
        $this->assertFalse($disk->exists('selfies/1/a.jpg'));
        $this->assertNull($disk->get('selfies/1/a.jpg'));
    }
}
