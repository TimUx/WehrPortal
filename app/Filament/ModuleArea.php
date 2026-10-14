<?php

namespace App\Filament;

use App\Models\User;

// The entry page of a module's area in the one admin panel (/admin/<key>…): a ModuleDashboard or a single
// page such as a module's Einstellungen. allows() decides who manages the module, so Kleiderwarte,
// Wehrführung, Personalverwalter etc. get into /admin with just their module, while admins see everything.
interface ModuleArea
{
    /** Whether $user (active) manages this module in /admin */
    public static function allows(User $user): bool;
}
