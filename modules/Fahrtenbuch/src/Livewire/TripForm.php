<?php

namespace Modules\Fahrtenbuch\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * One form for a trip: begin (?fahrzeug=), end an open one (?fahrt=), enter one afterwards
 * (?nachtragen=1) or correct one (?fahrt=&korrigieren=1, Fahrtenbuchwart). The vehicle of a running
 * booking, a running Einsatz of the vehicle and the last Kilometerstand are filled in by themselves.
 */
class TripForm extends Component
{
    public string $mode = 'start';            // start | end | record | correct

    public ?Trip $trip = null;

    public string $vehicleId = '';

    public string $driverId = '';

    public string $startedAt = '';

    public string $startKm = '';

    public string $origin = '';

    public string $purpose = '';

    public string $purposeNote = '';

    public string $reportId = '';

    public string $passengers = '';

    public string $notes = '';

    public string $endedAt = '';

    public string $endKm = '';

    public string $destination = '';

    public string $reason = '';

    /** Shown under the form: what was filled in by itself */
    public array $hints = [];

    public function mount(LogbookService $logbook): void
    {
        $user = auth()->user();
        $this->driverId = (string) $user->id;
        $this->origin = 'Gerätehaus';
        $now = now('Europe/Berlin')->format('Y-m-d\TH:i');
        $this->startedAt = $now;
        $this->endedAt = $now;

        if (request()->filled('fahrt')) {
            $this->trip = Trip::with('vehicle')->findOrFail((int) request('fahrt'));
            $this->mode = request()->boolean('korrigieren') && LogbookService::canManage($user) ? 'correct' : ($this->trip->isOpen() ? 'end' : 'view');
            $this->fillFromTrip();
            if ($this->mode === 'end') {
                $this->endedAt = $now;
                $this->destination = $this->trip->origin;
            }

            return;
        }

        $this->mode = request()->boolean('nachtragen') ? 'record' : 'start';
        $this->vehicleId = (string) (Vehicle::where('is_active', true)->find((int) request('fahrzeug'))?->id ?? '');
        $this->applySuggestions($logbook);
    }

    public function updatedVehicleId(LogbookService $logbook): void
    {
        $this->applySuggestions($logbook);
    }

    public function updatedDriverId(LogbookService $logbook): void
    {
        $this->applySuggestions($logbook);
    }

    public function save(LogbookService $logbook)
    {
        $this->resetErrorBag();
        $user = auth()->user();
        $start = ['vehicle_id' => $this->vehicleId, 'driver_id' => $this->driverId, 'started_at' => $this->startedAt, 'start_km' => $this->startKm,
            'origin' => $this->origin, 'purpose' => $this->purpose, 'purpose_note' => $this->purposeNote, 'report_id' => $this->reportId ?: null,
            'passengers' => $this->passengers, 'notes' => $this->notes];
        $end = ['ended_at' => $this->endedAt, 'end_km' => $this->endKm, 'destination' => $this->destination, 'notes' => $this->notes];

        try {
            $trip = match ($this->mode) {
                'start' => $logbook->start($user, $start),
                'end' => $logbook->end($user, $this->trip, $end),
                'record' => $logbook->record($user, [...$start, ...$end]),
                'correct' => $logbook->correct($user, $this->trip, [...$start, ...($this->endKm !== '' ? $end : [])], $this->reason),
                default => throw ValidationException::withMessages(['trip' => 'Diese Fahrt ist beendet; korrigieren kann nur der Fahrtenbuchwart.']),
            };
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('fahrtenbuch.flash', match ($this->mode) {
            'start' => 'Gute Fahrt! Die Fahrt mit '.$trip->vehicle->name.' ist begonnen – nach der Rückkehr hier beenden.',
            'end' => 'Fahrt beendet: '.number_format((int) $trip->distance(), 0, ',', '.').' km.',
            'record' => 'Fahrt nachgetragen.',
            default => 'Korrektur gespeichert.',
        });

        return redirect()->route('fahrtenbuch.vehicle', $trip->vehicle_id);
    }

    public function void(LogbookService $logbook)
    {
        try {
            $logbook->void(auth()->user(), $this->trip, $this->reason);
        } catch (ValidationException $e) {
            $this->addError('reason', collect($e->errors())->flatten()->first());

            return null;
        }
        session()->flash('fahrtenbuch.flash', 'Fahrt storniert.');

        return redirect()->route('fahrtenbuch.vehicle', $this->trip->vehicle_id);
    }

    private function fillFromTrip(): void
    {
        $t = $this->trip;
        $local = fn ($d) => $d?->copy()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i') ?? '';
        $this->fill([
            'vehicleId' => (string) $t->vehicle_id, 'driverId' => (string) $t->driver_id, 'startedAt' => $local($t->started_at), 'startKm' => (string) $t->start_km,
            'origin' => $t->origin, 'purpose' => $t->purpose, 'purposeNote' => (string) $t->purpose_note, 'reportId' => (string) ($t->report_id ?? ''),
            'passengers' => (string) ($t->passengers ?? ''), 'notes' => (string) $t->notes, 'endedAt' => $local($t->ended_at), 'endKm' => (string) ($t->end_km ?? ''),
            'destination' => (string) $t->destination,
        ]);
    }

    /** Booking, running Einsatz and last Kilometerstand fill the form ("automatische Fahrzeugzuordnung") */
    private function applySuggestions(LogbookService $logbook): void
    {
        if (! in_array($this->mode, ['start', 'record'], true)) {
            return;
        }
        $driver = User::find((int) $this->driverId) ?? auth()->user();
        $s = $logbook->suggest($driver, $this->vehicleId !== '' ? Vehicle::find((int) $this->vehicleId) : null);
        $this->hints = [];

        if ($this->vehicleId === '' && $s['vehicle_id']) {
            $this->vehicleId = (string) $s['vehicle_id'];
            $this->hints[] = 'Fahrzeug aus deiner Buchung übernommen.';
        }
        if ($s['booking'] && $this->purposeNote === '') {
            $this->purposeNote = mb_substr((string) $s['booking']->purpose, 0, 300);
            $this->purpose = $this->purpose ?: 'dienstfahrt';
            $this->hints[] = 'Buchung „'.$s['booking']->purpose.'“ wird verknüpft.';
        }
        if ($s['report']) {
            $this->purpose = 'einsatz';
            $this->reportId = (string) $s['report']->id;
            $this->hints[] = 'Das Fahrzeug ist im laufenden Einsatz '.$s['report']->number.' eingetragen – verknüpft.';
        }
        if ($s['start_km'] !== null && $this->mode === 'start') {
            $this->startKm = (string) $s['start_km'];
        }
    }

    public function render(LogbookService $logbook)
    {
        return view('fahrtenbuch::livewire.trip-form', [
            'vehicles' => Vehicle::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->trip?->vehicle_id))->orderBy('sort_order')->orderBy('name')->get(),
            'drivers' => User::active()->orderBy('name')->get(['id', 'name']),
            'reports' => $logbook->reportOptions()->when($this->trip?->report, fn ($c) => $c->push($this->trip->report)->unique('id')),
            'lastKm' => $this->vehicleId !== '' && ($v = Vehicle::find((int) $this->vehicleId)) ? $logbook->lastKm($v, $this->trip?->id) : null,
            'canManage' => LogbookService::canManage(auth()->user()),
        ]);
    }
}
