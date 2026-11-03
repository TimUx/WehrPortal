<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\TagAssignment;
use Modules\Atemschutz\src\Models\Wearer;

class TagAssignmentService
{
    public static function normalize(?string $value): ?string
    {
        $uid = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $value));

        return $uid === '' ? null : $uid;
    }

    public function assignToWearer(Wearer $wearer, string $uid, User $actor, string $standard = 'rfid'): TagAssignment
    {
        if (! MonitorService::canCareFor($actor, $wearer->user_id)) {
            abort(403);
        }

        return $this->assign(['wearer_id' => $wearer->id], $uid, $actor, $standard);
    }

    public function assignToEquipment(Equipment $equipment, string $uid, User $actor, string $standard = 'rfid'): TagAssignment
    {
        if (! MonitorService::canManageEquipment($actor)) {
            abort(403);
        }

        return $this->assign(['equipment_id' => $equipment->id], $uid, $actor, $standard);
    }

    public function deactivate(TagAssignment $assignment, User $actor): void
    {
        $assignment->loadMissing(['wearer', 'equipment']);
        $allowed = $assignment->wearer
            ? MonitorService::canCareFor($actor, $assignment->wearer->user_id)
            : MonitorService::canManageEquipment($actor);
        abort_unless($allowed, 403);

        DB::transaction(function () use ($assignment, $actor) {
            $locked = TagAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->deactivated_at !== null) {
                return;
            }

            $locked->forceFill([
                'deactivated_by' => $actor->id,
                'deactivated_at' => now(),
            ])->save();
        });
    }

    public function findActive(string $uid): ?TagAssignment
    {
        $normalized = self::normalize($uid);
        if ($normalized === null) {
            return null;
        }

        return TagAssignment::query()->active()->where('tag_uid', $normalized)->with(['wearer.user', 'equipment'])->first();
    }

    /**
     * @param  array{wearer_id?: int, equipment_id?: int}  $target
     */
    private function assign(array $target, string $uid, User $actor, string $standard): TagAssignment
    {
        $normalized = self::normalize($uid);
        $data = Validator::make(['tag_uid' => $normalized, 'standard' => $standard], [
            'tag_uid' => ['required', 'string', 'max:100'],
            'standard' => ['required', Rule::in(['rfid', 'nfc', 'barcode'])],
        ])->validate();

        return DB::transaction(function () use ($target, $data, $actor) {
            $active = TagAssignment::query()->active()->where('tag_uid', $data['tag_uid'])->lockForUpdate()->first();
            if ($active) {
                $sameTarget = ($target['wearer_id'] ?? null) === $active->wearer_id
                    && ($target['equipment_id'] ?? null) === $active->equipment_id;
                if ($sameTarget) {
                    return $active;
                }

                throw ValidationException::withMessages([
                    'tag_uid' => 'Diese Kennung ist bereits einem anderen Atemschutzobjekt zugeordnet. Deaktiviere zuerst die bisherige Zuordnung.',
                ]);
            }

            try {
                return TagAssignment::query()->create([
                    ...$target,
                    'tag_uid' => $data['tag_uid'],
                    'standard' => $data['standard'],
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23505') {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'tag_uid' => 'Diese Kennung wurde gleichzeitig einem anderen Atemschutzobjekt zugeordnet. Bitte erneut prüfen.',
                ]);
            }
        });
    }
}
