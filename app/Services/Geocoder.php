<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Address → coordinates via the Google Geocoding API (key under Admin → Einstellungen → Dienste) */
class Geocoder
{
    public function __construct(private readonly ServiceSettings $services) {}

    public function available(): bool
    {
        return filled($this->services->googleMapsKey());
    }

    /** @return array{lat: float, lng: float}|null null without key, without result or on any error */
    public function locate(?string $address): ?array
    {
        $key = $this->services->googleMapsKey();
        if (blank($address) || blank($key)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'de',
                'language' => 'de',
                'key' => $key,
            ]);
            $location = $response->json('results.0.geometry.location');

            return $response->json('status') === 'OK' && isset($location['lat'], $location['lng'])
                ? ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']]
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
