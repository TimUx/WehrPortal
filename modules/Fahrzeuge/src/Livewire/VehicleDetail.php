<?php

namespace Modules\Fahrzeuge\src\Livewire;

use App\Services\ModuleRegistry;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Document;
use Modules\Fahrzeuge\src\Models\Downtime;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Models\StatusEvent;
use Modules\Fahrzeuge\src\Services\FleetService;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Maengelmanagement\src\Models\Defect;

/**
 * The Fahrzeugprofil: data, Ausstattung, status and Werkstatt, deadlines with their documentation,
 * the Einsätze (Einsatzbericht), the bookings (Fahrzeugbuchung) and the Beladung (Ausrüstung).
 */
class VehicleDetail extends Component
{
    use WithFileUploads;

    public Vehicle $vehicle;

    public string $status = '';

    public string $statusNote = '';

    /** @var array{starts_at: string, ends_at: string, reason: string} */
    public array $downtime = [];

    public ?int $editingDeadline = null;

    /** @var array{title: string, interval_months: string, due_on: string, lead_days: string, notes: string} */
    public array $deadline = [];

    public bool $recording = false;

    /** @var array{deadline_id: string, kind: string, title: string, done_on: string, mileage_km: string, cost: string, provider: string, notes: string} */
    public array $record = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var list<array{step: string, state: string, note: string}> the Prüfschritte of the Prüfung being entered */
    public array $checklist = [];

    public string $signature = '';

    public string $signerName = '';

    /** Hand the costs to the Finanzen as an Ausgabe */
    public bool $toFinance = false;

    public string $costCenterId = '';

    /** @var array{kind: string, title: string, valid_until: string, internal: bool} */
    public array $doc = ['kind' => 'registration', 'title' => '', 'valid_until' => '', 'internal' => false];

    public ?TemporaryUploadedFile $docFile = null;

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(FleetService::canViewVehicle(auth()->user(), $this->vehicle), 404);
        $this->flash = session('fahrzeuge.flash');
        $this->status = Profile::where('vehicle_id', $this->vehicle->id)->value('status') ?? 'ready';
        $this->resetDowntime();
    }

    public function saveStatus(FleetService $fleet): void
    {
        $this->run(fn () => $fleet->setStatus(auth()->user(), $this->vehicle, $this->status, $this->statusNote), 'Status gespeichert.');
        $this->statusNote = '';
    }

    public function decommission(FleetService $fleet): void
    {
        $this->run(fn () => $fleet->decommission(auth()->user(), $this->vehicle, $this->statusNote), 'Außer Dienst gestellt – nicht mehr buchbar.');
    }

    public function recommission(FleetService $fleet): void
    {
        $this->run(fn () => $fleet->recommission(auth()->user(), $this->vehicle), 'Wieder in Dienst gestellt.');
    }

    public function deleteVehicle(FleetService $fleet)
    {
        $this->resetErrorBag();
        try {
            $fleet->deleteVehicle(auth()->user(), $this->vehicle);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('fahrzeuge.flash', 'Fahrzeug gelöscht.');

        return redirect()->route('fahrzeuge.index');
    }

    public function addDowntime(FleetService $fleet): void
    {
        $affected = collect();
        $ok = $this->run(function () use ($fleet, &$affected) {
            $affected = $fleet->addDowntime(auth()->user(), $this->vehicle, $this->downtime['starts_at'], $this->downtime['ends_at'], $this->downtime['reason']);
        }, 'Ausfallzeit eingetragen. Das Fahrzeug ist in dieser Zeit nicht buchbar.');
        if ($ok) {
            $this->resetDowntime();
            if ($affected->isNotEmpty()) {
                $this->flash .= ' '.$affected->count().' bestehende Buchung'.($affected->count() === 1 ? '' : 'en').' überschneid'.($affected->count() === 1 ? 'et' : 'en').' sich – die Buchenden wurden informiert.';
            }
        }
    }

    public function endDowntime(int $id, FleetService $fleet): void
    {
        $this->run(fn () => $fleet->endDowntime(auth()->user(), Downtime::where('vehicle_id', $this->vehicle->id)->findOrFail($id)), 'Ausfallzeit beendet.');
        $this->status = Profile::where('vehicle_id', $this->vehicle->id)->value('status') ?? 'ready';
    }

    public function editDeadline(?int $id = null, ?string $preset = null): void
    {
        $this->resetErrorBag();
        $d = $id ? Deadline::where('vehicle_id', $this->vehicle->id)->findOrFail($id) : null;
        $this->editingDeadline = $d?->id ?? 0;
        $this->deadline = $d
            ? ['title' => $d->title, 'interval_months' => (string) ($d->interval_months ?? ''), 'due_on' => $d->due_on->toDateString(), 'lead_days' => (string) $d->lead_days,
                'notes' => (string) $d->notes, 'steps' => implode("\n", $d->steps ?? [])]
            : ['title' => trim((string) $preset), 'interval_months' => (string) (Deadline::PRESETS[trim((string) $preset)] ?? ''), 'due_on' => '', 'lead_days' => '30', 'notes' => '',
                'steps' => implode("\n", Deadline::STEPS[trim((string) $preset)] ?? [])];
    }

    public function updatedDeadlineTitle(string $value): void
    {
        if (isset(Deadline::PRESETS[$value]) && ($this->deadline['interval_months'] ?? '') === '') {
            $this->deadline['interval_months'] = (string) Deadline::PRESETS[$value];
        }
        if (isset(Deadline::STEPS[$value]) && trim($this->deadline['steps'] ?? '') === '') {
            $this->deadline['steps'] = implode("\n", Deadline::STEPS[$value]);
        }
    }

    public function saveDeadline(FleetService $fleet): void
    {
        $existing = $this->editingDeadline ? Deadline::where('vehicle_id', $this->vehicle->id)->findOrFail($this->editingDeadline) : null;
        if ($this->run(fn () => $fleet->saveDeadline(auth()->user(), $this->vehicle, $existing, $this->deadline), 'Frist gespeichert.')) {
            $this->editingDeadline = null;
        }
    }

    public function deleteDeadline(int $id, FleetService $fleet): void
    {
        $this->run(fn () => $fleet->deleteDeadline(auth()->user(), Deadline::where('vehicle_id', $this->vehicle->id)->findOrFail($id)), 'Frist entfernt.');
    }

    public function startRecord(?int $deadlineId = null): void
    {
        $this->resetErrorBag();
        $deadline = $deadlineId ? Deadline::where('vehicle_id', $this->vehicle->id)->find($deadlineId) : null;
        $this->recording = true;
        $this->uploads = [];
        $this->record = ['deadline_id' => (string) ($deadline?->id ?? ''), 'kind' => $deadline ? 'inspection' : 'service', 'title' => (string) $deadline?->title,
            'done_on' => FleetService::today()->toDateString(), 'mileage_km' => '', 'cost' => '', 'provider' => '', 'notes' => '', 'invoice_number' => '', 'result' => ''];
        $this->signature = '';
        $this->signerName = auth()->user()->name;
        $this->toFinance = false;
        $this->loadChecklist($deadline);
    }

    public function updatedRecordDeadlineId(string $value): void
    {
        $deadline = $value !== '' ? Deadline::where('vehicle_id', $this->vehicle->id)->find((int) $value) : null;
        if ($deadline) {
            $this->record['kind'] = 'inspection';
            $this->record['title'] = $this->record['title'] ?: $deadline->title;
        }
        $this->loadChecklist($deadline);
    }

    /** A Mangel in the checklist suggests "bedingt einsatzbereit" */
    public function updatedChecklist(): void
    {
        $hasDefect = collect($this->checklist)->contains('state', 'defect');
        if ($hasDefect && in_array($this->record['result'], ['', 'passed'], true)) {
            $this->record['result'] = 'limited';
        } elseif (! $hasDefect && $this->record['result'] === 'limited') {
            $this->record['result'] = 'passed';
        }
    }

    private function loadChecklist(?Deadline $deadline): void
    {
        $this->checklist = collect($deadline?->steps ?? [])->map(fn ($step) => ['step' => $step, 'state' => 'ok', 'note' => ''])->all();
        if ($this->checklist) {
            $this->record['result'] = 'passed';
        }
    }

    public function saveRecord(FleetService $fleet): void
    {
        $this->validate(['uploads.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf']], ['uploads.*.max' => 'Eine Datei darf höchstens 10 MB groß sein.', 'uploads.*.mimes' => 'Bitte Fotos oder PDF-Dateien hochladen.']);
        $cost = trim($this->record['cost']) === '' ? null : Money::parse($this->record['cost']);
        if (trim($this->record['cost']) !== '' && $cost === null) {
            $this->addError('record_cost', 'Bitte die Kosten als Betrag angeben, z. B. 249,90.');

            return;
        }
        if ($this->toFinance && $this->costCenterId === '') {
            $this->addError('record_finance', 'Bitte die Kostenstelle wählen.');

            return;
        }
        $data = [...$this->record, 'cost_cents' => $cost, 'deadline_id' => $this->record['deadline_id'] ?: null, 'result' => $this->record['result'] ?: null,
            'checklist' => $this->checklist, 'signature' => $this->signature ?: null, 'signer_name' => $this->signerName];
        $record = null;
        $done = 'Eingetragen.';
        if ($this->run(function () use ($fleet, $data, &$record) {
            $record = $fleet->recordWork(auth()->user(), $this->vehicle, $data, $this->uploads);
        }, $done)) {
            if ($record->result === 'failed' && $record->deadline_id) {
                $this->flash .= ' Die Frist bleibt bis zur Nachprüfung offen.';
            }
            if ($record->defects() && app(ModuleRegistry::class)->isActive('maengelmanagement')) {
                $this->flash .= ' '.count($record->defects()).' '.(count($record->defects()) === 1 ? 'Mangel steht' : 'Mängel stehen').' jetzt im Mängelmanagement.';
            }
            if ($this->toFinance && $record->cost_cents) {
                try {
                    $fleet->submitToFinance(auth()->user(), $record, (int) $this->costCenterId, $this->uploads);
                    $this->flash .= ' Die Kosten sind als Ausgabe bei den Finanzen eingereicht.';
                } catch (ValidationException $e) {
                    $this->flash .= ' Finanzen: '.collect($e->errors())->flatten()->first();
                }
            }
            $this->status = Profile::where('vehicle_id', $this->vehicle->id)->value('status') ?? 'ready';
            $this->recording = false;
            $this->uploads = [];
            $this->checklist = [];
            $this->signature = '';
        }
    }

    public function addDocument(FleetService $fleet): void
    {
        $this->validate(['docFile' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf']],
            ['docFile.required' => 'Bitte eine Datei wählen.', 'docFile.max' => 'Die Datei darf höchstens 10 MB groß sein.', 'docFile.mimes' => 'Bitte ein Foto oder eine PDF-Datei hochladen.']);
        if ($this->run(fn () => $fleet->addDocument(auth()->user(), $this->vehicle, $this->doc, $this->docFile), 'Dokument abgelegt.')) {
            $this->doc = ['kind' => 'registration', 'title' => '', 'valid_until' => '', 'internal' => false];
            $this->docFile = null;
        }
    }

    public function deleteDocument(int $id, FleetService $fleet): void
    {
        $this->run(fn () => $fleet->deleteDocument(auth()->user(), Document::where('vehicle_id', $this->vehicle->id)->findOrFail($id)), 'Dokument entfernt.');
    }

    public function cancel(): void
    {
        $this->editingDeadline = null;
        $this->recording = false;
    }

    private function resetDowntime(): void
    {
        $this->downtime = ['starts_at' => now('Europe/Berlin')->format('Y-m-d\TH:i'), 'ends_at' => '', 'reason' => ''];
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;
        $this->vehicle->refresh();

        return true;
    }

    public function render(FleetService $fleet)
    {
        $profile = Profile::firstOrNew(['vehicle_id' => $this->vehicle->id]);
        $modules = app(ModuleRegistry::class);
        $this->vehicle->loadMissing('standort');
        $equipment = $profile->location_id && $modules->isActive('ausruestung')
            ? Equipment::inService()->where('location_id', $profile->location_id)->where('ext_location_id', $this->vehicle->ext_location_id)->get()
            : null;

        return view('fahrzeuge::livewire.vehicle-detail', [
            'profile' => $profile,
            'state' => $fleet->states(collect([$this->vehicle]))->first(),
            'downtimes' => Downtime::where('vehicle_id', $this->vehicle->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('starts_at')->get(),
            'deadlines' => Deadline::active()->where('vehicle_id', $this->vehicle->id)->orderBy('due_on')->get(),
            'records' => Record::with('attachments:id,record_id,name,mime')->where('vehicle_id', $this->vehicle->id)->latest('done_on')->latest('id')->limit(50)->get(),
            'events' => StatusEvent::with('user')->where('vehicle_id', $this->vehicle->id)->latest('created_at')->limit(15)->get(),
            'einsaetze' => $fleet->einsatzHistory($this->vehicle),
            'einsatzThisYear' => $fleet->einsatzHistory($this->vehicle, 1000)->filter(fn ($e) => str_starts_with((string) $e->alarmed_at, (string) now('Europe/Berlin')->year))->count(),
            'assignment' => $fleet->runningAssignments([$this->vehicle->id])->first(),
            'bookings' => $modules->isActive('fahrzeugbuchung')
                ? Booking::visible()->with('user')->where('vehicle_id', $this->vehicle->id)->where('ends_at', '>', now())->orderBy('starts_at')->limit(10)->get()
                : null,
            'equipment' => $equipment,
            'canManage' => $canManage = FleetService::canManage(auth()->user()),
            'documents' => Document::where('vehicle_id', $this->vehicle->id)->when(! $canManage, fn ($q) => $q->where('internal', false))
                ->orderBy('kind')->orderByDesc('created_at')->get(['id', 'vehicle_id', 'kind', 'title', 'valid_until', 'internal', 'name', 'mime', 'size', 'created_at']),
            'defects' => $modules->isActive('maengelmanagement') && auth()->user()->canUseModule('maengelmanagement')
                ? Defect::active()->where('vehicle_id', $this->vehicle->id)->latest()->get()
                : null,
            'costs' => $canManage ? $fleet->costs($this->vehicle, (int) now('Europe/Berlin')->year) : null,
            'costCenters' => $canManage && $modules->isActive('finanzen') && auth()->user()->canUseModule('finanzen')
                ? CostCenter::active()->ordered()->get(['id', 'name', 'code'])
                : null,
            'canSeeReports' => auth()->user()->can('einsatzbericht-lead'),
        ]);
    }
}
