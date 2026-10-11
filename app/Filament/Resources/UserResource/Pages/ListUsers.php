<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\ModuleRegistry;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        $modules = app(ModuleRegistry::class)->all();

        return [
            Actions\ActionGroup::make([
                UserResource::grantModuleAction(Actions\Action::make('grant_module_all'), $modules)
                    ->modalDescription(fn () => 'Gilt für alle '.$this->filteredUserIds()->count().' Kameraden, die mit den aktuellen Filtern und der Suche angezeigt werden – auch auf anderen Seiten.')
                    ->action(fn (array $data) => UserResource::grantModule($this->filteredUserIds(), $data)),

                UserResource::revokeModuleAction(Actions\Action::make('revoke_module_all'), $modules)
                    ->modalDescription(fn () => 'Gilt für alle '.$this->filteredUserIds()->count().' Kameraden, die mit den aktuellen Filtern und der Suche angezeigt werden – auch auf anderen Seiten.')
                    ->action(fn (array $data) => UserResource::revokeModule($this->filteredUserIds(), $data['module'])),
            ])
                ->label('Module für alle Gefilterten')
                ->icon('heroicon-o-squares-plus')
                ->button()
                ->color('gray'),

            Actions\CreateAction::make()->label('Kamerad anlegen'),
        ];
    }

    /** Every Kamerad the filters and the search currently match, not just the visible page */
    private function filteredUserIds()
    {
        return $this->getFilteredTableQuery()->pluck('users.id');
    }
}
