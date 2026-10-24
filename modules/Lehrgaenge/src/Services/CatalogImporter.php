<?php

namespace Modules\Lehrgaenge\src\Services;

use App\Models\Setting;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Notifications\CatalogChangedNotification;
use RuntimeException;
use Throwable;

/**
 * Reads the public "Restplätze Lehrgänge" page of the own Kreis in fox112 daily; the address is set
 * by the Ausbildungsleitung (Lehrgangsliste). Only the Lehrgänge
 * "ohne festen Termin" count: they are what a Kamerad can announce Bedarf for. Dated Lehrgänge with
 * Restplätze are ignored. What isn't offered yet becomes a suggestion for the Ausbildungsleiter; what
 * leaves the page is flagged for a decision, never deleted.
 */
class CatalogImporter
{
    /** The page of every Kreis on fox112 has this path */
    public const PAGE = 'HomePageLehrgaengeRestplaetze.php';

    private const SETTING = 'lehrgaenge_fox112_url';

    private const LOCK = 'lehrgaenge-catalog-import';

    /** The Restplätze page of the own Kreis, or null while none is set */
    public static function url(): ?string
    {
        return Setting::get(self::SETTING) ?: null;
    }

    /**
     * Saves the address. Accepted: the full address, the Kreis address on fox112 ("https://kreis.fox112.de")
     * or only the Kreis part ("kreis"); only https addresses on fox112.de, so the server fetches nothing else.
     *
     * @return ?string the address as saved; null clears it
     */
    public static function saveUrl(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            Setting::set(self::SETTING, null);

            return null;
        }

        if (preg_match('/^[a-z0-9-]+$/i', $input)) {
            $input = 'https://'.strtolower($input).'.fox112.de/';
        }
        if (! str_contains($input, '://')) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || ! preg_match('/^[a-z0-9-]+\.fox112\.de$/', $host) || isset($parts['user']) || isset($parts['port'])) {
            throw ValidationException::withMessages(['url' => 'Bitte die fox112-Adresse des Kreises angeben, z. B. https://kreis.fox112.de.']);
        }

        $path = trim($parts['path'] ?? '', '/');
        $url = 'https://'.$host.'/'.($path === '' ? self::PAGE : $path);
        Setting::set(self::SETTING, $url);

        return $url;
    }

    /** @return array{at: ?string, message: ?string, ok: bool} */
    public static function lastRun(): array
    {
        return [
            'at' => Setting::get('lehrgaenge_import_at'),
            'message' => Setting::get('lehrgaenge_import_message'),
            'ok' => Setting::get('lehrgaenge_import_ok') === '1',
        ];
    }

    /**
     * @return array{listed: int, courses: int, gone: int}
     *
     * @throws RuntimeException when the page can't be loaded or read; nothing is changed then
     */
    public function import(): array
    {
        $lock = Cache::lock(self::LOCK, 300);

        if (! $lock->get()) {
            throw new RuntimeException('Der Import läuft gerade schon.');
        }

        try {
            $url = self::url() ?? throw new RuntimeException('Noch keine fox112-Adresse eingetragen (Lehrgänge → Lehrgangsliste).');
            $rows = $this->parseList($this->fetch($url));
            $undated = array_values(array_filter($rows, fn (array $row) => ! $row['dated']));

            if ($undated === []) {
                throw new RuntimeException('Auf der Seite wurden keine Lehrgänge ohne festen Termin gefunden – hat sich das Format geändert?');
            }

            $stats = DB::transaction(fn () => $this->store($undated));
            $this->remember(true, sprintf('%d Lehrgänge in fox112, %d neue Vorschläge, %d angebotene nicht mehr in fox112.', $stats['listed'], $stats['courses'], $stats['gone']));
            // The Ausbildungsleiter decide: new suggestions and Lehrgänge that left fox112
            if ($stats['courses'] + $stats['gone'] > 0) {
                foreach (app(RegistrationService::class)->managers() as $manager) {
                    rescue(fn () => $manager->notify(new CatalogChangedNotification($stats['courses'], $stats['gone'])));
                }
            }

            return $stats;
        } catch (Throwable $e) {
            $this->remember(false, $e->getMessage());

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Rows of the Restplätze table: the course name (bold part), the full title with variant, and
     * whether the row has a fixed date.
     *
     * @return list<array{external_id: int, course_name: string, title: string, dated: bool}>
     */
    public function parseList(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $rows = [];

        foreach ($xpath->query('//tr') as $tr) {
            $cells = collect($tr->childNodes)->filter(fn ($n) => $n instanceof DOMElement && strtolower($n->nodeName) === 'td')->values();
            $link = $xpath->query('.//a[contains(@href, "lehrgangx=")]', $tr)->item(0);
            $bold = $cells->count() > 1 ? $cells[1]->getElementsByTagName('b')->item(0) : null;

            if (! $link || ! $bold || ($courseName = $this->text($bold)) === '') {
                continue;
            }

            preg_match('/lehrgangx=(\d+)/', $link->getAttribute('href'), $m);
            $rows[] = [
                'external_id' => (int) ($m[1] ?? 0),
                'course_name' => $courseName,
                'title' => $this->text($cells[1]),
                // Rows without a date have one wide cell "ohne festen Termin"
                'dated' => $cells->count() >= 12 && $cells[3]->getAttribute('colspan') === '',
            ];
        }

        return $rows;
    }

    /**
     * fox112 only suggests: a Lehrgang that isn't offered yet (new, or taken off the list) becomes a suggestion
     * for the Ausbildungsleiter, unless it was ignored. One that left fox112 is flagged, never deleted – fox112
     * often drops a Lehrgang for a while.
     *
     * @param  list<array>  $rows
     * @return array{listed: int, courses: int, gone: int}
     */
    private function store(array $rows): array
    {
        $stats = ['listed' => 0, 'courses' => 0, 'gone' => 0];
        $now = now();
        $seen = [];

        foreach (collect($rows)->groupBy('course_name') as $name => $variants) {
            $course = Course::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? new Course(['name' => mb_substr($name, 0, 150), 'is_standard' => false]);
            $course->fill(['titles' => $variants->pluck('title')->unique()->values()->all(), 'listed' => true, 'last_seen_at' => $now, 'gone_since' => null]);
            if ((! $course->is_standard || $course->removed_at) && ! $course->ignored_at && ! $course->suggested_at) {
                $course->suggested_at = $now;
                $stats['courses']++;
            }
            $course->save();
            $seen[] = $course->id;
            $stats['listed']++;
        }

        // Left fox112: flagged for a decision if it is offered, kept in any case
        $gone = Course::where('listed', true)->whereNotIn('id', $seen);
        $stats['gone'] = (clone $gone)->where('is_standard', true)->whereNull('removed_at')->count();
        (clone $gone)->where('is_standard', true)->whereNull('removed_at')->update(['gone_since' => $now]);
        $gone->update(['listed' => false, 'suggested_at' => null]);

        return $stats;
    }

    private function fetch(string $url): string
    {
        $response = Http::timeout(20)->withUserAgent('WehrPortal (Lehrgangskatalog)')->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Die Seite '.$url.' antwortet mit HTTP '.$response->status().'.');
        }

        // fox112 delivers ISO-8859-1
        $body = $response->body();

        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
    }

    private function remember(bool $ok, string $message): void
    {
        Setting::set('lehrgaenge_import_at', now()->toIso8601String());
        Setting::set('lehrgaenge_import_ok', $ok ? '1' : '0');
        Setting::set('lehrgaenge_import_message', mb_substr($message, 0, 500));
    }

    private function text(DOMElement $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $node->textContent)));
    }
}
