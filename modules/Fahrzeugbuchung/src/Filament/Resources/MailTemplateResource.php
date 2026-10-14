<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\Fahrzeugbuchung\src\Filament\Concerns\FahrzeugbuchungArea;
use Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource\Pages;
use Modules\Fahrzeugbuchung\src\Models\MailTemplate;

class MailTemplateResource extends Resource
{
    use FahrzeugbuchungArea;

    protected static ?string $slug = 'fahrzeugbuchung/e-mail-texte';

    protected static ?string $model = MailTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'E-Mail-Texte';

    protected static ?string $modelLabel = 'E-Mail-Text';

    protected static ?string $pluralModelLabel = 'E-Mail-Texte';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Placeholder::make('placeholders')
                ->label('Platzhalter')
                ->content(fn (?MailTemplate $record) => new HtmlString(
                    collect(MailTemplate::placeholders($record?->key ?? ''))
                        ->map(fn ($description, $name) => '<code>{'.e($name).'}</code> – '.e($description))
                        ->implode('<br>')
                ))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('subject')
                ->label('Betreff')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('greeting')
                ->label('Anrede')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('body')
                ->label('Text')
                ->required()
                ->rows(10)
                ->helperText('Absätze durch eine Leerzeile trennen. **Text** wird fett dargestellt.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('action_label')
                ->label('Button-Beschriftung')
                ->helperText('Leer lassen, um keinen Button anzuzeigen.')
                ->maxLength(255),

            Forms\Components\TextInput::make('salutation')
                ->label('Grußformel')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('E-Mail'),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Betreff')
                    ->limit(60),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Zuletzt geändert')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin'),
            ])
            ->defaultSort('name')
            ->paginated(false)
            ->actions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailTemplates::route('/'),
            'edit' => Pages\EditMailTemplate::route('/{record}/bearbeiten'),
        ];
    }
}
