<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Fahrzeugbuchung\src\Models\Booking;

class UpcomingBookingsWidget extends BaseWidget
{
    protected static ?string $heading = 'Kommende Buchungen (nächste 7 Tage)';

    // Next to the Top 5 on the desktop, below it on the phone
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::with(['vehicle', 'user'])
                    ->active()
                    ->whereBetween('starts_at', [now(), now()->addDays(7)])
                    ->orderBy('starts_at')
            )
            ->columns([
                Tables\Columns\ColorColumn::make('vehicle.color')
                    ->label(''),

                Tables\Columns\TextColumn::make('vehicle.name')
                    ->label('Fahrzeug'),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Von')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin'),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Bis')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nutzer'),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Zweck')
                    ->limit(30),
            ]);
    }
}
