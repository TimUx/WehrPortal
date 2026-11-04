<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\User;
use App\Support\FileResponse;
use App\Support\SignatureImage;
use Extensions\Locations\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentComponent;
use Modules\Atemschutz\src\Models\EquipmentDocument;
use Modules\Atemschutz\src\Models\EquipmentEvent;
use Modules\Atemschutz\src\Models\EquipmentInspection;
use Modules\Atemschutz\src\Models\EquipmentTask;
use Modules\Atemschutz\src\Models\EquipmentType;
use Modules\Atemschutz\src\Models\EquipmentTypeEvent;

class EquipmentService
{
    public const MAX_DOCUMENT_KB = 25600;

    public function uploadDocument(Equipment $equipment, array $data, User $actor): EquipmentDocument
    {
        $this->authorize($actor);

        $data = Validator::make($data, [
            'kind' => ['required', Rule::in(array_keys(EquipmentDocument::KINDS))],
            'title' => ['required', 'string', 'max:180'],
            'file' => ['required', 'file', 'max:'.self::MAX_DOCUMENT_KB],
        ])->validate();
        $file = $data['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Bitte eine gültige Datei auswählen.']);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, FileResponse::allowedExtensions(), true)) {
            throw ValidationException::withMessages(['file' => 'Dieser Dateityp ist nicht zugelassen.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['file' => 'Die Datei konnte nicht gelesen werden.']);
        }

        return $equipment->documents()->create([
            'kind' => $data['kind'],
            'title' => $data['title'],
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'size' => strlen($bytes),
            'data' => base64_encode($bytes),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function deleteDocument(EquipmentDocument $document, Equipment $equipment, User $actor): void
    {
        $this->authorize($actor);
        abort_unless((int) $document->equipment_id === (int) $equipment->id, 404);
        $document->delete();
    }

    public function createEquipment(array $data, User $actor): Equipment
    {
        $this->authorize($actor);
        $this->assertLocationAvailable($data['ext_location_id'] ?? null);
        $locationRule = config('locations.enabled', false) && Schema::hasTable('x_locations')
            ? ['nullable', 'integer', 'exists:x_locations,id']
            : ['nullable', 'integer'];

        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:180'],
            'type_id' => ['required', 'integer', 'exists:as_equipment_types,id'],
            'device_number' => ['nullable', 'string', 'max:80', 'unique:as_equipment,device_number'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'model' => ['nullable', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:150'],
            'manufactured_at' => ['nullable', 'date'],
            'commissioned_at' => ['nullable', 'date'],
            'purchased_at' => ['nullable', 'date'],
            'fill_pressure_bar' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'last_filled_on' => ['nullable', 'date'],
            'next_inspection_at' => ['nullable', 'date'],
            'inspection_basis' => ['nullable', 'string', 'max:10000'],
            'specifications' => ['nullable', 'array'],
            'ext_location_id' => $locationRule,
            'physical_location' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($data, $actor) {
            $type = EquipmentType::query()->findOrFail($data['type_id']);
            if (! $type->is_active) {
                throw ValidationException::withMessages([
                    'type_id' => 'Dieser Gerätetyp ist nicht mehr aktiv.',
                ]);
            }
            $specifications = array_key_exists('specifications', $data) && is_array($data['specifications'])
                ? $data['specifications']
                : [];
            $data['specifications'] = $this->validatedSpecifications($type, $specifications);

            $equipment = Equipment::query()->create([
                ...$data,
                'code' => 'TMP-'.Str::ulid(),
                'specifications' => $data['specifications'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $equipment->forceFill([
                'code' => 'AS-'.str_pad((string) $equipment->id, 6, '0', STR_PAD_LEFT),
            ])->save();

            return $equipment->refresh();
        });
    }

    public function updateEquipment(Equipment $equipment, array $data, User $actor): Equipment
    {
        $this->authorize($actor);
        $this->assertLocationAvailable($data['ext_location_id'] ?? null);
        $locationRule = config('locations.enabled', false) && Schema::hasTable('x_locations')
            ? ['nullable', 'integer', 'exists:x_locations,id']
            : ['nullable', 'integer'];

        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:180'],
            'type_id' => ['required', 'integer', 'exists:as_equipment_types,id'],
            'device_number' => ['nullable', 'string', 'max:80', Rule::unique('as_equipment', 'device_number')->ignore($equipment->id)],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'model' => ['nullable', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:150'],
            'manufactured_at' => ['nullable', 'date'],
            'commissioned_at' => ['nullable', 'date'],
            'purchased_at' => ['nullable', 'date'],
            'fill_pressure_bar' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'last_filled_on' => ['nullable', 'date'],
            'next_inspection_at' => ['nullable', 'date'],
            'inspection_basis' => ['nullable', 'string', 'max:10000'],
            'specifications' => ['nullable', 'array'],
            'ext_location_id' => $locationRule,
            'physical_location' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($equipment, $data, $actor) {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            $type = EquipmentType::query()->findOrFail($data['type_id']);
            if (! $type->is_active && (int) $locked->type_id !== (int) $type->id) {
                throw ValidationException::withMessages([
                    'type_id' => 'Dieser Gerätetyp ist nicht mehr aktiv.',
                ]);
            }
            $specifications = array_key_exists('specifications', $data) && is_array($data['specifications'])
                ? $data['specifications']
                : [];
            $data['specifications'] = $this->validatedSpecifications($type, $specifications);

            $newPhysicalLocation = array_key_exists('physical_location', $data)
                ? $data['physical_location']
                : $locked->physical_location;
            $newOrganizationId = array_key_exists('ext_location_id', $data)
                ? $data['ext_location_id']
                : $locked->ext_location_id;
            $locationChanged = $locked->physical_location !== $newPhysicalLocation
                || (int) $locked->ext_location_id !== (int) $newOrganizationId;
            $beforeLocation = $locked->physical_location;
            $beforeOrganization = $this->organizationName($locked->ext_location_id);
            $afterOrganization = $this->organizationName($newOrganizationId);
            $locked->fill([
                ...$data,
                'specifications' => $data['specifications'],
                'updated_by' => $actor->id,
            ])->save();

            if ($locationChanged) {
                $this->recordEvent(
                    $locked,
                    $actor,
                    'relocated',
                    $locked->status,
                    $locked->status,
                    $beforeLocation,
                    $newPhysicalLocation,
                    null,
                    $beforeOrganization,
                    $afterOrganization,
                );
            }

            return $locked->refresh();
        });
    }

    public function sendToWorkshop(Equipment $equipment, string $workshop, string $reason, User $actor): Equipment
    {
        $this->authorize($actor);
        $data = Validator::make([
            'workshop' => $workshop,
            'reason' => $reason,
        ], [
            'workshop' => ['required', 'string', 'max:180'],
            'reason' => ['required', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($equipment, $data, $actor) {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            if (in_array($locked->status, [Equipment::STATUS_IN_REPAIR, Equipment::STATUS_RETIRED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Dieses Gerät kann nicht in eine weitere Werkstatt gegeben werden.',
                ]);
            }

            $fromLocation = $locked->physical_location;
            $fromStatus = $locked->status;
            $locked->forceFill([
                'status' => Equipment::STATUS_IN_REPAIR,
                'physical_location' => $data['workshop'],
                'updated_by' => $actor->id,
            ])->save();
            $this->recordEvent(
                $locked,
                $actor,
                'workshop_sent',
                $fromStatus,
                $locked->status,
                $fromLocation,
                $locked->physical_location,
                $data['reason'],
            );

            return $locked->refresh();
        });
    }

    public function returnFromWorkshop(
        Equipment $equipment,
        string $destination,
        string $status,
        bool $confirmed,
        string $notes,
        User $actor,
    ): Equipment {
        $this->authorize($actor);
        $data = Validator::make([
            'destination' => $destination,
            'status' => $status,
            'confirmed' => $confirmed,
            'notes' => $notes,
        ], [
            'destination' => ['required', 'string', 'max:180'],
            'status' => ['required', Rule::in([Equipment::STATUS_AVAILABLE, Equipment::STATUS_DEFECTIVE])],
            'confirmed' => ['accepted'],
            'notes' => ['required', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($equipment, $data, $actor) {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            if ($locked->status !== Equipment::STATUS_IN_REPAIR) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Geräte in Reparatur können aus der Werkstatt zurückgenommen werden.',
                ]);
            }

            $fromLocation = $locked->physical_location;
            $locked->forceFill([
                'status' => $data['status'],
                'physical_location' => $data['destination'],
                'updated_by' => $actor->id,
            ])->save();
            $this->recordEvent(
                $locked,
                $actor,
                'workshop_returned',
                Equipment::STATUS_IN_REPAIR,
                $locked->status,
                $fromLocation,
                $locked->physical_location,
                $data['notes'],
            );

            return $locked->refresh();
        });
    }

    public function changeStatus(Equipment $equipment, string $status, string $reason, User $actor): Equipment
    {
        $this->authorize($actor);
        $data = Validator::make([
            'status' => $status,
            'reason' => $reason,
        ], [
            'status' => ['required', Rule::in([Equipment::STATUS_DEFECTIVE, Equipment::STATUS_RETIRED])],
            'reason' => ['required', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($equipment, $data, $actor) {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            if ($locked->status === Equipment::STATUS_RETIRED) {
                throw ValidationException::withMessages([
                    'status' => 'Ein ausgesondertes Gerät kann nicht erneut geändert werden.',
                ]);
            }

            $beforeStatus = $locked->status;
            $locked->forceFill([
                'status' => $data['status'],
                'updated_by' => $actor->id,
            ])->save();
            $this->recordEvent(
                $locked,
                $actor,
                $data['status'] === Equipment::STATUS_RETIRED ? 'retired' : 'marked_defective',
                $beforeStatus,
                $locked->status,
                $locked->physical_location,
                $locked->physical_location,
                $data['reason'],
            );

            return $locked->refresh();
        });
    }

    public function updateType(EquipmentType $type, array $data, User $actor): EquipmentType
    {
        $this->authorize($actor);

        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'minimum_fill_pressure_bar' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($type, $data, $actor) {
            $locked = EquipmentType::query()->lockForUpdate()->findOrFail($type->id);
            if ($locked->isCylinder() && $data['is_active'] && $data['minimum_fill_pressure_bar'] === null) {
                throw ValidationException::withMessages([
                    'minimum_fill_pressure_bar' => 'Für einen aktiven Flaschentyp muss ein Mindestfülldruck festgelegt werden.',
                ]);
            }

            $before = $locked->only(['name', 'minimum_fill_pressure_bar', 'is_active']);
            $locked->fill([
                ...$data,
                'updated_by' => $actor->id,
            ])->save();
            if ($locked->wasChanged(['name', 'minimum_fill_pressure_bar', 'is_active'])) {
                EquipmentTypeEvent::query()->create([
                    'type_id' => $locked->id,
                    'from_name' => $before['name'],
                    'to_name' => $locked->name,
                    'from_minimum_fill_pressure_bar' => $before['minimum_fill_pressure_bar'],
                    'to_minimum_fill_pressure_bar' => $locked->minimum_fill_pressure_bar,
                    'from_is_active' => $before['is_active'],
                    'to_is_active' => $locked->is_active,
                    'user_id' => $actor->id,
                    'changed_at' => now(),
                ]);
            }

            return $locked->refresh();
        });
    }

    public function recordInspection(Equipment $equipment, array $data, User $actor): EquipmentInspection
    {
        $this->authorize($actor);

        $data = Validator::make($data, [
            'performed_on' => ['required', 'date', 'before_or_equal:today'],
            'next_due_on' => ['nullable', 'date', 'after:performed_on'],
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'checklist' => ['required', 'array', 'min:1'],
            'checklist.*' => ['array'],
            'findings' => ['nullable', 'string', 'max:10000'],
            'inspector_name' => ['required', 'string', 'max:150'],
            'qualification' => ['required', 'string', 'max:150'],
            'inspector_number' => ['nullable', 'string', 'max:80'],
            'provider_name' => ['nullable', 'string', 'max:150'],
            'signature' => ['required', 'string'],
        ])->validate();

        $signature = SignatureImage::fromDataUrl($data['signature']);
        unset($data['signature']);

        return DB::transaction(function () use ($equipment, $data, $signature, $actor) {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            $resultingStatus = match ($locked->status) {
                Equipment::STATUS_RETIRED => Equipment::STATUS_RETIRED,
                Equipment::STATUS_IN_REPAIR => Equipment::STATUS_IN_REPAIR,
                default => $data['result'] === 'failed'
                    ? Equipment::STATUS_DEFECTIVE
                    : Equipment::STATUS_AVAILABLE,
            };
            $beforeStatus = $locked->status;

            $inspectionData = [
                ...$data,
                'equipment_id' => $locked->id,
                'inspector_user_id' => $actor->id,
                'recorded_by' => $actor->id,
                'signature' => $signature,
                'signed_at' => now(),
            ];
            $inspectionData['content_hash'] = hash(
                'sha256',
                json_encode($inspectionData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $inspection = EquipmentInspection::query()->create($inspectionData);

            $locked->forceFill([
                'next_inspection_at' => $data['next_due_on'] ?? null,
                'status' => $resultingStatus,
                'updated_by' => $actor->id,
            ])->save();

            $this->recordEvent(
                $locked,
                $actor,
                'inspection',
                $beforeStatus,
                $resultingStatus,
                $locked->physical_location,
                $locked->physical_location,
                $data['findings'] ?? null,
            );

            return $inspection;
        });
    }

    public function completeTask(
        EquipmentTask $task,
        ?EquipmentInspection $inspection,
        User $actor,
    ): EquipmentTask {
        $this->authorize($actor);

        return DB::transaction(function () use ($task, $inspection) {
            $locked = EquipmentTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages([
                    'task' => 'Nur offene Aufgaben können erledigt werden.',
                ]);
            }
            if ($inspection !== null
                && ((int) $inspection->equipment_id !== (int) $locked->equipment_id
                    || ! $inspection->exists)) {
                throw ValidationException::withMessages([
                    'inspection_id' => 'Die Prüfung gehört nicht zu diesem Gerät.',
                ]);
            }

            $locked->forceFill([
                'status' => 'completed',
                'inspection_id' => $inspection?->id,
                'completed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function syncComponents(Equipment $equipment, array $components, User $actor): void
    {
        $this->authorize($actor);
        $componentIds = array_values(array_unique(array_map('intval', $components)));

        if (count($componentIds) !== count($components)) {
            throw ValidationException::withMessages([
                'components' => 'Ein Gerät kann nur einmal als Komponente zugeordnet werden.',
            ]);
        }

        DB::transaction(function () use ($equipment, $componentIds, $actor) {
            $parent = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            $children = Equipment::query()
                ->whereIn('id', $componentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($children->count() !== count($componentIds) || in_array($parent->id, $componentIds, true)) {
                throw ValidationException::withMessages([
                    'components' => 'Die Komponentenauswahl ist ungültig.',
                ]);
            }

            foreach ($children as $child) {
                $currentParentId = $child->componentOf()->value('equipment_id');
                if ($currentParentId !== null && (int) $currentParentId !== (int) $parent->id) {
                    throw ValidationException::withMessages([
                        'components' => 'Eine Komponente kann nur einem Gerät zugeordnet sein.',
                    ]);
                }

                if ($this->containsComponent($child, (int) $parent->id)) {
                    throw ValidationException::withMessages([
                        'components' => 'Die Zuordnung würde einen Komponenten-Zyklus erzeugen.',
                    ]);
                }
            }

            $existing = EquipmentComponent::query()->where('equipment_id', $parent->id)->get();
            foreach ($existing as $relation) {
                if (! in_array((int) $relation->component_equipment_id, $componentIds, true)) {
                    $component = Equipment::query()->find($relation->component_equipment_id);
                    $this->recordEvent(
                        $parent,
                        $actor,
                        'component_removed',
                        $parent->status,
                        $parent->status,
                        $parent->physical_location,
                        $parent->physical_location,
                        $component ? 'Komponente entfernt: '.$component->code.' '.$component->name : 'Komponente entfernt',
                    );
                }
            }
            $relations = EquipmentComponent::query()->where('equipment_id', $parent->id);
            if ($componentIds === []) {
                $relations->delete();
            } else {
                $relations->whereNotIn('component_equipment_id', $componentIds)->delete();
            }
            foreach ($componentIds as $componentId) {
                $wasAttached = $existing->contains(fn (EquipmentComponent $relation) => (int) $relation->component_equipment_id === $componentId);
                EquipmentComponent::query()->updateOrCreate(
                    [
                        'equipment_id' => $parent->id,
                        'component_equipment_id' => $componentId,
                    ],
                    ['role' => 'Komponente'],
                );
                if (! $wasAttached) {
                    $component = $children->get($componentId);
                    $this->recordEvent(
                        $parent,
                        $actor,
                        'component_attached',
                        $parent->status,
                        $parent->status,
                        $parent->physical_location,
                        $parent->physical_location,
                        'Komponente zugeordnet: '.$component->code.' '.$component->name,
                    );
                }
            }
        });
    }

    public function createTask(Equipment $equipment, array $data, User $actor): EquipmentTask
    {
        $this->authorize($actor);
        $data = Validator::make($data, [
            'title' => ['required', 'string', 'max:180'],
            'due_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return $equipment->tasks()->create([
            ...$data,
            'created_by' => $actor->id,
        ]);
    }

    private function authorize(User $user): void
    {
        abort_unless(MonitorService::canManageEquipment($user), 403);
    }

    private function validatedSpecifications(EquipmentType $type, array $specifications): array
    {
        $fields = match ($type->family) {
            'mask' => [
                'mask_type' => ['nullable', 'string', 'max:120'],
                'size' => ['nullable', 'string', 'max:80'],
                'visor' => ['nullable', 'string', 'max:120'],
                'connection' => ['nullable', 'string', 'max:120'],
                'material' => ['nullable', 'string', 'max:120'],
            ],
            'apparatus' => [
                'design' => ['nullable', 'string', 'max:120'],
                'harness' => ['nullable', 'string', 'max:120'],
                'pressure_reducer' => ['nullable', 'string', 'max:120'],
                'warning_device' => ['nullable', 'string', 'max:120'],
            ],
            'cylinder' => [
                'material' => ['nullable', 'string', 'max:120'],
                'construction' => ['nullable', 'string', 'max:120'],
                'volume_l' => ['nullable', 'numeric', 'gt:0', 'max:100'],
                'working_pressure_bar' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'test_pressure_bar' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'valve_type' => ['nullable', 'string', 'max:120'],
            ],
            default => [],
        };
        $allowed = array_keys($fields);
        if ($allowed === []) {
            return [];
        }

        $rules = ['specifications' => ['array:'.implode(',', $allowed)]];
        foreach ($fields as $field => $fieldRules) {
            $rules['specifications.'.$field] = $fieldRules;
        }

        return Validator::make(['specifications' => $specifications], $rules)
            ->validate()['specifications'];
    }

    private function containsComponent(Equipment $equipment, int $targetId): bool
    {
        foreach ($equipment->components()->with('component')->get() as $relation) {
            $component = $relation->component;
            if ((int) $component->id === $targetId || $this->containsComponent($component, $targetId)) {
                return true;
            }
        }

        return false;
    }

    private function recordEvent(
        Equipment $equipment,
        User $actor,
        string $kind,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $fromLocation,
        ?string $toLocation,
        ?string $notes = null,
        ?string $fromOrganization = null,
        ?string $toOrganization = null,
    ): void {
        $organization = $this->organizationName($equipment->ext_location_id);
        EquipmentEvent::query()->create([
            'equipment_id' => $equipment->id,
            'kind' => $kind,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_organization' => $fromOrganization ?? $organization,
            'to_organization' => $toOrganization ?? $organization,
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'notes' => $notes,
            'user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    private function organizationName(?int $locationId): ?string
    {
        return $locationId !== null && Schema::hasTable('x_locations')
            ? Location::query()->whereKey($locationId)->value('name')
            : null;
    }

    private function assertLocationAvailable(mixed $locationId): void
    {
        if (filled($locationId) && (! config('locations.enabled', false) || ! Schema::hasTable('x_locations'))) {
            throw ValidationException::withMessages([
                'ext_location_id' => 'Die Standortzuordnung ist nicht verfügbar.',
            ]);
        }
    }
}
