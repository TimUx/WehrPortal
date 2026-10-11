<?php

namespace App\Filament\Concerns;

use App\Services\MemberStructureTransfer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** "Exportieren" and "Importieren" (CSV) for the Abteilungen and Funktionen lists */
trait TransfersMemberStructure
{
    /** @param  'groups'|'functions'  $kind */
    protected function transferActions(string $kind): array
    {
        $label = $kind === 'groups' ? 'Abteilungen' : 'Funktionen';

        return [
            Action::make('export')
                ->label('Exportieren')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                // Livewire only turns streamed responses into a browser download
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(MemberStructureTransfer::class)->{$kind === 'groups' ? 'exportGroups' : 'exportFunctions'}()),
                    mb_strtolower($label).'-'.now()->format('Y-m-d').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),

            Action::make('import')
                ->label('Importieren')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading($label.' importieren')
                ->modalDescription('CSV wie beim Export (Semikolon, UTF-8). Vorhandene '.$label.' werden über den Namen erkannt und aktualisiert, neue angelegt; gelöscht wird nichts.')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV-Datei')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->required(),
                    Toggle::make('members')
                        ->label('Mitglieder zuordnen (Spalte „Mitglieder“, über die E-Mail-Adresse)')
                        ->helperText('Fügt nur hinzu, nimmt niemanden heraus. Unbekannte Adressen werden gemeldet.')
                        ->default(true),
                ])
                ->action(function (array $data) use ($kind, $label) {
                    $content = Storage::disk('local')->get($data['file']);
                    Storage::disk('local')->delete($data['file']);

                    try {
                        $stats = app(MemberStructureTransfer::class)->{$kind === 'groups' ? 'importGroups' : 'importFunctions'}((string) $content, (bool) $data['members']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Import nicht möglich')->body(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($label.' importiert')
                        ->body($stats['created'].' neu, '.$stats['updated'].' aktualisiert, '.$stats['members'].' Mitglieder zugeordnet.'
                            .($stats['unknown'] ? ' Unbekannte E-Mail-Adressen: '.implode(', ', array_slice($stats['unknown'], 0, 10)).(count($stats['unknown']) > 10 ? ' …' : '') : ''))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
