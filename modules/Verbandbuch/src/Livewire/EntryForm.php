<?php

namespace Modules\Verbandbuch\src\Livewire;

use App\Models\User;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

/**
 * Write an entry into the Verbandbuch and sign it on the screen. A Kamerad picks himself or
 * another Kamerad as injured person, or types a name (e.g. a guest at a Veranstaltung).
 */
class EntryForm extends Component
{
    /** Marks "someone who is not a Kamerad" in the person selects */
    public const OTHER = 'other';

    public string $kind = 'injury';

    public string $occasion = '';

    public string $occurredAt = '';

    public string $location = '';

    public string $organizationalLocationId = '';

    public string $injuredUserId = '';

    public string $injuredName = '';

    public string $description = '';

    public string $injury = '';

    public string $witnesses = '';

    public string $firstAidAt = '';

    public string $firstAidMeasures = '';

    public string $firstAiderUserId = '';

    public string $firstAiderName = '';

    public bool $doctorVisited = false;

    /** PNG data URLs from the signature pads */
    public string $signature = '';

    public string $injuredSignature = '';

    public function mount(): void
    {
        $this->occurredAt = VerbandbuchService::now()->format('Y-m-d\TH:i');
        $this->firstAiderUserId = (string) auth()->id();
        $this->organizationalLocationId = (string) (app(LocationContext::class)->forUser(auth()->user())['current'] ?? '');
    }

    public function save(VerbandbuchService $service)
    {
        $this->resetErrorBag();
        $person = fn (string $id) => $id !== '' && $id !== self::OTHER ? (int) $id : null;

        try {
            $entry = $service->create(auth()->user(), [
                'kind' => $this->kind, 'occasion' => $this->occasion, 'occurred_at' => $this->occurredAt, 'location' => $this->location,
                'ext_location_id' => $this->organizationalLocationId,
                'injured_user_id' => $person($this->injuredUserId), 'injured_name' => $this->injuredUserId === self::OTHER ? $this->injuredName : null,
                'description' => $this->description, 'injury' => $this->injury, 'witnesses' => $this->witnesses,
                'first_aid_at' => $this->firstAidAt, 'first_aid_measures' => $this->firstAidMeasures,
                'first_aider_user_id' => $person($this->firstAiderUserId), 'first_aider_name' => $this->firstAiderUserId === self::OTHER ? $this->firstAiderName : null,
                'doctor_visited' => $this->doctorVisited,
            ], $this->signature, $this->injuredSignature ?: null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('verbandbuch.flash', 'Eintrag '.$entry->code().' gespeichert und unterschrieben.');

        return redirect()->route('verbandbuch.show', $entry);
    }

    public function render()
    {
        return view('verbandbuch::livewire.entry-form', [
            'members' => User::active()->orderBy('name')->get(['id', 'name']),
            'locations' => config('locations.enabled') ? app(LocationContext::class)->forUser(auth()->user())['locations'] : collect(),
            'hurt' => $this->kind !== 'incident',
            'injuredLabel' => match (true) {
                $this->injuredUserId === self::OTHER => $this->injuredName,
                $this->injuredUserId !== '' => User::find((int) $this->injuredUserId)?->name,
                default => null,
            },
        ]);
    }
}
