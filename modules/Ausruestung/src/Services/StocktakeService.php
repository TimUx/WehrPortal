<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use Extensions\Locations\Models\Location as Standort;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;
use Modules\Ausruestung\src\Models\Stocktake;

/**
 * Inventur of a room or vehicle: everything booked there is expected; what is scanned (code,
 * barcode or RFID/NFC tag) or ticked is found. Items found here but booked elsewhere can be moved
 * here; ausgeliehene items are not counted as missing. Every item gets a history entry.
 */
class StocktakeService
{
    public function __construct(private readonly EquipmentService $equipment) {}

    /** @return Collection<int, Equipment> in service and booked at the location (or one of its Geräteräume) */
    public function expected(Location $location, User $user, ?int $standortId = null): Collection
    {
        $query = EquipmentService::managedEquipmentFor($user)->inService()->with(['category', 'compartment'])
            ->where('location_id', $location->id);

        return ($standortId === null ? $query : $query->where('ext_location_id', $standortId))
            ->orderBy('compartment_id')->orderBy('name')->get();
    }

    /**
     * @param  list<int>  $foundIds
     * @param  list<string>  $unknown  scanned codes nobody knows
     */
    public function finish(User $by, Location $location, array $foundIds, array $unknown = [], bool $moveHere = true, ?string $notes = null, ?int $standortId = null): Stocktake
    {
        if (! $by->canManageAusruestung()) {
            throw ValidationException::withMessages(['stocktake' => 'Die Inventur machen die Gerätewarte.']);
        }

        if (config('locations.enabled')) {
            $physicalStandortId = $location->getAttribute('ext_location_id') === null
                ? null
                : (int) $location->getAttribute('ext_location_id');
            if ($standortId !== null && $standortId !== $physicalStandortId) {
                throw ValidationException::withMessages(['standortId' => 'Der Standort der Inventur muss dem Standort des Lagerorts entsprechen.']);
            }
            $standortId = $physicalStandortId;
        }

        $locationScope = EquipmentService::equipmentManagementScope($by);
        if ($locationScope !== null && (! $standortId || ! in_array($standortId, $locationScope, true))) {
            throw ValidationException::withMessages(['standortId' => 'Bitte einen Standort auswählen, den du verwalten darfst.']);
        }
        if ($locationScope !== null && ! EquipmentService::managedEquipmentFor($by)
            ->where('ext_location_id', $standortId)->where('location_id', $location->id)->exists()) {
            throw ValidationException::withMessages(['locationId' => 'Für diesen Raum oder dieses Fahrzeug gibt es an deinem Standort keine Geräte.']);
        }
        if ($standortId !== null && ! Standort::query()->whereKey($standortId)->exists()) {
            throw ValidationException::withMessages(['standortId' => 'Der ausgewählte Standort ist nicht verfügbar.']);
        }

        return DB::transaction(function () use ($by, $location, $foundIds, $unknown, $moveHere, $notes, $standortId) {
            $expected = $this->expected($location, $by, $standortId);
            $ids = array_values(array_unique(array_map('intval', $foundIds)));
            $found = EquipmentService::managedEquipmentFor($by)->with(['location', 'compartment.location'])
                ->whereIn('id', $ids)
                ->when($standortId !== null, fn ($query) => $query->where('ext_location_id', $standortId))
                ->get();
            if ($found->count() !== count($ids)) {
                throw ValidationException::withMessages(['found' => 'Die Auswahl enthält Geräte, die du an diesem Standort nicht verwalten darfst.']);
            }
            $foreign = $found->reject(fn (Equipment $e) => $e->location_id === $location->id);
            $missing = $expected->reject(fn (Equipment $e) => $found->contains('id', $e->id) || $e->status === EquipmentStatus::Loaned);

            $moved = [];
            foreach ($foreign as $item) {
                $from = $item->placeLabel();
                if ($moveHere) {
                    $this->equipment->move($item, $by, $location->id, null);
                    $moved[] = ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'from' => $from];
                }
                $this->equipment->log($item, $by, 'inventory', 'Bei der Inventur in '.$location->name.' gefunden'.($moveHere ? '' : ' (gebucht: '.$from.')'));
            }
            foreach ($found->diff($foreign) as $item) {
                $this->equipment->log($item, $by, 'inventory', 'Vorhanden ('.$location->name.')');
            }
            foreach ($missing as $item) {
                $this->equipment->log($item, $by, 'inventory', 'Bei der Inventur in '.$location->name.' nicht gefunden');
            }
            EquipmentService::managedEquipmentFor($by)->whereIn('id', $found->pluck('id'))->update(['last_seen_at' => now()]);

            return Stocktake::create([
                'location_id' => $location->id, 'ext_location_id' => $standortId,
                'location_name' => $location->name, 'user_id' => $by->id, 'expected' => $expected->count(),
                'found' => $found->pluck('id')->values()->all(),
                'missing' => $missing->map(fn (Equipment $e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values()->all(),
                'moved' => $moved, 'unknown' => array_values(array_unique(array_filter(array_map('trim', $unknown)))),
                'notes' => filled($notes) ? mb_substr(trim($notes), 0, 2000) : null, 'finished_at' => now(),
            ]);
        });
    }
}
