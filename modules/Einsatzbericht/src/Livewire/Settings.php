<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Support\Arr;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;
use Modules\Einsatzbericht\src\Services\ReportService;

/** Wehrführung: mandatory fields of a report, and the DIVERA 24/7 webhook */
class Settings extends Component
{
    /** @var list<string> */
    public array $required = [];

    public bool $diveraEnabled = false;

    /** @var array<string, string> */
    public array $mapping = [];

    public string $filters = '';

    /** Test alarm, as DIVERA would send it */
    public array $test = ['keyword' => 'FEU 1 – Test-Alarm', 'message' => 'Test-Alarm aus openwehr, bitte ignorieren', 'address' => '', 'latitude' => '', 'longitude' => '', 'probe' => false, 'via_url' => false];

    public ?string $testResult = null;

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(ReportService::canRelease(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->required = ReportService::required();
        $this->diveraEnabled = DiveraWebhook::enabled();
        $this->mapping = DiveraWebhook::mapping();
        $this->filters = implode("\n", DiveraWebhook::filters());
    }

    public function save(): void
    {
        ReportService::setRequired($this->required);
        $this->required = ReportService::required();
        $this->flash = 'Pflichtfelder gespeichert.';
    }

    public function saveDivera(): void
    {
        $this->validate(['mapping.*' => ['nullable', 'string', 'max:255'], 'filters' => ['nullable', 'string', 'max:2000']]);
        DiveraWebhook::setEnabled($this->diveraEnabled);
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

    public function render()
    {
        $requests = DiveraRequest::with('report')->latest('id')->limit(20)->get();
        $latest = $requests->first();

        return view('einsatzbericht::livewire.settings', [
            'fields' => Report::REQUIRABLE,
            'url' => DiveraWebhook::url(),
            'requests' => $requests,
            // What the last request contained, to set the mapping by
            'latestFields' => $latest ? Arr::dot(DiveraWebhook::parse($latest)) : [],
        ]);
    }
}
