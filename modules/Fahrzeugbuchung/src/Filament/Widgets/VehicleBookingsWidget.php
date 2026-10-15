<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class VehicleBookingsWidget extends BaseWidget
{
    protected static ?string $heading = 'Fahrzeuge nach Anzahl Buchungen';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Vehicle::query()
                    ->withCount(['bookings' => fn ($q) => $q->active()])
                    ->orderByDesc('bookings_count')
                    ->orderBy('sort_order')
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\ColorColumn::make('color')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Fahrzeug'),
                Tables\Columns\TextColumn::make('bookings_count')->label('Buchungen')->alignEnd(),
            ]);
    }
}
