<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
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
    public function expected(Location $location): Collection
    {
        return Equipment::inService()->with(['category', 'compartment'])->where('location_id', $location->id)
            ->orderBy('compartment_id')->orderBy('name')->get();
    }

    /**
     * @param  list<int>  $foundIds
     * @param  list<string>  $unknown  scanned codes nobody knows
     */
    public function finish(User $by, Location $location, array $foundIds, array $unknown = [], bool $moveHere = true, ?string $notes = null): Stocktake
    {
        if (! $by->canManageAusruestung()) {
            throw ValidationException::withMessages(['stocktake' => 'Die Inventur machen die Gerätewarte.']);
        }

        return DB::transaction(function () use ($by, $location, $foundIds, $unknown, $moveHere, $notes) {
            $expected = $this->expected($location);
            $found = Equipment::with(['location', 'compartment.location'])->whereIn('id', array_unique(array_map('intval', $foundIds)))->get();
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
            Equipment::whereIn('id', $found->pluck('id'))->update(['last_seen_at' => now()]);

            return Stocktake::create([
                'location_id' => $location->id, 'location_name' => $location->name, 'user_id' => $by->id, 'expected' => $expected->count(),
                'found' => $found->pluck('id')->values()->all(),
                'missing' => $missing->map(fn (Equipment $e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values()->all(),
                'moved' => $moved, 'unknown' => array_values(array_unique(array_filter(array_map('trim', $unknown)))),
                'notes' => filled($notes) ? mb_substr(trim($notes), 0, 2000) : null, 'finished_at' => now(),
            ]);
        });
    }
}
