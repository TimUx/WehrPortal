<?php

namespace Extensions\Oidc\Support;

use App\Services\AuthMethod;

class OidcAuthMethod implements AuthMethod
{
    public function __construct(private readonly OidcSettings $settings) {}

    public function key(): string
    {
        return 'oidc';
    }

    public function label(): string
    {
        return $this->settings->label();
    }

    public function loginUrl(): string
    {
        return route('oidc.redirect');
    }

    public function isEnabled(): bool
    {
        return $this->settings->isActive();
    }

    public function isExclusive(): bool
    {
        return $this->settings->exclusive();
    }
}
