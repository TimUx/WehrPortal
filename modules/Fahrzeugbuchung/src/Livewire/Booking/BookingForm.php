<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Booking;

use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;

class BookingForm extends Component
{
    /** Length of a new booking */
    public const DEFAULT_MINUTES = 180;

    public ?int $bookingId = null;

    public ?int $vehicleId = null;

    public string $startsAt = '';

    public string $endsAt = '';

    public string $purpose = '';

    public string $destination = '';

    public string $notes = '';

    public ?int $groupId = null;

    /** Who the booking belongs to; only admins can pick someone else */
    public ?int $ownerId = null;

    public string $ownerSearch = '';

    /** Why the requester needs the vehicle despite the conflict (conflict requests only) */
    public string $reason = '';

    public bool $show = false;

    // Availability check result
    public bool $available = true;

    public ?array $conflict = null;

    /** Why the vehicle can't be booked at all in this time (Werkstatt), from the Fahrzeugverwaltung */
    public ?string $blocked = null;

    public array $alternatives = [];

    public function mount(?int $bookingId = null, ?string $date = null): void
    {
        if ($bookingId) {
            $this->loadBooking($bookingId);
        } elseif ($date) {
            $this->setDefaultTimes(Carbon::parse($date)->setTimezone('Europe/Berlin'));
        }
    }

    /** A new booking starts at 08:00 on the chosen day and lasts the default 3 hours */
    private function setDefaultTimes(Carbon $day): void
    {
        $start = $day->copy()->setTime(8, 0);
        $this->startsAt = $start->format('Y-m-d\TH:i');
        $this->endsAt = $start->copy()->addMinutes(self::DEFAULT_MINUTES)->format('Y-m-d\TH:i');
    }

    #[On('open-booking-form')]
    public function open(?string $date = null, ?int $vehicleId = null): void
    {
        $this->reset(['purpose', 'destination', 'notes', 'groupId', 'conflict', 'blocked', 'alternatives', 'bookingId', 'reason', 'ownerSearch']);
        $this->ownerId = auth()->id();
        $this->available = true;

        $this->setDefaultTimes($date ? Carbon::parse($date)->setTimezone('Europe/Berlin') : now('Europe/Berlin'));

        $this->vehicleId = $vehicleId ?? Vehicle::active()->first()?->id;
        $this->show = true;
    }

    public function selectOwner(int $userId): void
    {
        abort_unless(auth()->user()->is_admin, 403);

        $this->ownerId = User::active()->findOrFail($userId)->id;
        $this->ownerSearch = '';
    }

    public function close(): void
    {
        $this->show = false;
    }

    #[On('open-edit-booking')]
    public function openForEdit(int $bookingId): void
    {
        $this->reset(['purpose', 'destination', 'notes', 'groupId', 'conflict', 'blocked', 'alternatives', 'reason', 'ownerSearch']);
        $this->available = true;
        $this->loadBooking($bookingId);
        $this->show = true;
    }

    public function updatedVehicleId(): void
    {
        $this->checkAvailability();
    }

    /** Moving the start moves the end along, keeping the duration (the default if there was none) */
    public function updatingStartsAt(string $value): void
    {
        $format = 'Y-m-d\TH:i';
        $newStart = Carbon::canBeCreatedFromFormat($value, $format) ? Carbon::createFromFormat($format, $value) : null;
        $oldStart = Carbon::canBeCreatedFromFormat($this->startsAt, $format) ? Carbon::createFromFormat($format, $this->startsAt) : null;
        $oldEnd = Carbon::canBeCreatedFromFormat($this->endsAt, $format) ? Carbon::createFromFormat($format, $this->endsAt) : null;

        if (! $newStart) {
            return;
        }

        $minutes = $oldStart && $oldEnd && $oldEnd > $oldStart ? $oldStart->diffInMinutes($oldEnd) : self::DEFAULT_MINUTES;
        $this->endsAt = $newStart->copy()->addMinutes($minutes)->format($format);
    }

    public function updatedStartsAt(): void
    {
        $this->checkAvailability();
    }

    public function updatedEndsAt(): void
    {
        $this->checkAvailability();
    }

    public function checkAvailability(): void
    {
        if (! $this->vehicleId || ! $this->startsAt || ! $this->endsAt) {
            return;
        }

        try {
            $starts = Carbon::createFromFormat('Y-m-d\TH:i', $this->startsAt, 'Europe/Berlin')->utc();
            $ends = Carbon::createFromFormat('Y-m-d\TH:i', $this->endsAt, 'Europe/Berlin')->utc();
        } catch (\Exception) {
            return;
        }

        if ($starts >= $ends) {
            return;
        }

        $service = app(BookingService::class);
        $result = $service->checkAvailability($this->vehicleId, $starts, $ends, $this->bookingId);

        $this->available = $result['available'];
        $this->blocked = $result['blocked'] ?? null;
        $this->conflict = $result['conflict']
            ? [
                'id' => $result['conflict']->id,
                'userName' => $result['conflict']->user->name,
                'purpose' => $result['conflict']->purpose,
                'startsAt' => $result['conflict']->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'),
                'endsAt' => $result['conflict']->ends_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'),
            ]
            : null;

        $this->alternatives = $result['alternatives']
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'color' => $v->color])
            ->toArray();
    }

    public function save(BookingService $service, DecisionService $decisions): void
    {
        $this->validate([
            'vehicleId' => ['required', 'integer', 'exists:vehicles,id'],
            'startsAt' => ['required'],
            'endsAt' => ['required'],
            'purpose' => ['required', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'vehicleId.required' => 'Bitte wähle ein Fahrzeug.',
            'startsAt.required' => 'Bitte gib die Startzeit an.',
            'endsAt.required' => 'Bitte gib die Endzeit an.',
            'purpose.required' => 'Bitte gib einen Zweck an.',
        ]);

        $starts = Carbon::createFromFormat('Y-m-d\TH:i', $this->startsAt, 'Europe/Berlin')->utc();
        $ends = Carbon::createFromFormat('Y-m-d\TH:i', $this->endsAt, 'Europe/Berlin')->utc();

        if ($starts >= $ends) {
            $this->addError('endsAt', 'Die Endzeit muss nach der Startzeit liegen.');

            return;
        }

        // Non-admins always book for themselves
        $owner = auth()->user()->is_admin && $this->ownerId
            ? User::findOrFail($this->ownerId)
            : auth()->user();

        $data = [
            'user_id' => $owner->id,
            'vehicle_id' => $this->vehicleId,
            'group_id' => $this->groupId,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'purpose' => $this->purpose,
            'destination' => $this->destination ?: null,
            'notes' => $this->notes ?: null,
        ];

        // Overlaps a confirmed booking: ask the deciders instead. A change of an
        // existing booking keeps the original until the decision.
        $this->checkAvailability();

        if ($this->blocked) {
            $this->addError('vehicleId', 'Das Fahrzeug ist in diesem Zeitraum nicht verfügbar: '.$this->blocked.'.');

            return;
        }

        if (! $this->available) {
            $replaces = $this->bookingId ? Booking::findOrFail($this->bookingId) : null;
            $replaces
                ? $this->authorize('update', $replaces)
                : $this->authorize('create', Booking::class);

            $this->validate(
                ['reason' => ['required', 'string', 'max:2000']],
                ['reason.required' => 'Bitte begründe, warum du das Fahrzeug trotzdem brauchst.'],
            );

            try {
                $decisions->request($data, $owner, $this->reason, $replaces);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    $this->addError($key, $messages[0]);
                }

                return;
            }

            session()->flash('success', 'Anfrage gesendet. Die Wehrführung entscheidet – das Ergebnis bekommst du per E-Mail.');
            $this->redirectRoute('fahrzeugbuchung.calendar');

            return;
        }

        try {
            if ($this->bookingId) {
                $booking = Booking::findOrFail($this->bookingId);
                $this->authorize('update', $booking);
                $service->update($booking, $data);
            } else {
                $this->authorize('create', Booking::class);
                $service->create($data, $owner->id);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        }

        $this->show = false;
        $this->dispatch('calendar-refresh');
        session()->flash('success', $this->bookingId ? 'Buchung aktualisiert.' : 'Buchung gespeichert.');
    }

    public function loadBooking(int $bookingId): void
    {
        $booking = Booking::findOrFail($bookingId);
        // Only who may change the booking gets it into the form (with its notes and destination)
        $this->authorize('update', $booking);
        $this->bookingId = $booking->id;
        $this->ownerId = $booking->user_id;
        $this->vehicleId = $booking->vehicle_id;
        $this->groupId = $booking->group_id;
        $this->startsAt = $booking->starts_at->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i');
        $this->endsAt = $booking->ends_at->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i');
        $this->purpose = $booking->purpose;
        $this->destination = $booking->destination ?? '';
        $this->notes = $booking->notes ?? '';
    }

    public function render()
    {
        $vehicles = Vehicle::active()->get();
        // Any group, not only the booker's own – e.g. a member driving for the Musikzug
        $groups = Group::query()
            ->when(Group::locationsAvailable(), fn ($query) => $query->with('location'))
            ->orderBy('sort_order')->orderBy('name')->get();

        $isAdmin = (bool) auth()->user()?->is_admin;
        $term = trim($this->ownerSearch);

        return view('fahrzeugbuchung::livewire.booking.booking-form', [
            'vehicles' => $vehicles,
            'groups' => $groups,
            'isAdmin' => $isAdmin,
            'owner' => $this->ownerId ? User::find($this->ownerId) : null,
            'ownerResults' => $isAdmin && mb_strlen($term) >= 2
                ? User::active()
                    ->where(fn ($q) => $q->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%"))
                    ->orderBy('name')
                    ->limit(8)
                    ->get()
                : collect(),
        ]);
    }
}
