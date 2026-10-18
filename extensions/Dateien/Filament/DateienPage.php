<?php

namespace Extensions\Dateien\Filament;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\Setting;
use App\Models\User;
use App\Support\FileResponse;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Extensions\Dateien\Services\Maintenance;
use Extensions\Locations\Models\Location;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

/**
 * @property-read Schema $form
 */
class DateienPage extends Page implements HasForms, ModuleArea
{
    use InteractsWithForms;
    use IsModuleArea;

    protected static ?string $slug = 'dateien';

    protected static ?string $title = 'Dateien verwalten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|\UnitEnum|null $navigationGroup = 'Dateien';

    protected string $view = 'dateien::filament.dateien';

    public ?array $data = [];

    #[Locked]
    public ?int $groupId = null;

    public string $groupName = '';

    public ?int $groupLocationId = null;

    public ?int $memberId = null;

    public bool $memberIsManager = false;

    public string $spaceName = '';

    public ?int $spaceLocationId = null;

    public ?int $quotaMb = null;

    public static function allows(User $user): bool
    {
        return GroupService::canManage($user);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill([
            'max_upload_kb' => FileService::maxUploadKb(),
            'extensions' => FileService::allowedExtensions(),
            'retention_days' => FileService::retentionDays(),
            'quota_warning_percent' => FileService::quotaWarningPercent(),
            'audit_retention_days' => FileService::auditRetentionDays(),
            'verify_downloads' => FileService::verifyDownloads(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Forms\Components\TextInput::make('max_upload_kb')->label('Maximale Dateigröße (KiB)')
                ->numeric()->required()->minValue(1)->maxValue(FileService::maxUploadLimitKb())
                ->helperText('Obergrenze laut Server-Konfiguration: '.FileService::maxUploadLimitKb().' KiB (DATEIEN_MAX_UPLOAD_LIMIT_KB). PHP lässt derzeit höchstens '.ini_get('upload_max_filesize').' pro Datei und '.ini_get('post_max_size').' pro Anfrage zu; der Reverse-Proxy braucht ein passendes Limit.'),
            Forms\Components\CheckboxList::make('extensions')->label('Erlaubte Dateiendungen')
                ->options(array_combine(FileResponse::allowedExtensions(), FileResponse::allowedExtensions()))
                ->required()->minItems(1),
            Forms\Components\TextInput::make('retention_days')->label('Mindestaufbewahrung im Papierkorb (Tage)')
                ->numeric()->required()->minValue(0)->maxValue(3650)
                ->helperText('Keine automatische Löschung. Erst nach dieser Frist ist eine explizite endgültige Löschung möglich.'),
            Forms\Components\TextInput::make('quota_warning_percent')->label('Kontingent-Warnung ab (%)')
                ->numeric()->required()->minValue(1)->maxValue(100),
            Forms\Components\TextInput::make('audit_retention_days')->label('Aufbewahrung des Dateiprotokolls (Tage)')
                ->numeric()->required()->minValue(0)->maxValue(36500)
                ->helperText('0 = unbegrenzt. Ältere Einträge löscht der nächtliche Lauf dateien:prune-audit.'),
            Forms\Components\Toggle::make('verify_downloads')->label('Prüfsumme beim Herunterladen prüfen')
                ->helperText('Erkennt beschädigte Dateien vor der Auslieferung; kostet bei großen Dateien etwas Zeit.'),
        ]);
    }

    public function saveSettings(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        $state = $this->form->getState();
        $this->validate([
            'data.max_upload_kb' => ['required', 'integer', 'min:1', 'max:'.FileService::maxUploadLimitKb()],
            'data.extensions' => ['required', 'array', 'min:1'],
            'data.extensions.*' => ['required', 'string', 'in:'.implode(',', FileResponse::allowedExtensions())],
            'data.retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'data.quota_warning_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'data.audit_retention_days' => ['required', 'integer', 'min:0', 'max:36500'],
            'data.verify_downloads' => ['boolean'],
        ]);
        Setting::set('dateien.max_upload_kb', (int) $state['max_upload_kb']);
        Setting::set('dateien.extensions', json_encode(array_values($state['extensions']), JSON_THROW_ON_ERROR));
        Setting::set('dateien.retention_days', (int) $state['retention_days']);
        Setting::set('dateien.quota_warning_percent', (int) $state['quota_warning_percent']);
        Setting::set('dateien.audit_retention_days', (int) $state['audit_retention_days']);
        Setting::set('dateien.verify_downloads', ! empty($state['verify_downloads']) ? '1' : '0');
        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    public function editGroup(int $id): void
    {
        $this->authorizeManager();
        $group = app(GroupService::class)->groups(auth()->user())->firstWhere('id', $id);
        abort_unless($group !== null, 403);
        $this->groupId = $group->id;
        $this->groupName = $group->name;
        $this->groupLocationId = $group->ext_location_id;
    }

    public function newGroup(): void
    {
        $this->authorizeManager();
        $this->reset('groupId', 'groupName', 'groupLocationId', 'memberId', 'memberIsManager');
    }

    public function saveGroup(): void
    {
        $this->authorizeManager();
        $group = app(GroupService::class)->save(
            auth()->user(),
            $this->groupId ? FileGroup::findOrFail($this->groupId) : null,
            $this->groupName,
            $this->groupLocationId,
        );
        $this->groupId = $group->id;
        Notification::make()->title('Dateigruppe gespeichert')->success()->send();
    }

    public function saveMember(): void
    {
        $this->authorizeManager();
        $this->validate(['memberId' => ['required', 'integer']]);
        app(GroupService::class)->setMember(auth()->user(), FileGroup::findOrFail($this->groupId), $this->memberId, $this->memberIsManager);
        $this->reset('memberId', 'memberIsManager');
    }

    public function removeMember(int $id): void
    {
        $this->authorizeManager();
        app(GroupService::class)->setMember(auth()->user(), FileGroup::findOrFail($this->groupId), $id, null);
    }

    public function createSpace(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        $this->validate([
            'spaceName' => ['required', 'string', 'max:150'],
            'quotaMb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'spaceLocationId' => ['nullable', 'integer', 'exists:x_locations,id'],
        ]);
        app(FileService::class)->createSpace(auth()->user(), [
            'name' => $this->spaceName,
            'ext_location_id' => config('locations.enabled') ? $this->spaceLocationId : null,
            'quota_bytes' => $this->quotaMb === null ? null : $this->quotaMb * 1024 * 1024,
        ]);
        $this->reset('spaceName', 'spaceLocationId', 'quotaMb');
        Notification::make()->title('Ablage erstellt')->success()->send();
    }

    public function retryDeletions(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        app(FileService::class)->retryPendingDeletions(auth()->user());
        Notification::make()->title('Ausstehende Dateilöschungen erneut versucht')->success()->send();
    }

    public function runReconcile(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        $report = app(Maintenance::class)->reconcile();
        $problems = Maintenance::problems($report);
        Notification::make()->title($problems === 0 ? 'Speicherprüfung ohne Befund' : 'Speicherprüfung: '.$problems.' Auffälligkeiten')
            ->{$problems === 0 ? 'success' : 'warning'}()->send();
    }

    public function deleteOrphans(): void
    {
        $this->authorizeManager();
        $result = app(Maintenance::class)->deleteOrphans(auth()->user());
        Notification::make()->title($result['orphans'].' verwaiste Dateien und '.$result['staging'].' abgebrochene Uploads entfernt')
            ->{$result['failed'] === 0 ? 'success' : 'warning'}()->send();
    }

    protected function getViewData(): array
    {
        $this->authorizeManager();
        $groups = app(GroupService::class)->groups(auth()->user());
        $group = $groups->firstWhere('id', $this->groupId);

        return [
            'groups' => $groups,
            'selectedGroup' => $group,
            'members' => $group ? $group->members()->get() : collect(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'locations' => config('locations.enabled') && auth()->user()->is_admin ? Location::orderBy('name')->get() : collect(),
            'report' => auth()->user()->is_admin ? app(Maintenance::class)->lastReport() : null,
            'pending' => auth()->user()->is_admin ? app(Maintenance::class)->pendingDeletions() : null,
        ];
    }

    private function authorizeManager(): void
    {
        abort_unless(self::canAccess(), 403);
    }
}
