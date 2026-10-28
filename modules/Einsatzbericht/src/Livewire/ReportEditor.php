<?php

namespace Modules\Einsatzbericht\src\Livewire;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AddressSuggestions;
use App\Services\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Models\Material;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Support\ReportDetails;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Wasserkarte\src\Services\WaterService;

/** One Einsatzbericht: all blocks of the paper form, vehicles with crew and Stärke, closing and release */
class ReportEditor extends Component
{
    #[Locked]
    public Report $report;

    /** @var array<string, mixed> the report fields as typed (times as Y-m-d\TH:i Berlin) */
    public array $form = [];

    /** @var array<int, array<string, string>> per vehicle: its four times */
    public array $vehicleTimes = [];

    /** @var array<int, array{user: string, function: string, ba: bool}> per vehicle: the Kamerad being added */
    public array $newCrew = [];

    public string $newVehicle = '';

    /** @var array{material: string, quantity: string, free: bool, name: string, unit: string, category: string} */
    public array $newMaterial = ['material' => '', 'quantity' => '', 'free' => false, 'name' => '', 'unit' => 'stueck', 'category' => 'sonstiges'];

    public ?string $flash = null;

    /** Signature of the Einsatzleiter (closing) or the Wehrführung (release), as data URL */
    public string $signature = '';

    public string $sideLabel = '';

    /** @var list<array{id: string, main: string, secondary: string}> Google suggestions for the address being typed */
    public array $addressSuggestions = [];

    /** Groups the typing and the chosen place into one Google session (billed once) */
    public string $placesSession = '';

    /** @var ?array{address: string, lat: ?float, lng: ?float} the chosen suggestion, so its point is saved without geocoding again */
    public ?array $pickedAddress = null;

    private const TEXT = ['dispatch_number', 'keyword', 'address', 'object', 'affected_parties', 'category', 'situation', 'measures', 'equipment_used',
        'property_damage', 'environmental_damage', 'neighbor_brigades', 'other_parties', 'handover', 'fire_watch_notes'];

    public function mount(): void
    {
        abort_unless(ReportService::canLeadReport(auth()->user(), $this->report), 403);
        $this->fillForm();
        $this->placesSession = (string) Str::uuid();
    }

    /** Typing the address: suggestions from Google (when a key is set) */
    public function updated(string $name): void
    {
        if ($name !== 'form.address') {
            return;
        }
        if ($this->pickedAddress && $this->pickedAddress['address'] !== $this->form['address']) {
            $this->pickedAddress = null;
        }
        $this->addressSuggestions = ReportService::canEdit(auth()->user(), $this->report)
            ? app(AddressSuggestions::class)->suggest((string) $this->form['address'], $this->placesSession, $this->near())
            : [];
    }

    /** A suggestion was tapped: its address, the point on the map and, for a place, its name as Objekt */
    public function chooseAddress(string $placeId, AddressSuggestions $suggestions): void
    {
        $place = $suggestions->details($placeId, $this->placesSession);
        $this->addressSuggestions = [];
        $this->placesSession = (string) Str::uuid();
        if (! $place) {
            return;
        }

        $this->form['address'] = mb_substr($place['address'], 0, 255);
        if ($place['name'] && trim((string) ($this->form['object'] ?? '')) === '') {
            $this->form['object'] = mb_substr($place['name'], 0, 255);
        }
        $this->pickedAddress = ['address' => $this->form['address'], 'lat' => $place['lat'], 'lng' => $place['lng']];
        if ($place['lat'] !== null) {
            $this->dispatch('einsatz-location', lat: $place['lat'], lng: $place['lng']);
        }
    }

    /** The middle of the earlier Einsätze: suggestions near the Wehr first */
    private function near(): ?array
    {
        $recent = Report::whereNotNull('latitude')->whereNotNull('longitude')->latest('alarmed_at')->limit(50)->toBase()->select(['latitude', 'longitude']);
        $point = DB::query()->fromSub($recent, 'recent')->selectRaw('avg(latitude) as lat, avg(longitude) as lng')->first();

        return $point && $point->lat !== null ? ['lat' => (float) $point->lat, 'lng' => (float) $point->lng] : null;
    }

    private function fillForm(): void
    {
        $r = $this->report->refresh();
        $this->form = [
            'number' => (string) $r->number,
            ...collect(self::TEXT)->mapWithKeys(fn ($f) => [$f => (string) $r->{$f}])->all(),
            ...collect(array_keys(Report::TIMES))->mapWithKeys(fn ($f) => [$f => self::local($r->{$f})])->all(),
            'rescued' => (string) $r->rescued, 'injured' => (string) $r->injured, 'dead' => (string) $r->dead,
            'police' => $r->police, 'ems' => $r->ems, 'fire_watch' => $r->fire_watch,
            'commander_id' => (string) ($r->commander_id ?? ''),
            'details' => $r->details ?? [],
        ];
        $vehicles = $r->vehicles()->get();
        $this->vehicleTimes = $vehicles->mapWithKeys(fn (ReportVehicle $v) => [$v->id => collect(array_keys(ReportVehicle::TIMES))
            ->mapWithKeys(fn ($f) => [$f => self::local($v->{$f})])->all()])->all();
        // The add row of each vehicle starts with "Einsatzkraft", so the shown function is the one saved
        $this->newCrew = $vehicles->mapWithKeys(fn (ReportVehicle $v) => [$v->id => $this->newCrew[$v->id] ?? self::blankCrew()])->all();
    }

    public function save(ReportService $reports): void
    {
        if ($this->persist($reports)) {
            $this->announceSaved($reports);
        }
    }

    /** Validates and saves the fields; false when something was refused */
    private function persist(ReportService $reports): bool
    {
        $this->validateAndJump([
            'form.number' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{3,}(?:-\d+)?$/'],
            'form.keyword' => ['nullable', 'string', 'max:150'],
            'form.dispatch_number' => ['nullable', 'string', 'max:50'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.object' => ['nullable', 'string', 'max:255'],
            'form.rescued' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'form.injured' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'form.dead' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'form.commander_id' => ['nullable', 'exists:users,id'],
            ...collect(array_keys(Report::TIMES))->mapWithKeys(fn ($f) => ["form.$f" => ['nullable', 'date_format:Y-m-d\TH:i']])->all(),
        ], [
            '*.date_format' => 'Bitte Datum und Uhrzeit angeben.',
            '*.integer' => 'Bitte eine Zahl angeben.',
            'form.number.regex' => 'Die laufende Nummer muss das Format JJJJ/001 haben.',
        ]);
        if ($this->getErrorBag()->isNotEmpty()) {
            return false;
        }

        $data = [
            'number' => trim($this->form['number']),
            ...collect(self::TEXT)->mapWithKeys(fn ($f) => [$f => $this->form[$f] ?? null])->all(),
            ...collect(array_keys(Report::TIMES))->mapWithKeys(fn ($f) => [$f => self::parse($this->form[$f] ?? null)])->all(),
            'rescued' => (int) ($this->form['rescued'] ?: 0), 'injured' => (int) ($this->form['injured'] ?: 0), 'dead' => (int) ($this->form['dead'] ?: 0),
            'police' => (bool) $this->form['police'], 'ems' => (bool) $this->form['ems'], 'fire_watch' => (bool) $this->form['fire_watch'],
            'commander_id' => $this->form['commander_id'] !== '' ? (int) $this->form['commander_id'] : null,
            'details' => (array) ($this->form['details'] ?? []),
            // A chosen suggestion brings its point along; otherwise a new address is geocoded
            ...($this->pickedAddress && $this->pickedAddress['address'] === $this->form['address'] && $this->pickedAddress['lat'] !== null
                ? ['latitude' => $this->pickedAddress['lat'], 'longitude' => $this->pickedAddress['lng']] : []),
        ];

        return $this->attempt(fn () => $reports->update(auth()->user(), $this->report, $data));
    }

    /** "Einsatz starten": saves and makes the draft the current Einsatz; missing fields don't stop it */
    public function start(ReportService $reports): void
    {
        if (! $this->persist($reports)) {
            return;
        }

        if ($this->attempt(fn () => $reports->start(auth()->user(), $this->report))) {
            $this->report->refresh();
            $this->dispatch('report-saved', message: 'Einsatz gestartet. Kameraden sehen ihn unter „Laufende Einsätze“ und schicken Lagemeldungen.', field: null, warn: false);
        }
    }

    /** "Gespeichert" next to the buttons; with mandatory fields still empty, jump to the first one */
    private function announceSaved(ReportService $reports): void
    {
        $missing = $reports->missingFields($this->report->refresh());
        $labels = array_map(fn ($field) => Report::REQUIRABLE[$field], $missing);

        $saved = $this->report->status === 'draft' ? 'Entwurf gespeichert.' : 'Gespeichert.';
        $this->dispatch('report-saved',
            message: $missing ? $saved.' Noch offen: '.implode(', ', $labels).'.' : $saved,
            field: $missing[0] ?? null,
            warn: $missing !== [],
        );
    }

    /** Validates; on an error the page jumps to the first invalid field */
    private function validateAndJump(array $rules, array $messages): void
    {
        $this->resetErrorBag();

        try {
            $this->validate($rules, $messages);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $field = str_replace('form.', '', (string) array_key_first($e->errors()));
            $this->dispatch('report-saved', message: 'Nicht gespeichert – bitte die markierte Eingabe prüfen.', field: $field, warn: true);
        }
    }

    public function addVehicle(ReportService $reports): void
    {
        $id = $this->newVehicle === 'standby' ? null : (int) $this->newVehicle;
        if ($this->newVehicle === '') {
            return;
        }

        $this->attempt(fn () => $reports->addVehicle(auth()->user(), $this->report, $id));
        $this->newVehicle = '';
        $this->fillForm();
    }

    public function removeVehicle(int $vehicleId, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->removeVehicle(auth()->user(), $this->vehicle($vehicleId)));
        $this->fillForm();
    }

    public function saveVehicleTimes(int $vehicleId, ReportService $reports): void
    {
        $times = collect($this->vehicleTimes[$vehicleId] ?? [])->map(fn ($v) => self::parse($v))->all();

        if ($this->attempt(fn () => $reports->setVehicleTimes(auth()->user(), $this->vehicle($vehicleId), $times))) {
            $this->flash = 'Zeiten gespeichert.';
        }
    }

    public function addCrew(int $vehicleId, ReportService $reports): void
    {
        $entry = $this->newCrew[$vehicleId] ?? [];
        if (empty($entry['user'])) {
            return;
        }

        $this->attempt(fn () => $reports->assign(auth()->user(), $this->vehicle($vehicleId), User::findOrFail((int) $entry['user']),
            $entry['function'] ?? 'einsatzkraft', (bool) ($entry['ba'] ?? false)));
        $this->newCrew[$vehicleId] = self::blankCrew();
    }

    public function updateCrew(int $crewId, string $function, bool $breathingApparatus, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->updateCrew(auth()->user(), $this->crew($crewId), $function, $breathingApparatus));
    }

    public function removeCrew(int $crewId, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->removeCrew(auth()->user(), $this->crew($crewId)));
    }

    /** From the map: the pin was moved or the map tapped */
    public function setLocation($lat, $lng, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->setLocation(auth()->user(), $this->report, is_numeric($lat) ? (float) $lat : null, is_numeric($lng) ? (float) $lng : null));
    }

    public function clearLocation(ReportService $reports): void
    {
        $this->attempt(fn () => $reports->setLocation(auth()->user(), $this->report, null, null));
    }

    public function addMaterial(ReportService $reports): void
    {
        $m = $this->newMaterial;
        $quantity = (float) str_replace(',', '.', (string) $m['quantity']);

        if ($this->attempt(fn () => $reports->addMaterial(auth()->user(), $this->report, $m['free'] ? null : ((int) $m['material'] ?: null), $quantity,
            $m['free'] ? $m['name'] : null, $m['free'] ? $m['unit'] : null, $m['free'] ? $m['category'] : null))) {
            $this->newMaterial = [...$this->newMaterial, 'material' => '', 'quantity' => '', 'name' => ''];
        }
    }

    public function updateMaterial(int $itemId, $quantity, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->updateMaterial(auth()->user(), $this->report->materials()->findOrFail($itemId), (float) str_replace(',', '.', (string) $quantity)));
    }

    public function removeMaterial(int $itemId, ReportService $reports): void
    {
        $this->attempt(fn () => $reports->removeMaterial(auth()->user(), $this->report->materials()->findOrFail($itemId)));
    }

    public function enableExternal(ExternalAccess $access): void
    {
        if ($this->attempt(fn () => $access->enable(auth()->user(), $this->report))) {
            $this->flash = 'QR-Zugang erzeugt. Ein älterer Code gilt nicht mehr.';
        }
    }

    public function revokeExternal(ExternalAccess $access): void
    {
        if ($this->attempt(fn () => $access->revoke(auth()->user(), $this->report))) {
            $this->flash = 'QR-Zugang gesperrt.';
        }
    }

    public function close(ReportService $reports): void
    {
        $this->save($reports);
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($this->attempt(fn () => $reports->close(auth()->user(), $this->report, $this->signature ?: null))) {
            $this->signature = '';
            $this->flash = 'Bericht abgeschlossen. Die Wehrführung gibt ihn frei.';
            $this->dispatch('report-saved', message: $this->flash, field: null, warn: false);
        } elseif ($field = $reports->missingFields($this->report)[0] ?? null) {
            $this->dispatch('report-saved', message: 'Nicht abgeschlossen – es fehlen noch Pflichtfelder.', field: $field, warn: true);
        }
    }

    public function release(ReportService $reports): void
    {
        if ($this->attempt(fn () => $reports->release(auth()->user(), $this->report, $this->signature ?: null))) {
            $this->flash = $this->report->released_signature ? 'Bericht freigegeben und signiert.' : 'Bericht freigegeben.';
            $this->signature = '';
        }
    }

    public function reopen(ReportService $reports): void
    {
        if ($this->attempt(fn () => $reports->reopen(auth()->user(), $this->report))) {
            $this->flash = 'Bericht ist wieder offen (aktuell).';
        }
    }

    /** A Nebenbericht to this (Haupt-)Bericht, e.g. for another Zug or Abschnitt */
    public function createSide(ReportService $reports)
    {
        $side = null;
        if ($this->attempt(function () use ($reports, &$side) {
            $side = $reports->createSide(auth()->user(), $this->report, $this->sideLabel);
        })) {
            return redirect()->route('einsatzbericht.show', $side);
        }

        return null;
    }

    public function delete(ReportService $reports)
    {
        if ($this->attempt(fn () => $reports->delete(auth()->user(), $this->report))) {
            return redirect()->route('einsatzbericht.index');
        }

        return null;
    }

    public function render()
    {
        $report = $this->report->load(['vehicles.crew.user', 'vehicles.vehicle', 'commander', 'closer', 'releaser', 'materials']);
        $user = auth()->user();
        $assigned = $report->vehicles->flatMap->crew->pluck('user_id')->all();
        $reports = app(ReportService::class);
        $crewOptions = $report->vehicles->mapWithKeys(fn (ReportVehicle $reportVehicle) => [
            $reportVehicle->id => $reports->eligibleCrew($reportVehicle),
        ]);

        return view('einsatzbericht::livewire.report-editor', [
            'canEdit' => ReportService::canEdit($user, $report),
            'canRelease' => ReportService::canReleaseReport($user, $report),
            'required' => ReportService::required(),
            'missing' => in_array($report->status, Report::OPEN, true) ? app(ReportService::class)->missing($report) : [],
            'canSuggest' => app(AddressSuggestions::class)->available(),
            'detailFields' => ReportDetails::fields($this->form['category'] ?? null),
            'waterOptions' => $this->waterOptions(),
            'sides' => $report->isSide() ? collect() : $report->sides()->with('vehicles.crew')->get(),
            'parentReport' => $report->parent,
            'seal' => $report->status === 'released' ? ReportService::sealIntact($report) : null,
            'strength' => ReportService::reportStrength($report),
            'crewOptions' => $crewOptions,
            'vehicleOptions' => Vehicle::active()->whereNotIn('id', $report->vehicles->pluck('vehicle_id')->filter())->pluck('name', 'id'),
            'hasStandby' => $report->vehicles->contains('is_standby', true),
            'externalUnits' => $report->externalUnits()->with('statuses')->get(),
            'externalQr' => ExternalAccess::qr($report, 220),
            'externalUrl' => ExternalAccess::url($report),
            'catalog' => Material::where('is_active', true)->orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category'),
            'people' => User::active()->orderBy('name')->get(['id', 'name']),
            'assigned' => $assigned,
            // Changes with name and time; after the release they are the correction history
            'history' => ActivityLog::with(['user', 'impersonator'])
                ->where(fn ($q) => $q->where(fn ($r) => $r->where('subject_type', Report::class)->where('subject_id', $report->id))
                    ->orWhere(fn ($v) => $v->where('subject_type', ReportVehicle::class)->whereIn('subject_id', $report->vehicles->pluck('id')))
                    ->orWhere(fn ($c) => $c->where('subject_type', CrewMember::class)->whereIn('subject_id', $report->vehicles->flatMap->crew->pluck('id'))))
                ->latest('id')->limit(30)->get(),
        ]);
    }

    /** Wasserentnahmestellen near the Einsatzstelle (Wasserkarte), for the Zusatzangaben of a Brand */
    private function waterOptions(): array
    {
        if (! app(ModuleRegistry::class)->isActive('wasserkarte') || ! $this->report->latitude || ! $this->report->longitude) {
            return [];
        }

        return app(WaterService::class)->nearest($this->report->latitude, $this->report->longitude, 8)
            ->map(fn ($row) => $row['source']->label().' ('.$row['meters'].' m)')->values()->all();
    }

    private function vehicle(int $id): ReportVehicle
    {
        return $this->report->vehicles()->findOrFail($id);
    }

    private function crew(int $id): CrewMember
    {
        return $this->report->crew()->findOrFail($id);
    }

    private function attempt(callable $change): bool
    {
        $this->resetErrorBag('report');

        try {
            $change();
        } catch (ValidationException $e) {
            $this->addError('report', collect($e->errors())->flatten()->first());

            return false;
        }

        return true;
    }

    private static function blankCrew(): array
    {
        return ['user' => '', 'function' => 'einsatzkraft', 'ba' => false];
    }

    private static function local(?Carbon $time): string
    {
        return $time ? $time->copy()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i') : '';
    }

    private static function parse(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::createFromFormat('Y-m-d\TH:i', $value, 'Europe/Berlin') : null;
    }
}
