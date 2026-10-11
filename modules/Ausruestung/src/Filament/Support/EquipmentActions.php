<?php

namespace Modules\Ausruestung\src\Filament\Support;

use App\Enums\MemberStatus;
use App\Models\User;
use App\Support\QrCode;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\EquipmentEvent;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\LoanService;

/**
 * The actions on an item, shared by the equipment list, the item page and its sub-lists.
 * $equipment resolves the item from the table record or the page.
 */
class EquipmentActions
{
    public static function qr(callable $equipment): Action
    {
        return Action::make('qr')
            ->label('QR-Code')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading(fn ($record, $livewire) => 'QR-Code: '.$equipment($record, $livewire)->name)
            ->modalContent(fn ($record, $livewire) => new HtmlString(static::labelHtml($equipment($record, $livewire))))
            ->modalSubmitAction(fn (Action $action) => $action->label('Drucken')->extraAttributes(['onclick' => 'window.print()']))
            ->modalCancelActionLabel('Schließen');
    }

    public static function inspect(callable $equipment): Action
    {
        return Action::make('inspect')
            ->label('Prüfung eintragen')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->schema([
                Forms\Components\DatePicker::make('inspected_at')->label('Geprüft am')->default(today())->maxDate(today())->required()->native(false)->displayFormat('d.m.Y'),
                Forms\Components\Toggle::make('passed')->label('Bestanden')->default(true)
                    ->helperText('Nicht bestanden: Das Gerät wird als defekt gekennzeichnet und ein Schaden angelegt.'),
                Forms\Components\Textarea::make('notes')->label('Bemerkung')->rows(2),
            ])
            ->visible(fn ($record, $livewire) => $equipment($record, $livewire)->status !== EquipmentStatus::Retired)
            ->action(function (array $data, $record, $livewire) use ($equipment) {
                $item = $equipment($record, $livewire);
                app(EquipmentService::class)->recordInspection($item, auth()->user(), Carbon::parse($data['inspected_at']), (bool) $data['passed'], $data['notes'] ?? null);

                Notification::make()
                    ->title($data['passed'] ? 'Prüfung eingetragen' : 'Prüfung nicht bestanden – Gerät gesperrt')
                    ->body($item->refresh()->next_inspection_at ? 'Nächste Prüfung: '.$item->next_inspection_at->format('d.m.Y') : null)
                    ->{$data['passed'] ? 'success' : 'warning'}()
                    ->send();
            });
    }

    public static function lend(callable $equipment): Action
    {
        return Action::make('lend')
            ->label('Ausleihen')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('gray')
            ->schema([
                Forms\Components\Select::make('user_id')->label('An')->options(fn () => static::members())->searchable()->required(),
                Forms\Components\DateTimePicker::make('due_at')->label('Zurück bis')->default(now()->addWeek()->setTime(18, 0))->required()
                    ->native(false)->displayFormat('d.m.Y H:i')->seconds(false)->timezone('Europe/Berlin'),
                Forms\Components\Textarea::make('notes')->label('Notiz')->rows(2),
            ])
            ->visible(fn ($record, $livewire) => $equipment($record, $livewire)->status === EquipmentStatus::Available)
            ->action(function (array $data, $record, $livewire, Action $action) use ($equipment) {
                static::attempt($action, fn () => app(LoanService::class)->lend(
                    $equipment($record, $livewire),
                    User::findOrFail($data['user_id']),
                    auth()->user(),
                    Carbon::parse($data['due_at']),
                    $data['notes'] ?? null,
                ));
                Notification::make()->title('Ausgeliehen')->success()->send();
            });
    }

    public static function reportDamage(callable $equipment): Action
    {
        return Action::make('damage')
            ->label('Schaden melden')
            ->icon('heroicon-o-wrench')
            ->color('danger')
            ->schema([Forms\Components\Textarea::make('description')->label('Was ist kaputt?')->required()->rows(3)])
            ->visible(fn ($record, $livewire) => $equipment($record, $livewire)->status !== EquipmentStatus::Retired)
            ->action(function (array $data, $record, $livewire) use ($equipment) {
                app(EquipmentService::class)->reportDamage($equipment($record, $livewire), auth()->user(), $data['description']);
                Notification::make()->title('Schaden gemeldet – Gerät ist als defekt gekennzeichnet')->warning()->send();
            });
    }

    public static function logUse(callable $equipment): Action
    {
        return Action::make('logUse')
            ->label('Einsatz / Übung eintragen')
            ->icon('heroicon-o-fire')
            ->color('gray')
            ->schema([
                Forms\Components\Select::make('type')->label('Art')
                    ->options(array_intersect_key(EquipmentEvent::TYPES, array_flip(EquipmentEvent::MANUAL_TYPES)))
                    ->default('operation')->required(),
                Forms\Components\DateTimePicker::make('occurred_at')->label('Wann')->default(now())->maxDate(now()->endOfDay())->required()
                    ->native(false)->displayFormat('d.m.Y H:i')->seconds(false)->timezone('Europe/Berlin'),
                Forms\Components\Textarea::make('body')->label('Beschreibung')->rows(2)->placeholder('z. B. Einsatz 2026-117, Brand Scheune'),
            ])
            ->action(function (array $data, $record, $livewire) use ($equipment) {
                app(EquipmentService::class)->log($equipment($record, $livewire), auth()->user(), $data['type'], $data['body'] ?? null, Carbon::parse($data['occurred_at']));
                Notification::make()->title('Eingetragen')->success()->send();
            });
    }

    public static function retire(callable $equipment): Action
    {
        return Action::make('retire')
            ->label('Aussondern')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Das Gerät wird aus dem Bestand genommen. Die Daten und der Verlauf bleiben erhalten.')
            ->schema([Forms\Components\Textarea::make('note')->label('Grund')->rows(2)])
            ->visible(fn ($record, $livewire) => ! in_array($equipment($record, $livewire)->status, [EquipmentStatus::Retired, EquipmentStatus::Loaned], true))
            ->action(function (array $data, $record, $livewire) use ($equipment) {
                app(EquipmentService::class)->setStatus($equipment($record, $livewire), auth()->user(), EquipmentStatus::Retired, $data['note'] ?? null);
                Notification::make()->title('Ausgesondert')->success()->send();
            });
    }

    public static function reactivate(callable $equipment): Action
    {
        return Action::make('reactivate')
            ->label('Wieder in Dienst')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn ($record, $livewire) => $equipment($record, $livewire)->status === EquipmentStatus::Retired)
            ->action(function ($record, $livewire) use ($equipment) {
                app(EquipmentService::class)->setStatus($equipment($record, $livewire), auth()->user(), EquipmentStatus::Available);
                Notification::make()->title('Wieder in Dienst')->success()->send();
            });
    }

    /** Active Kameraden for borrower selections */
    public static function members(): array
    {
        return User::where('status', MemberStatus::Active->value)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Runs a service call and shows its validation message instead of failing */
    public static function attempt(Action $action, callable $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
            $action->halt();
        }

        return null;
    }

    /** URL a scan of the label opens (via /i/{code}) */
    public static function scanUrl(Equipment $equipment): string
    {
        return rtrim(config('app.url'), '/').'/i/'.rawurlencode($equipment->code);
    }

    public static function labelHtml(Equipment $equipment): string
    {
        $svg = QrCode::svg(static::scanUrl($equipment));

        return '<div style="text-align:center">'
            .'<img alt="QR-Code" style="margin:0 auto;width:15rem;max-width:100%" src="data:image/svg+xml;base64,'.base64_encode($svg).'">'
            .'<p style="font-weight:600;margin-top:.5rem">'.e($equipment->name).'</p>'
            .'<p style="font-family:ui-monospace,monospace;font-size:.85rem">'.e($equipment->code).'</p>'
            .'</div>';
    }
}
