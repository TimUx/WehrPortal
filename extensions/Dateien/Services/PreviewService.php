<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Version;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Small previews of images (JPEG, PNG, GIF, WebP), made with GD on the server and kept in the
 * database per version (x_files_previews, deleted with the version). Never public: the route
 * checks read access every time. Images above the pixel limit get no preview, so a small file
 * with huge dimensions can't exhaust the memory. PDFs and office files get none (no extra parser).
 */
class PreviewService
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(private readonly FileAccess $access, private readonly FileService $files) {}

    public static function supports(?Version $version): bool
    {
        return $version !== null && in_array($version->extension, self::EXTENSIONS, true) && function_exists('imagecreatefromstring');
    }

    /** @return array{data: string, mime: string}|null */
    public function for(User $user, Node $node): ?array
    {
        $user = $this->access->member($user);
        $this->access->authorize($user, $node, 'read');
        if ($node->kind !== 'file' || $node->trashed()) {
            return null;
        }
        $version = Version::where('node_id', $node->id)->orderByDesc('number')->first();
        if (! self::supports($version)) {
            return null;
        }
        $cached = DB::table('x_files_previews')->where('version_id', $version->id)->first();
        if ($cached) {
            return $cached->data === '' ? null : ['data' => (string) base64_decode($cached->data, true), 'mime' => $cached->mime];
        }
        $preview = $this->make($version);
        DB::table('x_files_previews')->insertOrIgnore([
            'version_id' => $version->id, 'data' => $preview ? base64_encode($preview['data']) : '',
            'mime' => $preview['mime'] ?? '', 'created_at' => now(),
        ]);

        return $preview;
    }

    /** @return array{data: string, mime: string}|null */
    private function make(Version $version): ?array
    {
        $path = $this->files->pathOf($version);
        if ($path === null || $version->size > (int) config('dateien.previews.max_bytes', 20 * 1024 * 1024)) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1
            || (int) config('dateien.previews.max_pixels', 16_000_000) < $info[0] * $info[1]
            || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return null;
        }
        try {
            $source = @imagecreatefromstring((string) file_get_contents($path));
            if ($source === false) {
                return null;
            }
            $max = max(32, (int) config('dateien.previews.size', 480));
            $scale = min(1, $max / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $scale));
            $height = max(1, (int) round($info[1] * $scale));
            $target = imagecreatetruecolor($width, $height);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            imagedestroy($source);
            $out = fopen('php://memory', 'w+b');
            $webp = (imagetypes() & IMG_WEBP) !== 0;
            $webp ? imagewebp($target, $out, 80) : imagepng($target, $out, 6);
            imagedestroy($target);
            rewind($out);
            $data = (string) stream_get_contents($out);
            fclose($out);

            return $data === '' ? null : ['data' => $data, 'mime' => $webp ? 'image/webp' : 'image/png'];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
