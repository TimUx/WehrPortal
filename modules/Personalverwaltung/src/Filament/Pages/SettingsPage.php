<?php

namespace Modules\Personalverwaltung\src\Filament\Pages;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\Group;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** /admin/personalverwaltung/einstellungen – Personalverwalter: which personal data is kept, self-service, Dienstausweis, Dienstzeit */
class SettingsPage extends Page implements ModuleArea
{
    use IsModuleArea;

    protected static ?string $slug = 'personalverwaltung/einstellungen';

    protected static ?string $title = 'Einstellungen Personalverwaltung';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static string|\UnitEnum|null $navigationGroup = 'Personalverwaltung';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'personalverwaltung::filament.pages.settings';

    public array $fields = [];

    public ?string $flash = null;

    /** How Jugendfeuerwehr time counts towards the Dienstzeit */
    public string $youthGroupId = '';

    public string $youthRule = 'full';

    public int $youthAge = 16;

    public static function allows(User $user): bool
    {
        return PersonnelService::canConfigure($user);
    }

    public function mount(): void
    {
        $this->fields = PersonnelService::settings();
        $rules = PersonnelService::serviceRules();
        [$this->youthGroupId, $this->youthRule, $this->youthAge] = [(string) ($rules['youth_group_id'] ?? ''), $rules['youth_rule'], $rules['youth_age']];
    }

    public function saveRules(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->saveServiceRules(auth()->user(), $this->youthGroupId !== '' ? (int) $this->youthGroupId : null, $this->youthRule, $this->youthAge);
        } catch (ValidationException $e) {
            $this->addError('rules', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Regeln für die Dienstzeit gespeichert.';
    }

    public function save(PersonnelService $personnel): void
    {
        $this->resetErrorBag();
        try {
            $personnel->saveSettings(auth()->user(), $this->fields);
        } catch (ValidationException $e) {
            $this->addError('settings', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Gespeichert.';
    }

    protected function getViewData(): array
    {
        return ['groups' => Group::query()
            ->when(Group::locationsAvailable(), fn ($query) => $query->with('location'))
            ->orderBy('sort_order')->orderBy('name')->get()];
    }
}
