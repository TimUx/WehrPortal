<?php

namespace Modules\Ausruestung\src\Livewire;

use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\EquipmentEvent;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\LoanService;

/** One item for members: details, history, and report a damage, reserve or log an Einsatz */
class EquipmentDetail extends Component
{
    public Equipment $equipment;

    public string $damage = '';

    public string $reserveFrom = '';

    public string $reserveUntil = '';

    public string $useType = 'operation';

    public string $useDate = '';

    public string $useText = '';

    public ?string $flash = null;

    /** Gerätewarte: Reinigung or Wartung with costs */
    public string $workType = 'cleaning';

    public string $workDate = '';

    public string $workText = '';

    public string $workCost = '';

    /** Gerätewarte: the RFID/NFC tag */
    public string $tag = '';

    public function mount(): void
    {
        $this->reserveFrom = now()->addDay()->setTimezone('Europe/Berlin')->format('Y-m-d\T08:00');
        $this->reserveUntil = now()->addDays(2)->setTimezone('Europe/Berlin')->format('Y-m-d\T18:00');
        $this->useDate = now()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i');
        $this->workDate = $this->useDate;
        $this->tag = (string) $this->equipment->rfid;
    }

    public function logWork(EquipmentService $service): void
    {
        $cost = trim($this->workCost) === '' ? null : Money::parse($this->workCost);
        if (trim($this->workCost) !== '' && $cost === null) {
            $this->addError('workCost', 'Bitte die Kosten als Betrag angeben, z. B. 35,00.');

            return;
        }
        $this->validate(['workDate' => ['required', 'date'], 'workText' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->logWork($this->equipment, auth()->user(), $this->workType, trim($this->workText) ?: null, $this->local($this->workDate), $cost !== null ? $cost / 100 : null);
        } catch (ValidationException $e) {
            $this->addError('workType', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('workText', 'workCost');
        $this->flash = EquipmentEvent::TYPES[$this->workType].' eingetragen.';
    }

    public function saveTag(EquipmentService $service): void
    {
        try {
            $service->setTag($this->equipment, auth()->user(), $this->tag);
        } catch (ValidationException $e) {
            $this->addError('tag', collect($e->errors())->flatten()->first());

            return;
        }
        $this->tag = (string) $this->equipment->refresh()->rfid;
        $this->flash = $this->tag !== '' ? 'Tag '.$this->tag.' zugeordnet – ein Scan öffnet jetzt dieses Gerät.' : 'Tag entfernt.';
    }

    public function reportDamage(EquipmentService $service): void
    {
        $this->validate(['damage' => ['required', 'string', 'max:3000']], ['damage.required' => 'Bitte beschreibe den Schaden.']);

        $service->reportDamage($this->equipment, auth()->user(), trim($this->damage));
        $this->reset('damage');
        $this->equipment->refresh();
        $this->flash = 'Danke! Der Schaden ist gemeldet, der Gerätewart ist informiert.';
    }

    public function reserve(LoanService $loans): void
    {
        // The inputs are local (Europe/Berlin) times, so they are compared as such
        $this->validate([
            'reserveFrom' => ['required', 'date', fn ($a, $value, $fail) => $this->local($value)->lt(now()->subMinutes(5)) ? $fail('Der Beginn liegt in der Vergangenheit.') : null],
            'reserveUntil' => ['required', 'date', 'after:reserveFrom'],
        ], ['reserveUntil.after' => 'Das Ende muss nach dem Beginn liegen.']);

        try {
            $loans->reserve(
                $this->equipment, auth()->user(), auth()->user(),
                $this->local($this->reserveFrom),
                $this->local($this->reserveUntil),
            );
        } catch (ValidationException $e) {
            $this->addError('reserveFrom', collect($e->errors())->flatten()->first());

            return;
        }

        $this->flash = 'Reserviert. Abholen beim Gerätewart; deine Reservierungen stehen unter „Meine Ausleihen“.';
    }

    public function logUse(EquipmentService $service): void
    {
        $this->validate([
            'useType' => ['required', Rule::in(EquipmentEvent::MANUAL_TYPES)],
            'useDate' => ['required', 'date', fn ($a, $value, $fail) => $this->local($value)->gt(now()->addMinutes(5)) ? $fail('Das Datum liegt in der Zukunft.') : null],
            'useText' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->log($this->equipment, auth()->user(), $this->useType, trim($this->useText) ?: null, $this->local($this->useDate));
        $this->reset('useText');
        $this->flash = 'Eingetragen.';
    }

    /** A datetime-local input value, which is Berlin time, as UTC */
    private function local(string $value): Carbon
    {
        return Carbon::parse($value, 'Europe/Berlin')->utc();
    }

    public function render()
    {
        $this->equipment->loadMissing(['category', 'location', 'compartment.location']);

        return view('ausruestung::livewire.equipment-detail', [
            'events' => $this->equipment->events()->with('user')->limit(20)->get(),
            'upcoming' => $this->equipment->loans()->with('user')->whereIn('status', [LoanStatus::Reserved->value, LoanStatus::Active->value])->orderBy('starts_at')->get(),
            'canReserve' => in_array($this->equipment->status, [EquipmentStatus::Available, EquipmentStatus::Loaned], true),
            'useTypes' => array_intersect_key(EquipmentEvent::TYPES, array_flip(EquipmentEvent::MANUAL_TYPES)),
            'workTypes' => array_intersect_key(EquipmentEvent::TYPES, array_flip(EquipmentEvent::WORK_TYPES)),
            'canManage' => $canManage = auth()->user()->canManageAusruestung(),
            'costs' => $canManage ? app(EquipmentService::class)->lifecycleCosts($this->equipment) : null,
        ]);
    }
}
