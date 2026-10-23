<?php

namespace Extensions\Anwesenheit\Filament;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\Setting;
use App\Models\User;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendanceService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Entry of the managers into /admin (ModuleArea): the default mail recipients. The recipients are
 * instance-wide; a template can carry its own.
 */
class SettingsPage extends Page implements HasForms, ModuleArea
{
    use InteractsWithForms;
    use IsModuleArea;

    protected static ?string $slug = 'anwesenheit/einstellungen';

    protected static ?string $title = 'Anwesenheitslisten';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Anwesenheit';

    protected static ?int $navigationSort = 1;

    protected string $view = 'anwesenheit::filament.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function allows(User $user): bool
    {
        return AttendanceService::canManage($user);
    }

    public function mount(): void
    {
        $this->form->fill(['recipients' => AttendanceService::defaultRecipients()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Forms\Components\Textarea::make('recipients')->label('Standard-Empfänger der Listen')
                ->helperText('Instanzweite Einstellung, nur durch globale Admins änderbar. Gilt, wenn weder die Liste noch die Vorlage Empfänger nennt.')
                ->disabled(fn () => ! auth()->user()?->is_admin)
                ->rows(3)->maxLength(2000)
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    $all = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (count($all) !== count(AttendanceList::parseRecipients((string) $value))) {
                        $fail('Mindestens eine E-Mail-Adresse ist ungültig.');
                    }
                }),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->is_admin, 403);

        $recipients = trim((string) ($this->form->getState()['recipients'] ?? ''));
        $recipients === '' ? Setting::forget('anwesenheit.recipients') : Setting::set('anwesenheit.recipients', $recipients);

        Notification::make()->title('Gespeichert')->success()->send();
    }
}
