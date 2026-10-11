<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogResource\Pages;
use App\Listeners\LogSentMail;
use App\Models\ActivityLog;
use App\Models\MailLog;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;

/** Admin "Logs": every change in the app – also by the Kameraden –, logins and sent e-mails */
class LogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $slug = 'logs';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Logs';

    protected static ?string $modelLabel = 'Eintrag';

    protected static ?string $pluralModelLabel = 'Logs';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'impersonator', 'mailLog']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Zeit')
                    ->dateTime('d.m.Y H:i:s')
                    ->timezone('Europe/Berlin')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Wer')
                    ->placeholder('System')
                    ->searchable()
                    ->description(fn (ActivityLog $record) => $record->impersonator ? 'über Admin '.$record->impersonator->name : null),
                Tables\Columns\TextColumn::make('event')
                    ->label('Was')
                    ->badge()
                    ->formatStateUsing(fn (ActivityLog $record) => $record->eventLabel())
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'mail' => 'info',
                        'login', 'impersonate' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject_label')
                    ->label('Betrifft')
                    ->searchable()
                    ->wrap()
                    ->description(fn (ActivityLog $record) => $record->event === 'mail' ? $record->mailLog?->typeLabel() : ActivityLog::typeLabel($record->subject_type)),
                Tables\Columns\TextColumn::make('changes')
                    ->label('Änderungen')
                    ->state(fn (ActivityLog $record) => $record->changesSummary())
                    ->wrap()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('period')
                    ->label('Zeitraum')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Von')->native(false)->displayFormat('d.m.Y'),
                        Forms\Components\DatePicker::make('until')->label('Bis')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, 'Europe/Berlin')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $until) => $q->where('created_at', '<=', Carbon::parse($until, 'Europe/Berlin')->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'ab '.Carbon::parse($data['from'])->format('d.m.Y') : null,
                        ($data['until'] ?? null) ? 'bis '.Carbon::parse($data['until'])->format('d.m.Y') : null,
                    ])),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Was')
                    ->options(ActivityLog::EVENTS)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Bereich')
                    ->options(fn () => ActivityLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
                        ->mapWithKeys(fn ($type) => [$type => ActivityLog::typeLabel($type)])->sort()->all())
                    ->multiple(),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Wer')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->actions([
                Actions\Action::make('show')
                    ->label('Ansehen')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Ansehen')
                    ->modalHeading(fn (ActivityLog $record) => $record->eventLabel().($record->subject_label ? ': '.$record->subject_label : ''))
                    ->modalDescription(fn (ActivityLog $record) => $record->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s').' Uhr · '
                        .($record->user?->name ?? 'System').($record->impersonator ? ' (über Admin '.$record->impersonator->name.')' : '')
                        .($record->subject_type && $record->event !== 'mail' ? ' · '.ActivityLog::typeLabel($record->subject_type).' #'.$record->subject_id : ''))
                    ->modalContent(fn (ActivityLog $record) => new HtmlString($record->event === 'mail' ? static::mailHtml($record->mailLog) : static::changesHtml($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen')
                    ->modalWidth('4xl'),
                Actions\Action::make('resend')
                    ->label('Erneut senden')
                    ->icon('heroicon-o-arrow-path')
                    ->iconButton()
                    ->tooltip('E-Mail erneut senden')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ActivityLog $record) => 'Die E-Mail „'.$record->mailLog?->subject.'“ geht noch einmal unverändert an '.$record->mailLog?->to.'.')
                    ->visible(fn (ActivityLog $record) => $record->event === 'mail' && $record->mailLog && ! $record->mailLog->redacted && filled($record->mailLog->html.$record->mailLog->text))
                    ->action(fn (ActivityLog $record) => static::resend($record->mailLog)),
            ])
            ->emptyStateHeading('Noch keine Einträge');
    }

    private static function changesHtml(ActivityLog $record): string
    {
        if (empty($record->changes)) {
            return '<p style="color:#6b7280">Keine Einzelheiten.</p>';
        }

        $rows = collect($record->changes)->map(fn ($pair, $field) => '<tr><td style="padding:.25rem .75rem .25rem 0;font-weight:600;vertical-align:top">'.e($field).'</td>'
            .'<td style="padding:.25rem .75rem;color:#6b7280;vertical-align:top">'.e(ActivityLog::show($pair[0] ?? null)).'</td>'
            .'<td style="padding:.25rem 0;vertical-align:top">'.e(ActivityLog::show($pair[1] ?? null)).'</td></tr>')->implode('');

        return '<table style="width:100%;font-size:.875rem"><thead><tr style="text-align:left;color:#6b7280"><th>Feld</th><th style="padding:0 .75rem">vorher</th><th>nachher</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private static function mailHtml(?MailLog $mail): string
    {
        if (! $mail) {
            return '<p style="color:#6b7280">Die E-Mail ist nicht mehr gespeichert.</p>';
        }

        if ($mail->redacted) {
            return '<p style="color:#6b7280">An '.e($mail->to).'. Inhalt nicht gespeichert – die E-Mail enthält einen Login- oder Bestätigungslink.</p>';
        }

        // Sandboxed, so nothing in the mail runs in the admin
        return '<p style="margin-bottom:.5rem;color:#6b7280">An '.e($mail->to).' · '.e($mail->typeLabel()).'</p>'
            .'<iframe sandbox style="width:100%;height:60vh;border:1px solid #e5e7eb;border-radius:.5rem;background:#fff" srcdoc="'.e($mail->html ?: nl2br(e((string) $mail->text))).'"></iframe>';
    }

    /** Sends the stored mail again unchanged; the new mail is logged with a reference to this one */
    public static function resend(MailLog $record): void
    {
        try {
            Mail::send([], [], function ($message) use ($record) {
                $message->to(array_map('trim', explode(',', $record->to)))->subject((string) $record->subject);
                $record->html ? $message->html($record->html) : $message->text((string) $record->text);
                if ($record->html && $record->text) {
                    $message->text($record->text);
                }
                $message->getHeaders()->addTextHeader(LogSentMail::RESEND_HEADER, (string) $record->id);
            });
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Senden fehlgeschlagen')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('E-Mail erneut gesendet')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogs::route('/'),
        ];
    }
}
