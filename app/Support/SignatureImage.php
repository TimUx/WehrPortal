<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A handwritten signature from the signature pad (`partials.signature-pad`): a PNG data URL with
 * dark strokes on a transparent background. Checked to be a real PNG that is not empty, so neither
 * a blank pad nor something else ends up as "signed". Used by the Verbandbuch and the Wartung.
 */
class SignatureImage
{
    /** Largest signature we take (PNG, base64-decoded) */
    public const MAX_BYTES = 300 * 1024;

    /** @return string the PNG as base64, ready to store */
    public static function fromDataUrl(string $dataUrl, string $field = 'signature'): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            $fail('Bitte unterschreiben.');
        }
        $png = base64_decode(substr($dataUrl, 22), true);
        if ($png === false || strlen($png) > self::MAX_BYTES) {
            $fail('Die Unterschrift konnte nicht gelesen werden. Bitte noch einmal unterschreiben.');
        }
        $size = @getimagesizefromstring($png);
        if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] < 50 || $size[1] < 20 || $size[0] > 3000 || $size[1] > 1500) {
            $fail('Die Unterschrift konnte nicht gelesen werden. Bitte noch einmal unterschreiben.');
        }
        if (self::inkPixels($png) < 30) {
            $fail('Die Unterschrift ist zu kurz. Bitte richtig unterschreiben.');
        }

        return base64_encode($png);
    }

    /** Visible pixels of the strokes, counted on every third pixel */
    private static function inkPixels(string $png): int
    {
        $image = @imagecreatefromstring($png);
        if (! $image) {
            return 0;
        }

        $count = 0;
        for ($x = 0, $w = imagesx($image), $h = imagesy($image); $x < $w; $x += 3) {
            for ($y = 0; $y < $h; $y += 3) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $dark = (($rgba >> 16) & 0xFF) + (($rgba >> 8) & 0xFF) + ($rgba & 0xFF) < 600;
                if ($alpha < 100 && $dark) {
                    $count++;
                }
            }
        }
        imagedestroy($image);

        return $count;
    }
}
