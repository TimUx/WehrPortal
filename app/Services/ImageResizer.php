<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Photos from phones: turned upright by the EXIF hint, scaled to a maximum side and stored as
 * WebP. Used by the Mängel photos and the Lagemeldungen of the Einsatzbericht.
 */
class ImageResizer
{
    /** @return string the WebP bytes */
    public function toWebp(string $path, int $maxSide, string $field = 'photos'): string
    {
        // A 12-megapixel phone photo takes about 50 MB as GD image, more while scaling
        self::ensureMemory('512M');

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image) {
            throw ValidationException::withMessages([$field => 'Ein Bild konnte nicht gelesen werden. Bitte JPG, PNG oder WebP verwenden.']);
        }

        $image = $this->applyExifOrientation($image, $path);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height));

        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
        }

        ob_start();
        imagewebp($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Phone photos are often stored sideways with an EXIF rotation hint */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? 1;

        return match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /** Raises the memory limit for image work, never lowers it */
    private static function ensureMemory(string $limit): void
    {
        $bytes = fn (string $value) => $value === '-1' ? PHP_INT_MAX : (int) $value * match (strtoupper(substr($value, -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };

        if ($bytes((string) ini_get('memory_limit')) < $bytes($limit)) {
            ini_set('memory_limit', $limit);
        }
    }
}
