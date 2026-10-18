<?php

namespace Extensions\Dateien\Office;

use App\Models\Setting;
use Extensions\Dateien\Services\FileService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Admin settings for Online-Office. At most one provider per instance. The office server URLs are
 * an admin decision and may point into the internal network (e.g. a Docker service), so they do
 * not go through SafeUrl; the app only ever calls exactly these hosts, without redirects.
 */
class OfficeSettings
{
    public const PROVIDERS = ['onlyoffice' => 'ONLYOFFICE Docs', 'collabora' => 'Collabora Online (WOPI)'];

    /** Formats either server can show; the admin narrows them down, FileService's list applies too */
    public const VIEWABLE = ['docx', 'doc', 'odt', 'rtf', 'txt', 'xlsx', 'xls', 'ods', 'csv', 'pptx', 'ppt', 'odp', 'pdf'];

    private const PREFIX = 'dateien.office.';

    public function provider(): ?string
    {
        $provider = (string) Setting::get(self::PREFIX.'provider', '');

        return isset(self::PROVIDERS[$provider]) ? $provider : null;
    }

    /** Address of the office server as the browser reaches it */
    public function serverUrl(): ?string
    {
        return $this->url('server_url');
    }

    /** Address of the office server as the app reaches it (discovery, health check); defaults to serverUrl */
    public function internalUrl(): ?string
    {
        return $this->url('internal_url') ?? $this->serverUrl();
    }

    /** Base URL of this app as the office server reaches it; defaults to APP_URL */
    public function callbackUrl(): string
    {
        return $this->url('callback_url') ?? rtrim((string) config('app.url'), '/');
    }

    public function secret(): ?string
    {
        $stored = Setting::get(self::PREFIX.'jwt_secret');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return list<string> */
    public function extensions(): array
    {
        $stored = json_decode((string) Setting::get(self::PREFIX.'extensions', ''), true);
        $list = is_array($stored) ? $stored : self::VIEWABLE;

        return array_values(array_intersect(self::VIEWABLE, $list, FileService::allowedExtensions()));
    }

    public function enabled(): bool
    {
        return $this->provider() !== null && $this->serverUrl() !== null
            && ($this->provider() !== 'onlyoffice' || $this->secret() !== null);
    }

    public function supports(string $extension): bool
    {
        return $this->enabled() && in_array(strtolower($extension), $this->extensions(), true);
    }

    /** Origin (scheme://host[:port]) of the browser-facing office server, for CSP and form targets */
    public function serverOrigin(): ?string
    {
        $url = $this->serverUrl();

        return $url ? self::origin($url) : null;
    }

    /**
     * @param  array{provider: ?string, server_url: ?string, internal_url: ?string, callback_url: ?string, extensions: array<int, string>}  $data
     */
    public function save(array $data, ?string $secret): void
    {
        $provider = $data['provider'] ?: null;
        if ($provider !== null && ! isset(self::PROVIDERS[$provider])) {
            throw ValidationException::withMessages(['office.provider' => 'Unbekannter Anbieter.']);
        }
        foreach (['server_url', 'internal_url', 'callback_url'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && ! self::validUrl($value)) {
                throw ValidationException::withMessages(['office.'.$field => 'Bitte eine http(s)-Adresse ohne Benutzer, Pfadparameter oder Fragment angeben.']);
            }
            $value === '' ? Setting::forget(self::PREFIX.$field) : Setting::set(self::PREFIX.$field, rtrim($value, '/'));
        }
        if ($provider !== null && trim((string) ($data['server_url'] ?? '')) === '') {
            throw ValidationException::withMessages(['office.server_url' => 'Die Adresse des Office-Servers fehlt.']);
        }
        $provider === null ? Setting::forget(self::PREFIX.'provider') : Setting::set(self::PREFIX.'provider', $provider);
        Setting::set(self::PREFIX.'extensions', json_encode(array_values(array_intersect(self::VIEWABLE, $data['extensions'])), JSON_THROW_ON_ERROR));
        if (filled($secret)) {
            if (mb_strlen($secret) < 32) {
                throw ValidationException::withMessages(['office.secret' => 'Das Secret muss mindestens 32 Zeichen lang sein.']);
            }
            Setting::set(self::PREFIX.'jwt_secret', Crypt::encryptString($secret));
        }
        if ($provider === 'onlyoffice' && $this->secret() === null) {
            throw ValidationException::withMessages(['office.secret' => 'ONLYOFFICE braucht ein JWT-Secret (wie JWT_SECRET des Document Servers).']);
        }
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function url(string $key): ?string
    {
        $value = (string) Setting::get(self::PREFIX.$key, '');

        return $value !== '' && self::validUrl($value) ? rtrim($value, '/') : null;
    }
}
