<?php

namespace Extensions\Locations\Filament\Resources;

use App\Support\BrandingImage;
use Extensions\Locations\Filament\Resources\LocationResource\Pages;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class LocationResource extends Resource
{
    protected static ?string $slug = 'standorte';

    protected static ?string $model = Location::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Standorte';

    protected static ?string $modelLabel = 'Standort';

    protected static ?string $pluralModelLabel = 'Standorte';

    protected static ?int $navigationSort = 7;

    private static function isGlobalAdmin(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /** Global admins and Standort-Admins; the latter only reach their own Standorte */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->is_active && ($user->is_admin || app(LocationAccess::class)->isLocationAdmin($user));
    }

    public static function canCreate(): bool
    {
        return self::isGlobalAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record instanceof Location && $user && app(LocationAccess::class)->canManage($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Location && self::isGlobalAdmin() && ! $record->is_default;
    }

    public static function canDeleteAny(): bool
    {
        return self::isGlobalAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return self::isGlobalAdmin() || ! $user
            ? $query
            : $query->whereIn('id', app(LocationAccess::class)->adminLocationIds($user));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
            Forms\Components\TextInput::make('short_name')->label('Kurzname')->maxLength(50),
            Forms\Components\FileUpload::make('crest_upload')
                ->label('Standortwappen')
                ->image()->storeFiles(false)->maxSize(2048)
                ->acceptedFileTypes(BrandingImage::TYPES)
                ->helperText('Optionales eigenes Wappen, unabhängig vom Feuerwehrlogo. PNG, JPEG oder WebP; höchstens 2 MB und 4096 × 4096 Pixel.'),
            Forms\Components\Toggle::make('remove_crest')->label('Vorhandenes Standortwappen entfernen')
                ->visible(fn (?Location $record) => (bool) $record?->crest_data),
            View::make('locations::partials.crest-preview'),
            Forms\Components\TextInput::make('slug')->label('Kennung')->required()->alphaDash()->maxLength(255)
                ->unique(ignoreRecord: true)->disabled(fn () => ! self::isGlobalAdmin()),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true)->disabled(fn () => ! self::isGlobalAdmin()),
            Forms\Components\Toggle::make('is_default')->label('Hauptstandort')->disabled(fn () => ! self::isGlobalAdmin()),
            Forms\Components\TextInput::make('sort_order')->label('Reihenfolge')->numeric()->default(0)->disabled(fn () => ! self::isGlobalAdmin()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('crest')->label('Wappen')
                    ->state(fn (Location $record) => $record->crestUrl()),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('short_name')->label('Kurzname'),
                Tables\Columns\TextColumn::make('members_count')->label('Mitglieder')->counts('members'),
                Tables\Columns\IconColumn::make('is_default')->label('Haupt')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
            RelationManagers\ModuleRolesRelationManager::class,
            RelationManagers\VehiclesRelationManager::class,
            RelationManagers\EquipmentRelationManager::class,
        ];
    }

    public static function prepareBranding(array $data): array
    {
        $upload = $data['crest_upload'] ?? null;
        $file = collect(is_array($upload) ? $upload : [$upload])->first(fn ($file) => $file instanceof TemporaryUploadedFile);
        if ($file) {
            $data['crest_data'] = BrandingImage::encode($file, 'data.crest_upload');
            $file->delete();
        } elseif ($data['remove_crest'] ?? false) {
            $data['crest_data'] = null;
        }

        unset($data['crest_upload'], $data['remove_crest']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/neu'),
            'edit' => Pages\EditLocation::route('/{record}/bearbeiten'),
        ];
    }
}
