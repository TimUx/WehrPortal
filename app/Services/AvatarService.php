<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class AvatarService
{
    private const SIZE = 256;

    /** Crop to a centred square, scale to 256 px, store as WebP */
    public function store(User $user, UploadedFile $file): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image) {
            throw ValidationException::withMessages(['photo' => 'Das Bild konnte nicht gelesen werden. Bitte JPG, PNG oder WebP verwenden.']);
        }

        $image = $this->applyExifOrientation($image, $file->getRealPath());

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled(
            $square, $image,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
            $side, $side,
        );

        ob_start();
        imagewebp($square, null, 82);
        $bytes = ob_get_clean();

        imagedestroy($image);
        imagedestroy($square);

        UserAvatar::updateOrCreate(
            ['user_id' => $user->id],
            ['mime' => 'image/webp', 'data' => base64_encode($bytes)],
        );
        $user->update(['avatar_updated_at' => now()]);
    }

    public function delete(User $user): void
    {
        $user->avatar()->delete();
        $user->update(['avatar_updated_at' => null]);
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
}
