<?php

namespace Extensions\Oidc\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuration of the OIDC login. The client secret is stored encrypted (APP_KEY) in the settings
 * table and is never read back into a form or a log.
 */
class OidcSettings
{
    private const PREFIX = 'ext_oidc_';

    public function enabled(): bool
    {
        return $this->flag('enabled', false);
    }

    /** OIDC_FORCE_DISABLED=true: the emergency switch beats every admin setting */
    public function forceDisabled(): bool
    {
        return (bool) config('oidc.force_disabled');
    }

    public function label(): string
    {
        return $this->string('label') ?: __('oidc::oidc.default_label');
    }

    public function issuer(): ?string
    {
        $issuer = $this->string('issuer');

        return $issuer === '' ? null : rtrim($issuer, '/');
    }

    public function clientId(): ?string
    {
        return $this->string('client_id') ?: null;
    }

    public function secret(): ?string
    {
        $stored = Setting::get(self::PREFIX.'client_secret');
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== null;
    }

    public function saveSecret(?string $secret): void
    {
        filled($secret)
            ? Setting::set(self::PREFIX.'client_secret', Crypt::encryptString($secret))
            : Setting::forget(self::PREFIX.'client_secret');
    }

    /** @return list<string> always contains "openid" */
    public function scopes(): array
    {
        $scopes = preg_split('/\s+/', $this->string('scopes') ?: 'openid email profile', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(['openid', ...$scopes]));
    }

    /** Link an existing account by the verified e-mail address on the first login */
    public function linkByEmail(): bool
    {
        return $this->flag('link_by_email', true);
    }

    /** Create accounts on the first login (just-in-time); off by default: only existing accounts are linked */
    public function jit(): bool
    {
        return $this->flag('jit', false);
    }

    /** IdP-only: the magic link works for local system admins only */
    public function exclusive(): bool
    {
        return $this->flag('exclusive', false);
    }

    public function isConfigured(): bool
    {
        return $this->issuer() !== null && $this->clientId() !== null && $this->hasSecret();
    }

    public function isActive(): bool
    {
        return config('oidc.enabled') && ! $this->forceDisabled() && $this->enabled() && $this->isConfigured();
    }

    public function redirectUri(): string
    {
        return route('oidc.callback');
    }

    public function set(string $key, mixed $value): void
    {
        $value === null || $value === '' ? Setting::forget(self::PREFIX.$key) : Setting::set(self::PREFIX.$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    private function string(string $key): string
    {
        return trim((string) Setting::get(self::PREFIX.$key, ''));
    }

    private function flag(string $key, bool $default): bool
    {
        $value = Setting::get(self::PREFIX.$key);

        return $value === null ? $default : (string) $value === '1';
    }
}
