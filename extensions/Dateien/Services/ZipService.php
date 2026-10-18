<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Version;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A folder as ZIP. Only entries the Kamerad may read are included: a subfolder without read
 * access is left out with everything below it (so its name never appears), a readable file
 * inside an unreadable folder too. Each file is streamed from disk in chunks (stored, not
 * compressed – most documents and photos are compressed already), so memory stays constant.
 * Without ZIP64 the archive is capped below 4 GiB; the configured limits are checked first.
 */
class ZipService
{
    public function __construct(private readonly FileAccess $access, private readonly FileService $files) {}

    public function download(User $user, Node $folder): StreamedResponse
    {
        $user = $this->access->member($user);
        $this->access->authorize($user, $folder, 'read');
        if ($folder->kind !== 'folder' || $folder->trashed()) {
            abort(404);
        }
        $entries = [];
        $total = 0;
        $this->collect($user, $folder, '', $entries, $total);
        $maxEntries = min(65000, (int) config('dateien.zip.max_entries', 2000));
        $maxBytes = min((int) config('dateien.zip.max_bytes', 2 * 1024 ** 3), 0xFFFFFFFF - 1024 * 1024 * 64);
        if (count($entries) > $maxEntries || $total > $maxBytes) {
            throw ValidationException::withMessages(['zip' => 'Der Ordner ist zu groß für einen ZIP-Download (höchstens '.$maxEntries.' Einträge und '
                .number_format($maxBytes / 1024 ** 2, 0, ',', '.').' MiB). Bitte Unterordner einzeln herunterladen.']);
        }
        AuditEvent::create(['actor_id' => $user->id, 'action' => 'folder.zipped', 'space_id' => $folder->space_id, 'node_id' => $folder->id,
            'metadata' => ['files' => count(array_filter($entries, fn ($e) => $e['path'] !== null)), 'bytes' => $total]]);
        $name = $folder->name.'.zip';
        $fallback = str_replace(['%', '/', '\\'], '_', preg_replace('/[^\x20-\x7e]/', '_', Str::ascii($name))) ?: 'ordner.zip';

        return new StreamedResponse(function () use ($entries) {
            $this->write($entries);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param  list<array{name: string, path: string|null, size: int, time: CarbonInterface}>  $entries */
    private function collect(User $user, Node $folder, string $prefix, array &$entries, int &$total, int $depth = 0): void
    {
        if ($depth > 100) {
            return;
        }
        $children = Node::where('parent_id', $folder->id)->where('space_id', $folder->space_id)->orderBy('kind')->orderBy('name')->get();
        if ($prefix !== '' && $children->isEmpty()) {
            $entries[] = ['name' => $prefix, 'path' => null, 'size' => 0, 'time' => $folder->updated_at ?? now()];
        }
        foreach ($children as $child) {
            if (! $this->access->can($user, $child, 'read')) {
                continue;
            }
            if ($child->kind === 'folder') {
                $this->collect($user, $child, $prefix.$child->name.'/', $entries, $total, $depth + 1);

                continue;
            }
            $version = Version::where('node_id', $child->id)->orderByDesc('number')->first();
            $path = $version ? $this->files->pathOf($version) : null;
            if ($version === null || $path === null) {
                continue;
            }
            $entries[] = ['name' => $prefix.pathinfo($child->name, PATHINFO_FILENAME).'.'.$version->extension, 'path' => $path, 'size' => $version->size, 'time' => $version->created_at ?? now()];
            $total += $version->size;
            if (count($entries) > (int) config('dateien.zip.max_entries', 2000)) {
                return;
            }
        }
    }

    /** @param  list<array{name: string, path: string|null, size: int, time: CarbonInterface}>  $entries */
    private function write(array $entries): void
    {
        $out = fopen('php://output', 'wb');
        $offset = 0;
        $central = '';
        foreach ($entries as $entry) {
            $isDir = $entry['path'] === null;
            $crc = $isDir ? 0 : (int) hexdec((string) hash_file('crc32b', $entry['path']));
            $size = $isDir ? 0 : $entry['size'];
            $time = $entry['time']->copy()->setTimezone('Europe/Berlin');
            $dosTime = ($time->hour << 11) | ($time->minute << 5) | intdiv($time->second, 2);
            $dosDate = ((max(1980, $time->year) - 1980) << 9) | ($time->month << 5) | $time->day;
            $name = $entry['name'];
            // Version 2.0, UTF-8 names (bit 11), stored
            $header = pack('vvvvvVVVvv', 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0);
            $local = "PK\x03\x04".$header.$name;
            fwrite($out, $local);
            if (! $isDir) {
                $in = fopen($entry['path'], 'rb');
                if ($in !== false) {
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                }
            }
            $central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 0x031E, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, $isDir ? (0o40755 << 16) | 0x10 : 0o100644 << 16, $offset).$name;
            $offset += strlen($local) + $size;
            flush();
        }
        fwrite($out, $central);
        fwrite($out, "PK\x05\x06".pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
        fclose($out);
    }
}
