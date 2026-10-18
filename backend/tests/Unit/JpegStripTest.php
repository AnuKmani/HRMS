<?php

namespace Tests\Unit;

use App\Services\Attendance\SelfieSanitizer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The GD-free path: a JPEG rebuilt from its image segments alone, for
 * PHP builds (serverless hosts) that ship without the GD extension.
 */
class JpegStripTest extends TestCase
{
    private function strip(string $raw): ?string
    {
        $method = new ReflectionMethod(SelfieSanitizer::class, 'stripJpeg');

        return $method->invoke(new SelfieSanitizer, $raw);
    }

    private function jpeg(bool $progressive = false): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 160, 240, imagecolorallocate($image, 200, 30, 30));
        imageinterlace($image, $progressive);
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /** Insert APP1 (EXIF-like, with a GPS marker), a COM, and a trailing payload. */
    private function withMetadata(string $jpeg): string
    {
        $exif = "Exif\0\0GPS-SECRET-25.2048,55.2708 iPhone 15";
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $comment = 'COMMENT-SECRET';
        $com = "\xFF\xFE".pack('n', strlen($comment) + 2).$comment;

        return substr($jpeg, 0, 2).$app1.$com.substr($jpeg, 2).'<?php echo "TRAILING-SECRET"; ?>';
    }

    public function test_metadata_and_trailing_bytes_are_removed_and_the_photo_still_decodes(): void
    {
        foreach ([false, true] as $progressive) {
            $dirty = $this->withMetadata($this->jpeg($progressive));
            $clean = $this->strip($dirty);

            $this->assertNotNull($clean);
            $this->assertStringNotContainsString('GPS-SECRET', $clean);
            $this->assertStringNotContainsString('COMMENT-SECRET', $clean);
            $this->assertStringNotContainsString('TRAILING-SECRET', $clean);
            $this->assertStringEndsWith("\xFF\xD9", $clean);

            $decoded = imagecreatefromstring($clean);
            $this->assertNotFalse($decoded, $progressive ? 'progressive' : 'baseline');
            $this->assertSame([320, 240], [imagesx($decoded), imagesy($decoded)]);
            // Same pixels as before cleaning.
            $this->assertSame(
                imagecolorat(imagecreatefromstring($dirty), 40, 100),
                imagecolorat($decoded, 40, 100),
            );
        }
    }

    public function test_anything_that_is_not_a_well_formed_jpeg_is_refused(): void
    {
        $this->assertNull($this->strip('<?php echo 1;'));
        $this->assertNull($this->strip("\xFF\xD8\xFF\xE1\x00\x10short"));
        $this->assertNull($this->strip(substr($this->jpeg(), 0, 200)));
        $this->assertNull($this->strip("\xFF\xD8\xFF\xD9")); // no frame at all
    }
}
