<?php

namespace Modules\Maengelmanagement\src\Filament\Resources;

use App\Support\QrCode;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\Maengelmanagement\src\Filament\Resources\DeviceResource\Pages;
use Modules\Maengelmanagement\src\Models\Device;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $slug = 'geraete';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Geräte & QR-Codes';

    protected static ?string $modelLabel = 'Gerät';

    protected static ?string $pluralModelLabel = 'Geräte';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gerät oder Objekt')
                ->description('Für jedes Gerät gibt es einen QR-Code. Wer ihn scannt, landet direkt im Meldeformular.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150),
                    Forms\Components\TextInput::make('location')->label('Standort')->maxLength(150)->placeholder('z. B. Fahrzeughalle'),
                    Forms\Components\Select::make('responsible_id')->label('Zuständig')
                        ->relationship('responsible', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                        ->searchable()->preload()->placeholder('– niemand –')
                        ->helperText('Neue Mängel an diesem Objekt werden dieser Person gleich zugewiesen.'),
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->unique(ignoreRecord: true)
                        ->maxLength(100)
                        ->placeholder('Wird automatisch vergeben (MM-0001)')
                        ->helperText('Der Code steht im QR-Code. Nachträglich geändert, passen bereits gedruckte Etiketten nicht mehr.'),
                    Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true)
                        ->helperText('Inaktive Geräte erscheinen nicht mehr im Meldeformular.'),
                    Forms\Components\Textarea::make('description')->label('Beschreibung')->rows(3)->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('location')->label('Standort')->placeholder('–')->searchable(),
                Tables\Columns\TextColumn::make('open_defects_count')
                    ->label('Offene Mängel')
                    ->counts(['defects as open_defects_count' => fn ($q) => $q->active()])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktiv')->default(true),
            ])
            ->actions([
                Actions\Action::make('qr')
                    ->label('QR-Code')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->modalHeading(fn (Device $record) => 'QR-Code: '.$record->name)
                    ->modalContent(fn (Device $record) => new HtmlString(static::labelHtml($record)))
                    ->modalSubmitActionLabel('Drucken')
                    ->modalSubmitAction(fn (Actions\Action $action) => $action
                        ->label('Drucken')
                        ->extraAttributes(['onclick' => 'window.print()']))
                    ->modalCancelActionLabel('Schließen'),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /** URL a scan of this device's label opens (via /i/{code}) */
    public static function scanUrl(Device $device): string
    {
        return rtrim(config('app.url'), '/').'/i/'.rawurlencode($device->code);
    }

    public static function labelHtml(Device $device): string
    {
        $svg = QrCode::svg(static::scanUrl($device));

        return '<div style="text-align:center">'
            .'<img alt="QR-Code" style="margin:0 auto;width:15rem;max-width:100%" src="data:image/svg+xml;base64,'.base64_encode($svg).'">'
            .'<p style="font-weight:600;margin-top:.5rem">'.e($device->name).'</p>'
            .'<p style="font-family:ui-monospace,monospace;font-size:.85rem">'.e($device->code).'</p>'
            .'</div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/neu'),
            'edit' => Pages\EditDevice::route('/{record}/bearbeiten'),
        ];
    }
}
