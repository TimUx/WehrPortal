<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandingImage
{
    public const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Re-encoding retains pixels, not metadata or appended executable content. */
    public static function encode(UploadedFile $file, string $field): string
    {
        Validator::make(Arr::undot([$field => $file]), [
            $field => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ], [], [$field => 'Bild'])->validate();

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages([$field => 'Das Bild konnte nicht gelesen werden. Bitte PNG, JPEG oder WebP verwenden.']);
        }

        imagesavealpha($image, true);
        ob_start();
        $encoded = imagewebp($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! $encoded || ! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages([$field => 'Das Bild konnte nicht verarbeitet werden.']);
        }

        return base64_encode($bytes);
    }
}
