<?php

namespace App\Filament\Resources\FeedbackThreadResource\Pages;

use App\Filament\Resources\FeedbackThreadResource;
use App\Models\FeedbackThread;
use App\Services\FeedbackService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFeedbackThread extends ViewRecord
{
    protected static string $resource = FeedbackThreadResource::class;

    public function getTitle(): string
    {
        return $this->record->subject;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->unread_for_admin) {
            $this->record->update(['unread_for_admin' => false]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->label('Antworten')
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    Forms\Components\Textarea::make('body')
                        ->label('Antwort')
                        ->helperText('Geht per E-Mail an '.$this->record->user->name.' und ist im Profil nachzulesen.')
                        ->required()
                        ->rows(5)
                        ->maxLength(5000),
                    Forms\Components\Toggle::make('close')
                        ->label('Danach als erledigt markieren'),
                ])
                ->action(function (array $data, FeedbackService $service) {
                    $service->reply($this->record, auth()->user(), $data['body'], fromAdmin: true);
                    if ($data['close'] ?? false) {
                        $service->setStatus($this->record, FeedbackThread::STATUS_CLOSED);
                    }
                    $this->record->refresh();
                    Notification::make()->title('Antwort gesendet')->success()->send();
                }),

            Actions\Action::make('close')
                ->label('Als erledigt markieren')
                ->color('gray')
                ->visible(fn () => ! $this->record->isClosed())
                ->action(function (FeedbackService $service) {
                    $service->setStatus($this->record, FeedbackThread::STATUS_CLOSED);
                    $this->record->refresh();
                }),

            Actions\Action::make('reopen')
                ->label('Wieder öffnen')
                ->color('gray')
                ->visible(fn () => $this->record->isClosed())
                ->action(function (FeedbackService $service) {
                    $service->setStatus($this->record, FeedbackThread::STATUS_OPEN);
                    $this->record->refresh();
                }),
        ];
    }
}
