<?php

use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use App\Services\ScanRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource;
use Modules\Atemschutz\src\Filament\Resources\EquipmentResource\Pages\EditEquipment;
use Modules\Atemschutz\src\Models\Equipment;
use Modules\Atemschutz\src\Models\EquipmentDocument;
use Modules\Atemschutz\src\Models\EquipmentInspection;
use Modules\Atemschutz\src\Models\EquipmentType;
use Modules\Atemschutz\src\Models\TagAssignment;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Models\WearerQualification;
use Modules\Atemschutz\src\Services\EquipmentService;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;
use Modules\Atemschutz\src\Services\TagAssignmentService;
use Modules\Atemschutz\src\Services\WearerService;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Module::where('key', 'atemschutz')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->admin()->create();
    $this->wart = User::factory()->create(['name' => 'Anton Atemschutzwart']);
    $this->wart->moduleAccess()->create([
        'module' => 'atemschutz',
        'role' => 'atemschutzwart',
    ]);
    $this->service = app(EquipmentService::class);
});

function atemschutzEquipment(User $actor, array $data = []): Equipment
{
    $type = EquipmentType::query()->where('code', 'atemschutzmaske')->firstOrFail();

    return app(EquipmentService::class)->createEquipment([
        'name' => 'Maske '.($type->equipment()->count() + 1),
        'type_id' => $type->id,
        ...$data,
    ], $actor);
}

test('only the global Atemschutzgerätewart may manage the equipment across locations', function () {
    $ordinary = User::factory()->create();
    expect(MonitorService::canManageEquipment($this->wart))->toBeTrue()
        ->and(MonitorService::canManageEquipment($ordinary))->toBeFalse();

    if (Schema::hasTable('x_locations')) {
        config(['locations.enabled' => true]);
        $location = Location::create([
            'name' => 'Atemschutz Nord',
            'slug' => 'atemschutz-nord',
        ]);
        $wehrfuehrung = User::factory()->create(['ext_location_id' => $location->id]);
        app(StandardRoleAssignments::class)->grant(
            $this->admin,
            $wehrfuehrung->id,
            'wehrfuehrung',
            $location->id,
            null,
            'Standortbezogene Wehrführung',
        );

        expect(MonitorService::canCare($wehrfuehrung))->toBeTrue()
            ->and(MonitorService::canManageEquipment($wehrfuehrung))->toBeFalse();

        $equipment = atemschutzEquipment($this->wart);
        $this->actingAs($wehrfuehrung)
            ->get(EquipmentResource::getUrl('edit', ['record' => $equipment]))
            ->assertForbidden();
        expect(fn () => app(TagAssignmentService::class)->assignToEquipment($equipment, '0400AABB', $wehrfuehrung))
            ->toThrow(HttpException::class);
    }
});

test('a cylinder is ready at its configured minimum but not below it or when empty', function () {
    $type = EquipmentType::query()->where('code', 'atemluftflasche-stahl')->firstOrFail();
    $cylinder = $this->service->createEquipment([
        'name' => 'Flasche Stahl 1',
        'type_id' => $type->id,
        'fill_pressure_bar' => 200,
    ], $this->wart);

    expect($cylinder->isOperationallyReady())->toBeFalse();

    $this->service->updateType($type, [
        'name' => $type->name,
        'minimum_fill_pressure_bar' => 200,
        'is_active' => true,
    ], $this->wart);
    expect($cylinder->fresh()->isOperationallyReady())->toBeTrue();

    $cylinder = $this->service->updateEquipment($cylinder, [
        'name' => $cylinder->name,
        'type_id' => $type->id,
        'fill_pressure_bar' => 199,
    ], $this->wart);
    expect($cylinder->isOperationallyReady())->toBeFalse();

    $cylinder = $this->service->updateEquipment($cylinder, [
        'name' => $cylinder->name,
        'type_id' => $type->id,
        'fill_pressure_bar' => 0,
    ], $this->wart);
    expect($cylinder->isOperationallyReady())->toBeFalse();
});

test('workshop return requires a destination and explicit status confirmation', function () {
    $equipment = atemschutzEquipment($this->wart);
    $this->service->sendToWorkshop($equipment, 'Prüfstelle Musterstadt', 'Jahresprüfung', $this->wart);

    expect($equipment->fresh()->status)->toBe(Equipment::STATUS_IN_REPAIR);
    expect(fn () => $this->service->returnFromWorkshop(
        $equipment,
        'Wache',
        Equipment::STATUS_AVAILABLE,
        false,
        'Prüfung abgeschlossen',
        $this->wart,
    ))->toThrow(ValidationException::class);

    $returned = $this->service->returnFromWorkshop(
        $equipment,
        'Wache',
        Equipment::STATUS_DEFECTIVE,
        true,
        'Mangel festgestellt',
        $this->wart,
    );

    expect($returned->status)->toBe(Equipment::STATUS_DEFECTIVE)
        ->and($returned->isOperationallyReady())->toBeFalse()
        ->and($returned->events()->count())->toBe(2);
});

test('an inspection is signed, updates the next due date, and cannot be changed afterwards', function () {
    $equipment = atemschutzEquipment($this->wart);
    $inspection = $this->service->recordInspection($equipment, [
        'performed_on' => today()->toDateString(),
        'next_due_on' => today()->addYear()->toDateString(),
        'result' => 'passed',
        'checklist' => [
            ['item' => 'Dichtheit', 'result' => 'passed'],
        ],
        'inspector_name' => 'Hanna Hydrant',
        'qualification' => 'Befähigte Person',
        'findings' => 'Ohne Befund',
        'signature' => signaturePng(),
    ], $this->wart);

    expect($inspection)->toBeInstanceOf(EquipmentInspection::class)
        ->and($equipment->fresh()->next_inspection_at->toDateString())->toBe(today()->addYear()->toDateString())
        ->and($equipment->fresh()->isOperationallyReady())->toBeTrue();

    expect(fn () => $inspection->update(['findings' => 'Geändert']))
        ->toThrow(QueryException::class);
});

test('G26.3 examinations are date-only history and readiness uses the latest entry', function () {
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $wearer = Wearer::query()->create(['user_id' => $member->id]);
    $service = app(WearerService::class);

    $service->recordExamination($wearer, [
        'examined_on' => today()->subYears(2)->toDateString(),
        'valid_until' => today()->addYear()->toDateString(),
    ], $this->wart);
    $service->recordExamination($wearer, [
        'examined_on' => today()->subYear()->toDateString(),
        'valid_until' => today()->subDay()->toDateString(),
    ], $this->wart);

    expect(app(ReadinessService::class)->forWearer($wearer)['items']['g263']['ok'])->toBeFalse()
        ->and(Schema::hasColumn('as_wearer_examinations', 'findings'))->toBeFalse()
        ->and(Schema::hasColumn('as_wearer_examinations', 'diagnosis'))->toBeFalse()
        ->and(Schema::hasColumn('as_wearers', 'g263_valid_until'))->toBeFalse()
        ->and(Schema::hasColumn('as_wearers', 'rfid_uid'))->toBeFalse()
        ->and(Schema::hasColumn('as_equipment', 'rfid'))->toBeFalse();
});

test('RFID assignments are normalized, globally unique while active, and retain reassignment history', function () {
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $wearer = Wearer::query()->create(['user_id' => $member->id]);
    $equipment = atemschutzEquipment($this->wart);
    $otherEquipment = atemschutzEquipment($this->wart);
    $tags = app(TagAssignmentService::class);

    $first = $tags->assignToWearer($wearer, '04-a1 b2-c3', $this->wart);
    $tags->assignToWearer($wearer, '04FFEE11', $this->wart);
    expect($first->tag_uid)->toBe('04A1B2C3')
        ->and($tags->findActive('04:A1:B2:C3')?->wearer_id)->toBe($wearer->id);

    expect(fn () => $tags->assignToEquipment($equipment, '04A1B2C3', $this->wart))
        ->toThrow(ValidationException::class);

    $tags->deactivate($first, $this->wart);
    $reassignment = $tags->assignToEquipment($equipment, '04A1B2C3', $this->wart);

    expect($reassignment->equipment_id)->toBe($equipment->id)
        ->and(TagAssignment::query()->where('tag_uid', '04A1B2C3')->count())->toBe(2)
        ->and($tags->findActive('04A1B2C3')?->equipment_id)->toBe($equipment->id);

    expect(fn () => $tags->assignToEquipment($otherEquipment, '04A1B2C3', $this->wart))
        ->toThrow(ValidationException::class);
    expect(fn () => TagAssignment::query()->create([
        'equipment_id' => $otherEquipment->id,
        'tag_uid' => '04A1B2C3',
        'assigned_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('equipment documents are served privately through an authorized response', function () {
    $equipment = atemschutzEquipment($this->wart);
    $document = app(EquipmentService::class)->uploadDocument($equipment, [
        'kind' => 'manual',
        'title' => 'Betriebsanleitung',
        'file' => UploadedFile::fake()->createWithContent('geraet.pdf', '%PDF-1.4 test document'),
    ], $this->wart);

    $this->actingAs($this->wart)
        ->get(route('atemschutz.equipment-document', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertContent('%PDF-1.4 test document');

    $ordinary = User::factory()->create();
    $ordinary->moduleAccess()->create(['module' => 'atemschutz']);
    $this->actingAs($ordinary)->get(route('atemschutz.equipment-document', $document))->assertForbidden();

    expect(EquipmentDocument::query()->findOrFail($document->id)->data)->not->toBeEmpty()
        ->and(fn () => app(EquipmentService::class)->uploadDocument($equipment, [
            'kind' => 'other',
            'title' => 'Unzulässige Datei',
            'file' => UploadedFile::fake()->createWithContent('payload.exe', 'not executable'),
        ], $this->wart))->toThrow(ValidationException::class);
});

test('qualification evidence is private, extension-checked and removed with the wearer', function () {
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $wearer = Wearer::create(['user_id' => $member->id]);
    $qualification = app(WearerService::class)->recordQualification($wearer, [
        'kind' => 'other',
        'title' => 'Maskenprüfer',
        'proof' => UploadedFile::fake()->createWithContent('nachweis.pdf', '%PDF-1.4 qualification'),
    ], $this->wart);

    $this->actingAs($this->wart)
        ->get(route('atemschutz.qualification-proof', $qualification))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertContent('%PDF-1.4 qualification');

    $ordinary = User::factory()->create();
    $ordinary->moduleAccess()->create(['module' => 'atemschutz']);
    $this->actingAs($ordinary)->get(route('atemschutz.qualification-proof', $qualification))->assertForbidden();

    expect(fn () => app(WearerService::class)->recordQualification($wearer, [
        'kind' => 'other',
        'title' => 'Nicht zulässiger Beleg',
        'proof' => UploadedFile::fake()->createWithContent('payload.exe', 'not executable'),
    ], $this->wart))->toThrow(ValidationException::class)
        ->and(WearerQualification::query()->findOrFail($qualification->id)->proof_data)->not->toBeEmpty();

    app(MemberRemoval::class)->transferAndDelete($member, User::factory()->create(), $this->admin);
    expect(WearerQualification::query()->whereKey($qualification->id)->exists())->toBeFalse();
});

test('equipment record page renders its history and private-document managers', function () {
    $equipment = atemschutzEquipment($this->wart);

    Livewire::actingAs($this->wart)
        ->test(EditEquipment::class, ['record' => $equipment->id])
        ->assertOk()
        ->assertSee('Dokumente und Fotos')
        ->assertSee('Kennzeichnungen')
        ->assertSee('Prüfungen');
});

test('equipment scan routes resolve only for the global Atemschutzgerätewart', function () {
    $equipment = atemschutzEquipment($this->wart);
    app(TagAssignmentService::class)->assignToEquipment($equipment, '04-a1 b2-c3', $this->wart);
    $registry = app(ScanRegistry::class);

    $this->actingAs($this->wart);
    expect($registry->resolve('04:A1:B2:C3'))->toContain('atemschutz/ausruestung');

    $ordinary = User::factory()->create();
    $ordinary->moduleAccess()->create(['module' => 'atemschutz']);
    $this->actingAs($ordinary);
    expect($registry->resolve('04A1B2C3'))->toBeNull();
});

test('G26.3 history is deleted with a member and never transferred to another person', function () {
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $replacement = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $wearer = Wearer::query()->create(['user_id' => $member->id]);
    $examination = app(WearerService::class)->recordExamination($wearer, [
        'examined_on' => today()->subMonth()->toDateString(),
        'valid_until' => today()->addYears(2)->toDateString(),
    ], $this->wart);

    app(MemberRemoval::class)->transferAndDelete($member, $replacement, $this->admin);

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(Wearer::query()->whereKey($wearer->id)->exists())->toBeFalse()
        ->and(DB::table('as_wearer_examinations')->where('id', $examination->id)->exists())->toBeFalse()
        ->and(Wearer::query()->where('user_id', $replacement->id)->exists())->toBeFalse();
});
