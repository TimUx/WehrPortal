<?php

namespace Modules\Ausruestung\src\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Ausruestung\src\Filament\Concerns\AusruestungArea;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Support\PublicScan;

/** The Gerätewart switches the Geräteinfo without login on or off */
class PublicScanPage extends Page
{
    use AusruestungArea;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Geräteinfo per QR-Code';

    protected static ?string $title = 'Geräteinfo per QR-Code';

    protected static ?string $slug = 'ausruestung/geraeteinfo';

    protected static ?int $navigationSort = 90;

    protected string $view = 'ausruestung::filament.pages.public-scan';

    public static function canAccess(): bool
    {
        return static::canAccessArea() && EquipmentService::canManageGlobal(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        $on = PublicScan::enabled();

        return [
            Action::make('toggle')
                ->label($on ? 'Ausschalten' : 'Einschalten')
                ->color($on ? 'gray' : 'primary')
                ->requiresConfirmation()
                ->modalDescription($on ? 'Wer ohne Login scannt, kommt dann wieder zur Anmeldung.' : 'Wer das Etikett eines Geräts ohne Login scannt, sieht dann Name, Status, nächste Prüfung und Standort.')
                ->action(function () use ($on) {
                    PublicScan::set(! $on);
                    Notification::make()->title($on ? 'Geräteinfo ohne Login ist aus.' : 'Geräteinfo ohne Login ist an.')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return ['on' => PublicScan::enabled()];
    }
}
