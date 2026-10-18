<?php

namespace Extensions\Dateien\Office;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads /hosting/discovery of the WOPI server (Collabora): action URLs per extension and the proof
 * keys. Fetched only from the configured internal URL, without redirects, cached for an hour.
 */
class WopiDiscovery
{
    public const CACHE = 'dateien.office.discovery';

    public function __construct(private readonly OfficeSettings $settings) {}

    /** @return array{actions: array<string, array<string, string>>, proof: ?array{modulus: string, exponent: string}, old: ?array{modulus: string, exponent: string}} */
    public function get(bool $fresh = false): array
    {
        $base = $this->settings->internalUrl();
        if ($base === null) {
            throw new RuntimeException('Kein Office-Server konfiguriert.');
        }
        $key = self::CACHE.'.'.sha1($base);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, fn () => self::parse($this->fetch($base.'/hosting/discovery')));
    }

    /** The action URL (view or edit preferred) for an extension, with its origin replaced by the browser-facing server */
    public function actionUrl(string $extension, bool $edit = false): ?string
    {
        $actions = $this->get()['actions'][strtolower($extension)] ?? [];
        $url = $edit
            ? ($actions['edit'] ?? null)
            : ($actions['view'] ?? $actions['edit'] ?? $actions['view_comment'] ?? null);
        $origin = $this->settings->serverOrigin();
        if ($url === null || $origin === null) {
            return null;
        }
        $url = preg_replace('/<[^>]*>/', '', $url) ?? $url;
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['path'])) {
            return null;
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? rtrim($parts['query'], '&').'&' : '';

        return $origin.$parts['path'].'?'.$query;
    }

    /** @return array{actions: array<string, array<string, string>>, proof: ?array{modulus: string, exponent: string}, old: ?array{modulus: string, exponent: string}} */
    public static function parse(string $xml): array
    {
        if (str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new RuntimeException('Ungültige Discovery-Antwort.');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new RuntimeException('Ungültige Discovery-Antwort.');
        }
        $actions = [];
        foreach ($doc->xpath('//net-zone/app/action') ?: [] as $action) {
            $ext = strtolower((string) $action['ext']);
            $name = (string) $action['name'];
            $url = (string) $action['urlsrc'];
            if ($ext !== '' && $name !== '' && $url !== '' && ! isset($actions[$ext][$name])) {
                $actions[$ext][$name] = $url;
            }
        }
        $proof = $old = null;
        $key = ($doc->xpath('//proof-key') ?: [null])[0];
        if ($key !== null && (string) $key['modulus'] !== '' && (string) $key['exponent'] !== '') {
            $proof = ['modulus' => (string) $key['modulus'], 'exponent' => (string) $key['exponent']];
            if ((string) $key['oldmodulus'] !== '' && (string) $key['oldexponent'] !== '') {
                $old = ['modulus' => (string) $key['oldmodulus'], 'exponent' => (string) $key['oldexponent']];
            }
        }

        return ['actions' => $actions, 'proof' => $proof, 'old' => $old];
    }

    private function fetch(string $url): string
    {
        $response = Http::timeout((int) config('dateien.office.timeout', 5))
            ->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Office-Server nicht erreichbar.');
        }

        return $response->body();
    }
}
