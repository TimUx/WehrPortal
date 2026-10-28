<?php

namespace Modules\Einsatzbericht\src\Filament\Pages;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\Setting;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Filament\Pages\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;
use Modules\Einsatzbericht\src\Services\ReportService;

/** /admin/einsatzbericht/einstellungen – Wehrführung: mandatory fields of a report, counting, and the DIVERA 24/7 webhook */
class SettingsPage extends Page implements ModuleArea
{
    use IsModuleArea;

    protected static ?string $slug = 'einsatzbericht/einstellungen';

    protected static ?string $title = 'Einstellungen Einsatzbericht';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static string|\UnitEnum|null $navigationGroup = 'Einsatzbericht';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'einsatzbericht::filament.pages.settings';

    /** @var list<string> */
    public array $required = [];

    /** Jahresstatistik and Jahresbericht count only released reports */
    public bool $releasedOnly = true;

    public bool $diveraEnabled = false;

    public ?string $diveraLocationId = null;

    /** @var array<string, string> */
    public array $mapping = [];

    public string $filters = '';

    /** Test alarm, as DIVERA would send it */
    public array $test = ['keyword' => 'FEU 1 – Test-Alarm', 'message' => 'Test-Alarm aus WehrPortal, bitte ignorieren', 'address' => '', 'latitude' => '', 'longitude' => '', 'probe' => false, 'via_url' => false];

    public ?string $testResult = null;

    public ?string $flash = null;

    public static function allows(User $user): bool
    {
        return ReportService::canRelease($user);
    }

    public function mount(): void
    {
        $this->required = ReportService::required();
        $this->releasedOnly = ReportService::countedStatuses() === ['released'];
        $this->diveraEnabled = DiveraWebhook::enabled();
        $this->diveraLocationId = DiveraWebhook::configuredLocationId();
        if (config('locations.enabled') && $this->diveraLocationId === null) {
            $locations = $this->locationOptions();
            if ($locations->count() === 1) {
                $this->diveraLocationId = (string) $locations->keys()->first();
            }
        }
        $this->mapping = DiveraWebhook::mapping();
        $this->filters = implode("\n", DiveraWebhook::filters());
    }

    public function saveCounting(): void
    {
        Setting::set('einsatzbericht.released_only', $this->releasedOnly ? '1' : '0');
        $this->flash = $this->releasedOnly ? 'Statistik und Jahresbericht zählen nur freigegebene Berichte.' : 'Statistik und Jahresbericht zählen auch abgeschlossene, noch nicht freigegebene Berichte.';
    }

    public function save(): void
    {
        ReportService::setRequired($this->required);
        $this->required = ReportService::required();
        $this->flash = 'Pflichtfelder gespeichert.';
    }

    public function saveDivera(): void
    {
        $rules = ['mapping.*' => ['nullable', 'string', 'max:255'], 'filters' => ['nullable', 'string', 'max:2000']];
        if (config('locations.enabled')) {
            $rules['diveraLocationId'] = $this->diveraEnabled
                ? ['required', 'integer', Rule::exists('x_locations', 'id')->where('is_active', true)]
                : ['nullable', 'integer'];
        }
        $this->validate($rules, ['diveraLocationId.required' => 'Bitte einen aktiven Standort für den DIVERA-Webhook auswählen.']);
        DiveraWebhook::setEnabled($this->diveraEnabled);
        DiveraWebhook::setLocationId($this->diveraLocationId);
        DiveraWebhook::setMapping($this->mapping);
        DiveraWebhook::setFilters($this->filters);
        $this->mapping = DiveraWebhook::mapping();
        $this->flash = $this->diveraEnabled ? 'DIVERA-Webhook gespeichert und eingeschaltet.' : 'DIVERA-Webhook gespeichert, er ist ausgeschaltet.';
    }

    public function regenerateKey(): void
    {
        DiveraWebhook::regenerateKey();
        $this->flash = 'Neue Adresse erzeugt – bitte in DIVERA eintragen, die alte gilt nicht mehr.';
    }

    public function sendTestAlarm(DiveraWebhook $webhook): void
    {
        $this->validate([
            'test.keyword' => ['required', 'string', 'max:150'],
            'test.message' => ['nullable', 'string', 'max:2000'],
            'test.address' => ['nullable', 'string', 'max:255'],
            'test.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'test.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ], ['test.keyword.required' => 'Bitte ein Stichwort angeben.', '*.numeric' => 'Bitte eine Zahl angeben, z. B. 51,17 als 51.17.']);

        try {
            $entry = $webhook->testAlarm([
                'keyword' => $this->test['keyword'], 'message' => $this->test['message'], 'address' => $this->test['address'],
                'latitude' => $this->test['latitude'], 'longitude' => $this->test['longitude'], 'test' => (bool) $this->test['probe'],
            ], (bool) $this->test['via_url']);
        } catch (\Throwable $e) {
            $this->testResult = 'Test-Alarm fehlgeschlagen: '.$e->getMessage();

            return;
        }

        $this->testResult = 'Test-Alarm: '.$entry->statusLabel().($entry->message ? ' – '.$entry->message : '').'.';
    }

    public function resetMapping(): void
    {
        $this->mapping = DiveraWebhook::DEFAULT_MAPPING;
    }

    protected function getViewData(): array
    {
        $requests = DiveraRequest::with('report')->latest('id')->limit(20)->get();
        $latest = $requests->first();

        return [
            'fields' => Report::REQUIRABLE,
            'url' => DiveraWebhook::url(),
            'requests' => $requests,
            'locationsEnabled' => config('locations.enabled'),
            'locations' => $this->locationOptions(),
            // What the last request contained, to set the mapping by
            'latestFields' => $latest ? Arr::dot(DiveraWebhook::parse($latest)) : [],
        ];
    }

    /** @return Collection<int|string, string> */
    private function locationOptions(): Collection
    {
        if (! config('locations.enabled')) {
            return collect();
        }

        return Location::query()->active()->orderBy('name')->pluck('name', 'id');
    }
}
