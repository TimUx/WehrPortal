<?php

namespace App\Filament\Resources\MemberFunctionResource\Pages;

use App\Filament\Concerns\TransfersMemberStructure;
use App\Filament\Resources\MemberFunctionResource;
use App\Models\MemberFunction;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberFunctions extends ManageRecords
{
    use TransfersMemberStructure;

    protected static string $resource = MemberFunctionResource::class;

    public function getSubheading(): ?string
    {
        return 'Was jemand macht, z. B. Jugendwart. In welcher Abteilung jemand Mitglied ist, steht unter „Abteilungen“.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->transferActions('functions'),
            Actions\CreateAction::make()
                ->label('Funktion anlegen')
                ->mutateDataUsing(fn (array $data) => $data + [
                    'sort_order' => (int) MemberFunction::topLevel()->max('sort_order') + 1,
                ]),
        ];
    }
}
