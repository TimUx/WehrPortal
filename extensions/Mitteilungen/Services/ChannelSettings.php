<?php

namespace Extensions\Mitteilungen\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Mitteilungen\Models\ChannelDestination;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChannelSettings
{
    public const TRANSPORTS = ['ntfy', 'matrix'];

    /** @return list<array{key: string, location_id: ?int, label: string}> */
    public function scopes(User $user): array
    {
        $scopes = [];

        if ($user->is_admin) {
            $scopes[] = ['key' => 'general', 'location_id' => null, 'label' => 'Allgemein'];
        }

        if (! config('locations.enabled') || ! Schema::hasTable('x_locations') || ! app()->bound(LocationAccess::class)) {
            return $scopes;
        }

        $access = app(LocationAccess::class);
        $ids = $user->is_admin ? Location::query()->pluck('id')->all() : $access->adminLocationIds($user);
        $locations = Location::query()->whereKey($ids)->orderBy('sort_order')->orderBy('name')->get();

        foreach ($locations as $location) {
            $scopes[] = [
                'key' => 'location:'.$location->id,
                'location_id' => (int) $location->id,
                'label' => 'Standort: '.$location->name,
            ];
        }

        return $scopes;
    }

    public function assertMayManage(User $user, string $scopeKey): void
    {
        $valid = collect($this->scopes($user))->contains(fn ($scope) => $scope['key'] === $scopeKey);
        abort_unless($valid, 403);
    }

    /** @return array{enabled: bool, server_url: string, target: string, has_token: bool} */
    public function formValues(string $scopeKey, string $transport): array
    {
        $destination = ChannelDestination::query()->where('scope_key', $scopeKey)->where('transport', $transport)->first();

        return [
            'enabled' => (bool) $destination?->enabled,
            'server_url' => (string) ($destination?->server_url ?? ''),
            'target' => (string) ($destination?->target ?? ''),
            'has_token' => $destination ? $this->token($destination) !== null : false,
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, string $scopeKey, string $transport, array $data): void
    {
        $this->assertMayManage($user, $scopeKey);

        if (! in_array($transport, self::TRANSPORTS, true)) {
            abort(404);
        }

        $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $serverUrl = trim((string) ($data['server_url'] ?? ''));
        $target = trim((string) ($data['target'] ?? ''));
        $removeToken = filter_var($data['remove_token'] ?? false, FILTER_VALIDATE_BOOL);
        $typedToken = trim((string) ($data['token'] ?? ''));
        $destination = ChannelDestination::query()->firstOrNew(['scope_key' => $scopeKey, 'transport' => $transport]);

        if ($enabled) {
            $serverUrl = $this->validateServerUrl($serverUrl, checkPublicHost: true);
            $this->validateTarget($transport, $target);
            $this->assertUniqueTarget($scopeKey, $transport, $serverUrl, $target);
        } else {
            if ($serverUrl !== '') {
                $serverUrl = $this->validateServerUrl($serverUrl, checkPublicHost: false);
            }
            if ($target !== '') {
                $this->validateTarget($transport, $target);
            }
        }

        if ($enabled && ($removeToken || (blank($typedToken) && ! $this->token($destination)))) {
            throw ValidationException::withMessages(['data.'.$scopeKey.'.'.$transport.'.token' => 'Zum Aktivieren ist ein Zugriffstoken erforderlich.']);
        }

        $locationId = str_starts_with($scopeKey, 'location:') ? (int) substr($scopeKey, strlen('location:')) : null;
        $destination->fill([
            'scope_key' => $scopeKey,
            'location_id' => $locationId,
            'transport' => $transport,
            'enabled' => $enabled,
            'server_url' => $serverUrl ?: null,
            'target' => $target ?: null,
        ]);

        if ($removeToken) {
            $destination->token = null;
        } elseif ($typedToken !== '') {
            if (strlen($typedToken) > 2000 || preg_match('/[\r\n]/', $typedToken)) {
                throw ValidationException::withMessages(['data.'.$scopeKey.'.'.$transport.'.token' => 'Das Zugriffstoken ist ungültig.']);
            }
            $destination->token = $typedToken;
        }

        $destination->save();
    }

    public function destinationFor(int|string|null $locationId, string $transport): ?ChannelDestination
    {
        $scopeKey = $locationId === null ? 'general' : 'location:'.$locationId;
        $destination = ChannelDestination::query()->where('scope_key', $scopeKey)
            ->where('transport', $transport)->where('enabled', true)->first();

        if (! $destination || blank($destination->server_url) || blank($destination->target)) {
            return null;
        }

        return $destination;
    }

    public function token(ChannelDestination $destination): ?string
    {
        $stored = $destination->exists ? $destination->getRawOriginal('token') : null;
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    private function validateServerUrl(string $url, bool $checkPublicHost): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['port'] ?? 443, [443], true)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw ValidationException::withMessages(['data' => 'Der Server muss eine HTTPS-Adresse ohne Pfad, Zugangsdaten oder eigenen Port sein.']);
        }

        if ($checkPublicHost) {
            try {
                \App\Support\SafeUrl::check($url);
            } catch (Throwable) {
                throw ValidationException::withMessages(['data' => 'Der Server ist nicht erreichbar oder nicht zulässig. Erlaubt sind öffentliche HTTPS-Server.']);
            }
        }

        return rtrim($url, '/');
    }

    private function validateTarget(string $transport, string $target): void
    {
        $valid = match ($transport) {
            'ntfy' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $target) === 1,
            'matrix' => preg_match('/^![A-Za-z0-9._=-]{1,200}:[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?$/', $target) === 1,
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['data' => $transport === 'ntfy'
                ? 'Das ntfy-Topic darf nur Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.'
                : 'Bitte eine gültige Matrix-Raum-ID (beginnend mit !) angeben.']);
        }
    }

    private function assertUniqueTarget(string $scopeKey, string $transport, string $serverUrl, string $target): void
    {
        $duplicate = ChannelDestination::query()->where('transport', $transport)
            ->where('scope_key', '!=', $scopeKey)
            ->whereRaw('LOWER(server_url) = ?', [mb_strtolower($serverUrl)])
            ->where('target', $target)->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['data' => 'Dieses Ziel ist bereits einem anderen Geltungsbereich zugeordnet. Allgemeine und standortbezogene Ziele müssen getrennt sein.']);
        }
    }
}
