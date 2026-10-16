<?php

namespace App\Services;

/**
 * A way to log in besides the magic link (e.g. an external identity provider). Extensions register
 * implementations with the AuthMethodRegistry; the login page lists the enabled ones.
 */
interface AuthMethod
{
    /** Stable identifier, e.g. "oidc" */
    public function key(): string;

    /** Text of the button on the login page */
    public function label(): string;

    /** Where the button leads (starts the login) */
    public function loginUrl(): string;

    public function isEnabled(): bool;

    /** An exclusive method replaces the magic link for everyone except local admins */
    public function isExclusive(): bool;
}
