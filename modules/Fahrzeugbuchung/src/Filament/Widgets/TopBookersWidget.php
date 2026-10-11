<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopBookersWidget extends BaseWidget
{
    protected static ?string $heading = 'Top 5 – meiste Buchungen';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->withCount(['bookings' => fn ($q) => $q->active()])
                    ->whereHas('bookings', fn ($q) => $q->active())
                    ->orderByDesc('bookings_count')
                    ->orderBy('name')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name'),
                Tables\Columns\TextColumn::make('bookings_count')->label('Buchungen')->alignEnd(),
            ])
            ->emptyStateHeading('Noch keine Buchungen');
    }
}
