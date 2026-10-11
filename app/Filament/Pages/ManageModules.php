<?php

namespace App\Filament\Pages;

use App\Models\Module;
use App\Services\ModuleRegistry;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ManageModules extends Page implements HasTable
{
    protected static ?string $slug = 'module';

    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Module';

    protected static ?string $title = 'Module verwalten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.manage-modules';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_admin ?? false;
    }

    public function mount(): void
    {
        // Ensure every registered module has a row in the DB so the table shows it.
        foreach (array_keys(app(ModuleRegistry::class)->all()) as $key) {
            Module::firstOrCreate(['key' => $key], ['is_active' => false, 'auto_assign' => false]);
        }
    }

    public function table(Table $table): Table
    {
        $registry = app(ModuleRegistry::class);

        // Names and descriptions live in code, not in the table; sort and search map them back to keys
        $names = collect($registry->all())->map(fn ($def) => $def['name']);
        $byName = fn (Builder $query, string $direction) => $query->orderByRaw(
            'case key '.$names->sort(SORT_NATURAL | SORT_FLAG_CASE)->keys()->map(fn ($key, $i) => "when '".str_replace("'", "''", $key)."' then ".$i)->implode(' ').' else 999 end '.($direction === 'desc' ? 'desc' : 'asc')
        );
        $matching = fn (string $search) => collect($registry->all())
            ->filter(fn ($def, $key) => str_contains(mb_strtolower($def['name'].' '.$def['description'].' '.implode(' ', $def['roles']).' '.$key), mb_strtolower($search)))
            ->keys()->all();

        return $table
            ->query(Module::whereIn('key', array_keys($registry->all()) ?: ['__none__'])
                ->withCount(['users as users_count']))
            ->columns([
                TextColumn::make('module_name')
                    ->label('Modul')
                    ->state(fn (Module $record): string => $registry->get($record->key)['name'] ?? $record->key)
                    ->weight('bold')
                    // Active modules link to their start page in the app
                    ->url(fn (Module $record): ?string => $record->is_active && ($route = $registry->get($record->key)['route'] ?? null)
                        ? (str_starts_with($route, '/') ? url($route) : route($route))
                        : null)
                    ->color(fn (Module $record) => $record->is_active ? 'primary' : null)
                    ->tooltip(fn (Module $record) => $record->is_active ? 'Zum Modul' : 'Erst aktivieren, dann erreichbar')
                    ->sortable(query: $byName)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereIn('key', $matching($search) ?: ['__none__'])),

                TextColumn::make('module_description')
                    ->label('Beschreibung')
                    ->state(fn (Module $record): string => $registry->get($record->key)['description'] ?? '')
                    ->wrap()
                    ->color('gray'),

                TextColumn::make('module_roles')
                    ->label('Rollen')
                    ->state(fn (Module $record): string => implode(', ', $registry->get($record->key)['roles'] ?? []))
                    ->badge()
                    ->separator(',')
                    ->color('primary'),

                TextColumn::make('users_count')
                    ->label('Freigeschaltet')
                    ->suffix(' Kameraden')
                    // Opens the list of all Kameraden to switch them one by one
                    ->url(fn (Module $record) => ModuleMembers::getUrl(['module' => $record->key]))
                    ->color('primary')
                    ->tooltip('Kameraden freischalten oder entziehen')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Aktiv')
                    ->sortable()
                    ->afterStateUpdated(fn () => Cache::forget('modules_active_keys')),

                ToggleColumn::make('auto_assign')
                    ->label('Neue Kameraden automatisch freischalten')
                    ->sortable(),
            ])
            ->defaultSort($byName)
            ->filters([
                TernaryFilter::make('is_active')->label('Aktiv')->placeholder('Alle')->trueLabel('Nur aktive')->falseLabel('Nur ausgeschaltete'),
                TernaryFilter::make('auto_assign')->label('Automatisch freischalten')->placeholder('Alle')->trueLabel('Ja')->falseLabel('Nein'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Keine Module registriert')
            ->emptyStateDescription('Module werden per Service-Provider registriert.');
    }
}
