<?php

namespace Modules\Maengelmanagement\src\Filament\Resources\DefectResource\Pages;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource;
use Modules\Maengelmanagement\src\Services\DefectService;

class EditDefect extends EditRecord
{
    protected static string $resource = DefectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('comment')
                ->label('Kommentieren')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->schema([Forms\Components\Textarea::make('body')->label('Kommentar')->required()->rows(3)->maxLength(3000)])
                ->action(function (array $data) {
                    app(DefectService::class)->comment($this->record, auth()->user(), $data['body']);
                    $this->record->refresh();
                    Notification::make()->title('Kommentar gespeichert.')->success()->send();
                }),
            Actions\Action::make('app')
                ->label('Ansicht der Mitglieder')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('maengelmanagement.show', $this->record))
                ->openUrlInNewTab(),
        ];
    }

    /** Every change goes through the service so it lands in the history and the right people are told */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(DefectService::class);
        $by = auth()->user();

        $service->setUrgency($record, $by, Urgency::from($data['urgency']));
        $service->assign($record, $by, filled($data['assigned_to'] ?? null) ? User::find($data['assigned_to']) : null);
        $service->setStatus($record, $by, DefectStatus::from($data['status']), $data['note'] ?? null);

        return $record->refresh();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['status'] = $data['status'] instanceof DefectStatus ? $data['status']->value : $data['status'];
        $data['urgency'] = $data['urgency'] instanceof Urgency ? $data['urgency']->value : $data['urgency'];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
