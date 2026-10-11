<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Address suggestions while typing, so no Einsatzstelle is mistyped: Google Places API (New),
 * Autocomplete and Place Details, with the Google key under Admin → Einstellungen → Dienste.
 * The key stays on the server; the browser only talks to Livewire. Without a key there are no
 * suggestions and the field is a plain text field.
 */
class AddressSuggestions
{
    public function __construct(private readonly ServiceSettings $services) {}

    public function available(): bool
    {
        return filled($this->services->googleMapsKey());
    }

    /**
     * @param  ?array{lat: float, lng: float}  $near  prefer results around this point (e.g. the Wehr's area)
     * @return list<array{id: string, main: string, secondary: string}>
     */
    public function suggest(string $input, string $session, ?array $near = null): array
    {
        $input = trim($input);
        $key = $this->services->googleMapsKey();
        if (mb_strlen($input) < 3 || blank($key)) {
            return [];
        }

        return Cache::remember('address_suggest_'.sha1($input.'|'.json_encode($near)), 600, function () use ($input, $session, $near, $key) {
            try {
                $response = Http::timeout(5)->acceptJson()->withHeaders(['X-Goog-Api-Key' => $key])
                    ->post('https://places.googleapis.com/v1/places:autocomplete', array_filter([
                        'input' => $input,
                        'languageCode' => 'de',
                        'regionCode' => 'de',
                        'includedRegionCodes' => ['de'],
                        'sessionToken' => $session,
                        'locationBias' => $near ? ['circle' => ['center' => ['latitude' => $near['lat'], 'longitude' => $near['lng']], 'radius' => 30000.0]] : null,
                    ]));
                if (! $response->successful()) {
                    report(new \RuntimeException('Places Autocomplete: HTTP '.$response->status().' '.$response->json('error.message')));

                    return [];
                }

                return collect($response->json('suggestions', []))
                    ->map(fn ($s) => $s['placePrediction'] ?? null)->filter()
                    ->map(fn ($p) => [
                        'id' => (string) $p['placeId'],
                        'main' => (string) ($p['structuredFormat']['mainText']['text'] ?? $p['text']['text'] ?? ''),
                        'secondary' => self::withoutCountry((string) ($p['structuredFormat']['secondaryText']['text'] ?? '')),
                    ])
                    ->take(6)->values()->all();
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * The chosen suggestion as address and point. For a place (e.g. a company or school) its name
     * comes along, as Objekt for the report.
     *
     * @return ?array{address: string, lat: ?float, lng: ?float, name: ?string}
     */
    public function details(string $placeId, string $session): ?array
    {
        $key = $this->services->googleMapsKey();
        if (blank($key) || ! preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId)) {
            return null;
        }

        try {
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'formattedAddress,location,displayName,types'])
                ->get('https://places.googleapis.com/v1/places/'.$placeId, ['languageCode' => 'de', 'regionCode' => 'de', 'sessionToken' => $session]);
            if (! $response->successful() || blank($response->json('formattedAddress'))) {
                return null;
            }

            $address = self::withoutCountry((string) $response->json('formattedAddress'));
            $name = (string) $response->json('displayName.text');
            $isAddress = array_intersect($response->json('types', []), ['street_address', 'premise', 'subpremise', 'route', 'locality', 'postal_code']) !== [];

            return [
                'address' => $address,
                'lat' => $response->json('location.latitude') !== null ? (float) $response->json('location.latitude') : null,
                'lng' => $response->json('location.longitude') !== null ? (float) $response->json('location.longitude') : null,
                'name' => ! $isAddress && $name !== '' && ! str_starts_with($address, $name) ? $name : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function withoutCountry(string $text): string
    {
        return (string) preg_replace('/,\s*(Deutschland|Germany)$/u', '', trim($text));
    }
}
