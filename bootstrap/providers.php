<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AusruestungAdminPanelProvider;
use App\Providers\Filament\FahrzeugbuchungAdminPanelProvider;
use App\Providers\Filament\KleiderkammerAdminPanelProvider;
use App\Providers\Filament\MaengelmanagementAdminPanelProvider;
use Modules\Ausruestung\AusruestungServiceProvider;
use Modules\Einsatzbericht\EinsatzberichtServiceProvider;
use Modules\Fahrzeugbuchung\FahrzeugbuchungServiceProvider;
use Modules\Finanzen\FinanzenServiceProvider;
use Modules\Kalender\KalenderServiceProvider;
use Modules\Kleiderkammer\KleiderkammerServiceProvider;
use Modules\Lehrgaenge\LehrgaengeServiceProvider;
use Modules\Maengelmanagement\MaengelmanagementServiceProvider;
use Modules\Mitteilungen\MitteilungenServiceProvider;
use Modules\Schichtplan\SchichtplanServiceProvider;
use Modules\Uebungsdienste\UebungsdiensteServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FahrzeugbuchungAdminPanelProvider::class,
    KleiderkammerAdminPanelProvider::class,
    MaengelmanagementAdminPanelProvider::class,
    AusruestungAdminPanelProvider::class,
    FahrzeugbuchungServiceProvider::class,
    KleiderkammerServiceProvider::class,
    MaengelmanagementServiceProvider::class,
    AusruestungServiceProvider::class,
    SchichtplanServiceProvider::class,
    KalenderServiceProvider::class,
    LehrgaengeServiceProvider::class,
    MitteilungenServiceProvider::class,
    UebungsdiensteServiceProvider::class,
    EinsatzberichtServiceProvider::class,
    FinanzenServiceProvider::class,
];
