<?php

namespace Modules\Einsatzbericht\src\Services;

use App\Models\Setting;
use App\Services\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Models\Report;
use Throwable;

/**
 * DIVERA 24/7 calls this at every alarm. Each request is stored raw; then a draft report is made
 * from it (or the request is attached to the draft of the same alarm, or filtered out). The secret
 * key in the address is the only protection, DIVERA does not sign requests.
 */
class DiveraWebhook
{
    /** Report field → paths in the request, comma-separated alternatives, dots for nesting */
    public const DEFAULT_MAPPING = [
        'external_id' => 'id,alarm_id,foreign_id',
        'keyword' => 'title,keyword,stichwort',
        'message' => 'text,message,meldung',
        'address' => 'address,adresse',
        'latitude' => 'lat,latitude',
        'longitude' => 'lng,lon,longitude',
        'test' => '',
    ];

    public const MAPPING_LABELS = [
        'external_id' => 'Alarm-Kennung (gegen doppelte Entwürfe)',
        'keyword' => 'Einsatzstichwort',
        'message' => 'Meldung',
        'address' => 'Adresse',
        'latitude' => 'Breitengrad',
        'longitude' => 'Längengrad',
        'test' => 'Kennzeichen Probealarm (ja = kein Einsatz)',
    ];

    public const DEFAULT_FILTERS = "Probealarm\nTestalarm";

    /** Without an alarm id, the same keyword and address within this time count as the same Einsatz */
    public const SAME_ALARM_MINUTES = 120;

    public function __construct(private readonly ReportService $reports, private readonly Geocoder $geocoder) {}

    // ─── settings ────────────────────────────────────────────────────────────

    public static function enabled(): bool
    {
        return Setting::get('einsatzbericht_divera_enabled') === '1' && self::key() !== null;
    }

    public static function setEnabled(bool $enabled): void
    {
        if ($enabled && self::key() === null) {
            self::regenerateKey();
        }

        Setting::set('einsatzbericht_divera_enabled', $enabled ? '1' : '0');
    }

    /** The secret part of the address, stored encrypted */
    public static function key(): ?string
    {
        $stored = Setting::get('einsatzbericht_divera_key');

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function regenerateKey(): string
    {
        $key = Str::random(48);
        Setting::set('einsatzbericht_divera_key', Crypt::encryptString($key));

        return $key;
    }

    public static function url(): ?string
    {
        return self::key() ? route('einsatzbericht.divera', ['key' => self::key()]) : null;
    }

    /** @return array<string, string> */
    public static function mapping(): array
    {
        $saved = (array) json_decode((string) Setting::get('einsatzbericht_divera_mapping'), true);

        return array_merge(self::DEFAULT_MAPPING, array_intersect_key($saved, self::DEFAULT_MAPPING));
    }

    public static function setMapping(array $mapping): void
    {
        Setting::set('einsatzbericht_divera_mapping', json_encode(array_map(fn ($v) => trim((string) $v), array_intersect_key($mapping, self::DEFAULT_MAPPING))));
    }

    /** @return list<string> keywords that make no draft (case-insensitive, part of keyword or message) */
    public static function filters(): array
    {
        return collect(preg_split('/\R/', (string) (Setting::get('einsatzbericht_divera_filters') ?? self::DEFAULT_FILTERS)))
            ->map(fn ($f) => trim($f))->filter()->values()->all();
    }

    public static function setFilters(string $filters): void
    {
        Setting::set('einsatzbericht_divera_filters', trim($filters));
    }

    // ─── requests ────────────────────────────────────────────────────────────

    /** Stores the request unchanged; processing comes afterwards */
    public function receive(Request $request): DiveraRequest
    {
        return DiveraRequest::create([
            'received_at' => now(),
            'method' => $request->method(),
            'content_type' => mb_substr((string) $request->header('Content-Type'), 0, 150) ?: null,
            'ip' => $request->ip(),
            'body' => match (true) {
                $request->isMethod('GET') => $request->getQueryString(),
                // PHP does not keep the raw body of form posts (never for multipart); store the fields as query string
                $request->getContent() === '' && $request->request->count() > 0 => http_build_query($request->request->all()),
                default => mb_substr($request->getContent(), 0, 1_000_000),
            },
            'status' => self::enabled() ? 'pending' : 'disabled',
        ]);
    }

    /**
     * A test alarm from the app, as DIVERA would send it: the fields go under the names the mapping
     * expects first. Either straight through the same processing, or as a real request to the public
     * address (checks proxy and key, too).
     *
     * @param  array{keyword: string, message: ?string, address: ?string, latitude: ?string, longitude: ?string, test: bool}  $fields
     */
    public function testAlarm(array $fields, bool $viaUrl = false): DiveraRequest
    {
        if (! self::enabled()) {
            throw new \RuntimeException('Der Webhook ist ausgeschaltet. Erst einschalten und speichern.');
        }

        $payload = [];
        $values = [
            'external_id' => 'test-'.now()->format('YmdHis').'-'.Str::lower(Str::random(8)),
            'keyword' => $fields['keyword'],
            'message' => $fields['message'] ?? null,
            'address' => $fields['address'] ?? null,
            'latitude' => $fields['latitude'] ?? null,
            'longitude' => $fields['longitude'] ?? null,
            'test' => ($fields['test'] ?? false) ? true : null,
        ];
        foreach ($values as $field => $value) {
            $path = trim(explode(',', self::mapping()[$field])[0] ?? '');
            if ($value !== null && $value !== '' && $path !== '') {
                Arr::set($payload, $path, $value);
            }
        }

        if ($viaUrl) {
            $before = (int) DiveraRequest::max('id');
            $response = Http::timeout(15)->asJson()->post(self::url(), $payload);
            if (! $response->successful()) {
                throw new \RuntimeException('Die Adresse antwortet mit HTTP '.$response->status().'.');
            }

            return DiveraRequest::where('id', '>', $before)->latest('id')->firstOrFail();
        }

        $entry = DiveraRequest::create([
            'received_at' => now(), 'method' => 'POST', 'content_type' => 'application/json (Test-Alarm aus der App)',
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'status' => 'pending',
        ]);
        $this->process($entry);

        return $entry->refresh();
    }

    /** Turns a stored request into a draft, attaches it, or filters it out; never throws */
    public function process(DiveraRequest $entry): void
    {
        if ($entry->status !== 'pending') {
            return;
        }

        try {
            $data = self::parse($entry);
            $fields = $this->extract($data);

            if ($reason = $this->filtered($fields)) {
                $entry->update(['status' => 'filtered', 'message' => $reason]);

                return;
            }

            if ($existing = $this->sameAlarm($fields)) {
                $entry->update(['status' => 'attached', 'report_id' => $existing->id, 'message' => 'Nachalarmierung zu '.$existing->number]);

                return;
            }

            $report = $this->reports->createFromAlarm([
                'keyword' => $fields['keyword'],
                'alarm_message' => $fields['message'],
                'address' => $fields['address'],
                'latitude' => $fields['latitude'],
                'longitude' => $fields['longitude'],
                'divera_id' => $fields['external_id'],
            ], $entry->received_at);

            $entry->update(['status' => 'created', 'report_id' => $report->id, 'message' => 'Einsatz '.$report->number]);

            if (! $report->latitude && $report->address && ($point = $this->geocoder->locate($report->address))) {
                $report->update(['latitude' => $point['lat'], 'longitude' => $point['lng']]);
            }
        } catch (Throwable $e) {
            report($e);
            $entry->update(['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** JSON, XML, form data, plain text or a GET query string – whatever DIVERA is set to send */
    public static function parse(DiveraRequest $entry): array
    {
        $body = (string) $entry->body;
        $type = strtolower((string) $entry->content_type);

        if ($entry->method === 'GET' || str_contains($type, 'form')) {
            parse_str($body, $data);

            return $data;
        }

        if (str_contains($type, 'json') || str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $data;
            }
        }

        if (str_contains($type, 'xml') || str_starts_with(ltrim($body), '<')) {
            $xml = @simplexml_load_string($body, options: LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml !== false) {
                return json_decode(json_encode($xml), true) ?: [];
            }
        }

        return ['text' => trim($body)];
    }

    /** @return array{external_id: ?string, keyword: ?string, message: ?string, address: ?string, latitude: ?float, longitude: ?float, test: bool} */
    public function extract(array $data): array
    {
        // DIVERA's full object nests the alarm under "data"; look there too
        $sources = array_filter([$data, is_array($data['data'] ?? null) ? $data['data'] : null]);
        $value = function (string $field) use ($sources) {
            foreach (explode(',', self::mapping()[$field]) as $path) {
                $path = trim($path);
                foreach ($sources as $source) {
                    $found = $path !== '' ? Arr::get($source, $path) : null;
                    if (is_scalar($found) && trim((string) $found) !== '') {
                        return trim((string) $found);
                    }
                }
            }

            return null;
        };
        $coordinate = fn (?string $v) => $v !== null && is_numeric(str_replace(',', '.', $v)) ? (float) str_replace(',', '.', $v) : null;

        return [
            'external_id' => ($id = $value('external_id')) !== null ? mb_substr($id, 0, 100) : null,
            'keyword' => ($keyword = $value('keyword')) !== null ? mb_substr($keyword, 0, 150) : null,
            'message' => $value('message'),
            'address' => ($address = $value('address')) !== null ? mb_substr($address, 0, 255) : null,
            'latitude' => $coordinate($value('latitude')),
            'longitude' => $coordinate($value('longitude')),
            'test' => in_array(mb_strtolower((string) $value('test')), ['1', 'true', 'ja', 'yes'], true),
        ];
    }

    private function filtered(array $fields): ?string
    {
        if ($fields['test']) {
            return 'Probealarm (Kennzeichen)';
        }

        $text = mb_strtolower(($fields['keyword'] ?? '').' '.($fields['message'] ?? ''));
        foreach (self::filters() as $filter) {
            if (str_contains($text, mb_strtolower($filter))) {
                return 'Filter „'.$filter.'“';
            }
        }

        return null;
    }

    /** The same alarm: same DIVERA id, or without id the same keyword and address shortly before */
    private function sameAlarm(array $fields): ?Report
    {
        if ($fields['external_id'] !== null) {
            return Report::where('source', 'divera')->where('divera_id', $fields['external_id'])->first();
        }

        return Report::where('source', 'divera')->whereNull('divera_id')
            ->where('created_at', '>=', now()->subMinutes(self::SAME_ALARM_MINUTES))
            ->where('keyword', $fields['keyword'])->where('address', $fields['address'])
            ->latest('id')->first();
    }
}
