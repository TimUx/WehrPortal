<?php

namespace Modules\Ausruestung\src\Filament\Resources\CategoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Modules\Ausruestung\src\Filament\Resources\CategoryResource;
use Modules\Ausruestung\src\Models\Category;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    public function getSubheading(): ?string
    {
        return 'Die Lebensdauern und Prüfintervalle sind Vorschlagswerte. Gleiche sie mit den Herstellerangaben und den geltenden DGUV-Regeln ab; jedes Gerät kann eigene Werte haben.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Kategorie anlegen')
                ->mutateDataUsing(fn (array $data) => $data + ['sort_order' => (int) Category::max('sort_order') + 1]),
        ];
    }
}
