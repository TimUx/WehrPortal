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
use Modules\Fahrzeuge\src\Models\Downtime;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Models\StatusEvent;
use Modules\Fahrzeuge\src\Services\FleetService;

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

    public ?string $flash = null;

    public function mount(): void
    {
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
            ? ['title' => $d->title, 'interval_months' => (string) ($d->interval_months ?? ''), 'due_on' => $d->due_on->toDateString(), 'lead_days' => (string) $d->lead_days, 'notes' => (string) $d->notes]
            : ['title' => trim((string) $preset), 'interval_months' => (string) (Deadline::PRESETS[trim((string) $preset)] ?? ''), 'due_on' => '', 'lead_days' => '30', 'notes' => ''];
    }

    public function updatedDeadlineTitle(string $value): void
    {
        if (isset(Deadline::PRESETS[$value]) && ($this->deadline['interval_months'] ?? '') === '') {
            $this->deadline['interval_months'] = (string) Deadline::PRESETS[$value];
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
            'done_on' => FleetService::today()->toDateString(), 'mileage_km' => '', 'cost' => '', 'provider' => '', 'notes' => ''];
    }

    public function saveRecord(FleetService $fleet): void
    {
        $this->validate(['uploads.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf']], ['uploads.*.max' => 'Eine Datei darf höchstens 10 MB groß sein.', 'uploads.*.mimes' => 'Bitte Fotos oder PDF-Dateien hochladen.']);
        $cost = trim($this->record['cost']) === '' ? null : Money::parse($this->record['cost']);
        if (trim($this->record['cost']) !== '' && $cost === null) {
            $this->addError('record_cost', 'Bitte die Kosten als Betrag angeben, z. B. 249,90.');

            return;
        }
        $data = [...$this->record, 'cost_cents' => $cost, 'deadline_id' => $this->record['deadline_id'] ?: null];
        if ($this->run(fn () => $fleet->recordWork(auth()->user(), $this->vehicle, $data, $this->uploads), 'Eingetragen.')) {
            $this->recording = false;
            $this->uploads = [];
        }
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
        $equipment = $profile->location_id && $modules->isActive('ausruestung')
            ? Equipment::inService()->where('location_id', $profile->location_id)->get()
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
            'assignment' => $fleet->runningAssignments()->firstWhere('vehicle_id', $this->vehicle->id),
            'bookings' => $modules->isActive('fahrzeugbuchung')
                ? Booking::visible()->with('user')->where('vehicle_id', $this->vehicle->id)->where('ends_at', '>', now())->orderBy('starts_at')->limit(10)->get()
                : null,
            'equipment' => $equipment,
            'canManage' => FleetService::canManage(auth()->user()),
            'canSeeReports' => auth()->user()->can('einsatzbericht-lead'),
        ]);
    }
}
