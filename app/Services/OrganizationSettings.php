<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Wehr-specific settings: name, short name, mail salutation, logo.
 * All values fall back to env/config when not set in the database.
 */
class OrganizationSettings
{
    /** Full name, e.g. "Freiwillige Feuerwehr Musterstadt" */
    public function name(): string
    {
        return Setting::get('org_name') ?? config('app.name', 'Meine Feuerwehr');
    }

    /** Short name for compact contexts, e.g. "FF Musterstadt" */
    public function shortName(): string
    {
        return Setting::get('org_short_name') ?? $this->name();
    }

    /** Closing line in outgoing mails, e.g. "Deine Freiwillige Feuerwehr Musterstadt" */
    public function salutation(): string
    {
        return Setting::get('org_salutation') ?? 'Deine '.$this->name();
    }

    /** Cache-busting timestamp for the logo; null when no logo uploaded */
    public function logoUpdatedAt(): ?string
    {
        return Setting::get('org_logo_updated_at');
    }

    /** Public URL for the logo, or null when none uploaded */
    public function logoUrl(): ?string
    {
        return $this->logoUpdatedAt()
            ? route('organization.logo', ['v' => $this->logoUpdatedAt()])
            : null;
    }

    /** Raw base64 logo data, or null */
    public function logoData(): ?string
    {
        return Setting::get('org_logo');
    }

    /** MIME type of the logo, or null */
    public function logoMime(): ?string
    {
        return Setting::get('org_logo_mime');
    }

    public function saveLogo(string $base64, string $mime): void
    {
        Setting::set('org_logo', $base64);
        Setting::set('org_logo_mime', $mime);
        Setting::set('org_logo_updated_at', (string) now()->timestamp);
    }

    public function deleteLogo(): void
    {
        Setting::forget('org_logo');
        Setting::forget('org_logo_mime');
        Setting::forget('org_logo_updated_at');
    }

    /** Prefix for Kleiderkammer item codes, e.g. "WEHR". Configurable in 2.7. */
    public function codePrefix(): string
    {
        return Setting::get('org_code_prefix') ?? 'WEHR';
    }
}
