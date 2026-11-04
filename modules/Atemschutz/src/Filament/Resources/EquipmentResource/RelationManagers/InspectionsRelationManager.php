<?php

namespace Modules\Atemschutz\src\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentInspection;
use Modules\Atemschutz\src\Services\EquipmentService;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Prüfungen';

    protected static ?string $modelLabel = 'Prüfung';

    protected static ?string $pluralModelLabel = 'Prüfungen';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prüfung und Prüfer')
                ->schema([
                    Forms\Components\DatePicker::make('performed_on')->label('Prüfung am')->required()->native(false)->displayFormat('d.m.Y')->maxDate(today()),
                    Forms\Components\DatePicker::make('next_due_on')->label('Nächste Prüfung')->native(false)->displayFormat('d.m.Y')
                        ->after('performed_on'),
                    Forms\Components\Select::make('result')->label('Ergebnis')->options([
                        'passed' => 'Bestanden',
                        'failed' => 'Nicht bestanden',
                    ])->required(),
                    Forms\Components\TextInput::make('inspector_name')->label('Prüfer')->required()->maxLength(150)
                        ->default(fn () => auth()->user()?->name),
                    Forms\Components\TextInput::make('qualification')->label('Qualifikation')->required()->maxLength(150),
                    Forms\Components\TextInput::make('inspector_number')->label('Prüfernummer')->maxLength(80),
                    Forms\Components\TextInput::make('provider_name')->label('Prüfstelle / Fachfirma')->maxLength(150),
                    Forms\Components\Textarea::make('findings')->label('Feststellungen')->maxLength(10000)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3]),
            Section::make('Prüfschritte')
                ->schema([
                    Forms\Components\Repeater::make('checklist')
                        ->label('Checkliste')
                        ->schema([
                            Forms\Components\TextInput::make('item')->label('Prüfschritt')->required()->maxLength(180),
                            Forms\Components\Select::make('result')->label('Bewertung')->options([
                                'passed' => 'In Ordnung',
                                'failed' => 'Mangel',
                                'not_applicable' => 'Entfällt',
                            ])->required(),
                            Forms\Components\TextInput::make('notes')->label('Bemerkung')->maxLength(1000),
                        ])
                        ->columns(3)
                        ->minItems(1)
                        ->required(),
                    Forms\Components\ViewField::make('signature')->label('Unterschrift des Prüfers')
                        ->view('atemschutz::filament.signature-pad')
                        ->required(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('performed_on')
            ->columns([
                Tables\Columns\TextColumn::make('performed_on')->label('Prüfung am')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('result')->label('Ergebnis')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'passed' ? 'Bestanden' : 'Nicht bestanden')
                    ->color(fn (string $state) => $state === 'passed' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('inspector_name')->label('Prüfer'),
                Tables\Columns\TextColumn::make('qualification')->label('Qualifikation')->toggleable(),
                Tables\Columns\TextColumn::make('next_due_on')->label('Nächste Prüfung')->date('d.m.Y')->placeholder('–'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Prüfung unterschreiben und speichern')
                    ->using(function (array $data, RelationManager $livewire): EquipmentInspection {
                        $equipment = $livewire->getOwnerRecord();
                        if (! $equipment instanceof Equipment) {
                            throw new \LogicException('The inspection relation must belong to Atemschutz equipment.');
                        }

                        return app(EquipmentService::class)->recordInspection($equipment, $data, auth()->user());
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('performed_on', 'desc');
    }
}
