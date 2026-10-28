<?php

use App\Livewire\Dashboard\Home;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Livewire\DeviceDetail;
use Modules\Wartung\src\Livewire\Overview;
use Modules\Wartung\src\Livewire\ProtocolDetail;
use Modules\Wartung\src\Livewire\ProtocolForm;
use Modules\Wartung\src\Livewire\TemplateForm;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Notifications\WartungDigestNotification;
use Modules\Wartung\src\Services\WartungService;

beforeEach(function () {
    Module::whereIn('key', ['ausruestung', 'wartung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->gw = User::factory()->create(['name' => 'Gerd Gerätewart']);
    $this->gw->moduleAccess()->create(['module' => 'wartung', 'role' => 'geraetewart']);
    $this->gw->moduleAccess()->create(['module' => 'ausruestung', 'role' => 'geraetewart']);
    $this->pruefer = User::factory()->create(['name' => 'Paula Prüfer']);
    $this->pruefer->moduleAccess()->create(['module' => 'wartung', 'role' => 'pruefer']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'wartung']);

    $this->w = app(WartungService::class);
    $this->lines = Category::where('name', 'Feuerwehrleine')->firstOrFail();
    $this->line = Equipment::create(['name' => 'Feuerwehrleine 30 m', 'category_id' => $this->lines->id, 'commissioned_at' => '2024-01-10',
        'next_inspection_at' => now('Europe/Berlin')->addDays(20)->toDateString()]);
    $this->later = Equipment::create(['name' => 'Feuerwehrleine 20 m', 'category_id' => $this->lines->id, 'commissioned_at' => '2024-01-10',
        'next_inspection_at' => now('Europe/Berlin')->addDays(100)->toDateString()]);
    $this->template = $this->w->saveTemplate($this->gw, null, [
        'name' => 'Jahresprüfung Feuerwehrleine', 'category_id' => $this->lines->id, 'interval_months' => 12, 'lead_days' => 30,
        'checklist' => ['Mantel ohne Beschädigung', 'Kern fühlbar ohne Verhärtung', 'Kennzeichnung lesbar'],
    ]);
});

function protocolData(Task $task, array $overrides = []): array
{
    return ['task_id' => $task->id, 'performed_on' => now('Europe/Berlin')->toDateString(), 'result' => 'passed',
        'checklist' => [['result' => 'ok'], ['result' => 'ok'], ['result' => 'na']], 'minutes' => 30, ...$overrides];
}

test('templates open the Wartungen within their Voranzeige, once', function () {
    $task = Task::open()->sole();
    expect($task)->equipment_id->toBe($this->line->id)->due_on->toDateString()->toBe($this->line->next_inspection_at->toDateString());

    expect($this->w->plan())->toBe(0)->and(Task::open()->count())->toBe(1);

    // Switched off: the open Wartung is no longer needed
    $this->w->saveTemplate($this->gw, $this->template, [...$this->template->only(['name', 'category_id', 'interval_months', 'lead_days', 'checklist']), 'is_active' => false]);
    expect(Task::open()->count())->toBe(0)->and($task->fresh()->status)->toBe('cancelled');
});

test('a Prüfer signs a protocol; it closes the Wartung, sets the Prüffrist and costs the time', function () {
    $this->w->setHourlyRate($this->gw, 2000);
    $task = Task::open()->sole();

    Livewire::actingAs($this->pruefer)->withQueryParams(['aufgabe' => $task->id])->test(ProtocolForm::class)
        ->assertSet('checklist.0.item', 'Mantel ohne Beschädigung')
        ->set('checklist.0.result', 'ok')->set('checklist.1.result', 'ok')->set('checklist.2.result', 'na')
        ->set('result', 'passed')->set('minutes', '30')->set('materialCost', '4,50')
        ->call('save')->assertHasErrors('signature')
        ->set('signature', signaturePng())
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $protocol = Protocol::sole();
    $today = now('Europe/Berlin')->startOfDay();
    expect($protocol)->code()->toBe('W-'.$today->year.'-001')->inspector_name->toBe('Paula Prüfer')
        ->labor_cost_cents->toBe(1000)->material_cost_cents->toBe(450)->totalCents()->toBe(1450)
        ->and($task->fresh())->status->toBe('done')->protocol_id->toBe($protocol->id)
        ->and($this->line->fresh()->next_inspection_at->toDateString())->toBe($today->copy()->addMonths(12)->toDateString())
        ->and($this->line->inspections()->sole()->passed)->toBeTrue()
        ->and($this->w->verify($protocol))->toBeTrue()
        // The next one only within the Voranzeige next year
        ->and(Task::open()->count())->toBe(0);

    // The signature never lands in the activity log
    $log = ActivityLog::where('subject_type', Protocol::class)->sole();
    expect($log->changes['signature'][1])->toBe('(verborgen)');
});

test('a failed check takes the device out of service', function () {
    $this->w->recordProtocol($this->pruefer, protocolData(Task::open()->sole(), [
        'result' => 'failed', 'checklist' => [['result' => 'defect', 'note' => 'Mantel aufgescheuert'], ['result' => 'ok'], ['result' => 'ok']],
    ]), signaturePng());

    expect($this->line->fresh())->status->toBe(EquipmentStatus::Defective)->next_inspection_at->toBeNull()
        ->and($this->line->damages()->sole()->description)->toContain('Prüfprotokoll W-');

    // A defect needs a description; a failed check needs a reason
    $this->w->planAdHoc($this->gw, $this->later, $this->template, now('Europe/Berlin')->toDateString(), null, null);
    expect(fn () => $this->w->recordProtocol($this->pruefer, protocolData(Task::open()->sole(), ['checklist' => [['result' => 'defect'], ['result' => 'ok'], ['result' => 'ok']]]), signaturePng()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('checklist.0'));
});

test('a template can ask for a qualification; the protocol keeps the Prüfer-ID', function () {
    $this->template->update(['required_qualification' => 'Sachkundiger PSAgA']);
    $task = Task::open()->sole();

    expect(fn () => $this->w->recordProtocol($this->pruefer, protocolData($task), signaturePng()))
        ->toThrow(ValidationException::class, 'Sachkundiger PSAgA');

    $expired = $this->w->saveQualification($this->gw, null, ['user_id' => $this->pruefer->id, 'name' => 'Sachkundiger PSAgA', 'inspector_number' => 'P-07', 'valid_until' => now()->subDay()->toDateString()]);
    expect(fn () => $this->w->recordProtocol($this->pruefer, protocolData($task, ['qualification_id' => $expired->id]), signaturePng()))
        ->toThrow(ValidationException::class);

    $valid = $this->w->saveQualification($this->gw, $expired, ['user_id' => $this->pruefer->id, 'name' => 'Sachkundiger PSAgA', 'inspector_number' => 'P-07', 'valid_until' => now()->addYear()->toDateString()]);
    $protocol = $this->w->recordProtocol($this->pruefer, protocolData($task, ['qualification_id' => $valid->id]), signaturePng());
    expect($protocol)->qualification->toBe('Sachkundiger PSAgA')->inspector_number->toBe('P-07');
});

test('a Fachfirma checks with its own report attached; attachments are part of the signed hash', function () {
    $provider = $this->w->saveProvider($this->gw, null, ['name' => 'Seilprüfdienst Muster GmbH', 'email' => 'service@example.com']);
    $task = Task::open()->sole();

    $protocol = $this->w->recordProtocol($this->gw, protocolData($task, [
        'performer' => 'external', 'provider_id' => $provider->id, 'technician' => 'Theo Techniker', 'inspector_number' => 'SK-123', 'external_cost_cents' => 8900,
    ]), signaturePng(), [UploadedFile::fake()->image('leine.jpg', 1200, 900), UploadedFile::fake()->create('pruefbericht.pdf', 50, 'application/pdf')]);

    expect($protocol)->performer->toBe('external')->provider_name->toBe('Seilprüfdienst Muster GmbH')->inspector_name->toBe('Theo Techniker')
        ->external_cost_cents->toBe(8900)->inspector_user_id->toBeNull()
        ->and($protocol->attachments()->pluck('mime')->all())->toBe(['image/webp', 'application/pdf'])
        ->and($this->w->verify($protocol))->toBeTrue();

    DB::statement('ALTER TABLE wm_attachments DISABLE TRIGGER wm_attachments_protect');
    DB::table('wm_attachments')->where('protocol_id', $protocol->id)->where('mime', 'application/pdf')->update(['data' => base64_encode('%PDF-geändert')]);
    DB::statement('ALTER TABLE wm_attachments ENABLE TRIGGER wm_attachments_protect');
    expect($this->w->verify($protocol))->toBeFalse();
});

test('a signed protocol cannot be changed or deleted, only cancelled by the Gerätewart', function () {
    $protocol = $this->w->recordProtocol($this->pruefer, protocolData(Task::open()->sole()), signaturePng());

    expect(fn () => DB::transaction(fn () => DB::table('wm_protocols')->where('id', $protocol->id)->update(['result' => 'failed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('wm_protocols')->where('id', $protocol->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => $this->w->voidProtocol($this->pruefer, $protocol, 'falsches Gerät'))->toThrow(ValidationException::class);

    Livewire::actingAs($this->gw)->test(ProtocolDetail::class, ['protocol' => $protocol])
        ->call('void')->assertHasErrors('void_reason')
        ->set('voidReason', 'Falsches Gerät gewählt')->call('void')->assertHasNoErrors()->assertSee('Storniert');

    expect($protocol->fresh())->isVoided()->toBeTrue()->and($this->w->verify($protocol->fresh()))->toBeTrue();

    // Deleting the device keeps the protocol with code and name
    $this->line->delete();
    expect($protocol->fresh())->equipment_id->toBeNull()->equipment_code->toBe($this->line->code);
});

test('ad hoc: plan a Wartung for a device, or check without a template', function () {
    Livewire::actingAs($this->gw)->test(DeviceDetail::class, ['equipment' => $this->later])
        ->set('notes', '')->call('plan')->assertHasErrors('notes')
        ->set('notes', 'Sichtprüfung nach Einsatz')->call('plan')->assertHasNoErrors();
    $task = Task::open()->where('equipment_id', $this->later->id)->sole();
    expect($task)->template_id->toBeNull()->label()->toBe('Sichtprüfung nach Einsatz');

    Livewire::actingAs($this->pruefer)->withQueryParams(['geraet' => $this->later->id])->test(ProtocolForm::class)
        ->set('title', 'Sichtprüfung nach Einsatz')->set('newItem', 'Keine Brandspuren')->call('addItem')
        ->set('checklist.0.result', 'ok')->set('result', 'passed')->set('signature', signaturePng())
        ->call('save')->assertHasNoErrors();

    $protocol = Protocol::sole();
    expect($protocol)->template_id->toBeNull()->checklist->toBe([['item' => 'Keine Brandspuren', 'result' => 'ok', 'note' => null]])
        // Not counted as Prüfung: the Prüffrist stays
        ->and($this->later->fresh()->next_inspection_at->toDateString())->toBe($this->later->next_inspection_at->toDateString());
});

test('templates are checked and edited with their checklist', function () {
    Livewire::actingAs($this->gw)->test(TemplateForm::class)
        ->set('name', 'Prüfung Leiter')->call('save')->assertHasErrors(['checklist', 'category_id'])
        ->set('categoryId', (string) Category::where('name', 'Tragbare Leiter')->value('id'))->assertSet('intervalMonths', '12')
        ->set('newItem', "Holme ohne Risse\nSprossen fest")->call('addItem')->assertSet('checklist', ['Holme ohne Risse', 'Sprossen fest'])
        ->call('moveItem', 1, -1)->assertSet('checklist', ['Sprossen fest', 'Holme ohne Risse'])
        ->call('save')->assertHasNoErrors()->assertRedirect(route('wartung.templates'));

    expect(Template::where('name', 'Prüfung Leiter')->sole()->checklist)->toBe(['Sprossen fest', 'Holme ohne Risse']);
});

test('the Gerätewarte get one mail for newly due and overdue Wartungen', function () {
    $this->travel(25)->days();
    $this->artisan('wartung:plan')->assertSuccessful();

    Notification::assertSentTo($this->gw, WartungDigestNotification::class, function ($n, $channels, $notifiable) {
        $mail = $n->toMail($notifiable);

        return str_contains($mail->subject, '1 überfällig') && str_contains(implode(' ', $mail->introLines), 'Feuerwehrleine 30 m');
    });
    Notification::assertNotSentTo($this->pruefer, WartungDigestNotification::class);

    Notification::fake();
    $this->artisan('wartung:plan')->assertSuccessful();
    Notification::assertNothingSent();
});

test('who may do what', function () {
    $this->actingAs($this->member)->get(route('wartung.index'))->assertOk()->assertDontSee('Durchführen');
    $this->actingAs($this->member)->get(route('wartung.perform'))->assertForbidden();
    $this->actingAs($this->member)->get(route('wartung.templates'))->assertForbidden();
    $this->actingAs($this->pruefer)->get(route('wartung.index'))->assertOk()->assertSee('Durchführen');
    $this->actingAs($this->pruefer)->get(route('wartung.templates'))->assertForbidden();
    expect(fn () => $this->w->recordProtocol($this->member, protocolData(Task::open()->sole()), signaturePng()))->toThrow(ValidationException::class);

    Livewire::actingAs($this->pruefer)->test(Home::class)->assertSee('1 Wartung offen');
    Livewire::actingAs($this->member)->test(Home::class)->assertDontSee('Wartung offen');
});

test('location-scoped Gerätewarte only manage and view maintenance at assigned locations', function () {
    config(['locations.enabled' => true]);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'wartung-nord']);
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'wartung-sued']);
    $this->line->update(['ext_location_id' => $north->id]);
    $this->later->update(['ext_location_id' => $south->id]);
    $this->w->planAdHoc($this->gw, $this->later, $this->template, now('Europe/Berlin')->toDateString(), null, null);

    $admin = User::factory()->admin()->create();
    $localManager = User::factory()->create(['name' => 'Gerd Gerätewart Nord', 'ext_location_id' => $north->id]);
    app(StandardRoleAssignments::class)->grant($admin, $localManager->id, 'geraetewart', $north->id, null, 'Wartung am Standort');

    $northTask = Task::open()->where('equipment_id', $this->line->id)->firstOrFail();
    $southTask = Task::open()->where('equipment_id', $this->later->id)->firstOrFail();
    $southProtocol = $this->w->recordProtocol($this->gw, protocolData($southTask), signaturePng());

    expect($localManager->canUseModule('wartung'))->toBeTrue()
        ->and(WartungService::canManage($localManager))->toBeFalse()
        ->and(WartungService::canManageOnEquipment($localManager, $this->line))->toBeTrue()
        ->and(WartungService::canManageOnEquipment($localManager, $this->later))->toBeFalse()
        ->and(WartungService::canPerformOnEquipment($localManager, $this->line))->toBeTrue()
        ->and(WartungService::canPerformOnEquipment($localManager, $this->later))->toBeFalse()
        ->and(WartungService::canViewProtocol($localManager, $southProtocol))->toBeFalse();

    Livewire::actingAs($localManager)->test(Overview::class)->assertSee($this->line->code)->assertDontSee($this->later->code);
    $this->actingAs($localManager)->get(route('wartung.device', $this->later))->assertNotFound();
    $this->actingAs($localManager)->get(route('wartung.protocol', $southProtocol))->assertNotFound();
    $this->actingAs($localManager)->get(route('wartung.protocol.pdf', $southProtocol))->assertNotFound();

    expect(fn () => $this->w->planAdHoc($localManager, $this->later, null, now('Europe/Berlin')->toDateString(), null, 'Prüfung Süd'))
        ->toThrow(ValidationException::class, 'Standort')
        ->and(fn () => $this->w->saveTemplate($localManager, null, ['name' => 'Globale Vorlage', 'category_id' => $this->lines->id, 'interval_months' => 12,
            'lead_days' => 30, 'checklist' => ['Prüfpunkt']]))->toThrow(ValidationException::class);

    $northProtocol = $this->w->recordProtocol($localManager, protocolData($northTask), signaturePng());
    $this->w->voidProtocol($localManager, $northProtocol, 'Falsches Gerät');
    expect($northProtocol->fresh()->isVoided())->toBeTrue();

    expect(fn () => $this->w->cancelTask($localManager, $southTask))->toThrow(ValidationException::class);

    $this->w->planAdHoc($this->gw, $this->line, null, now('Europe/Berlin')->toDateString(), null, 'Folgeprüfung Nord');
    $this->w->planAdHoc($this->gw, $this->later, null, now('Europe/Berlin')->toDateString(), null, 'Folgeprüfung Süd');
    $this->gw->moduleAccess()->where('module', 'wartung')->delete();
    expect($this->w->remind())->toBe(2);

    Notification::assertSentTo($localManager, WartungDigestNotification::class, function ($notification, $channels, $notifiable) {
        $content = implode(' ', $notification->toMail($notifiable)->introLines);

        return str_contains($content, 'Folgeprüfung Nord') && ! str_contains($content, 'Folgeprüfung Süd');
    });
    Notification::assertSentTo($admin, WartungDigestNotification::class, function ($notification, $channels, $notifiable) {
        $content = implode(' ', $notification->toMail($notifiable)->introLines);

        return str_contains($content, 'Folgeprüfung Süd') && ! str_contains($content, 'Folgeprüfung Nord');
    });
});

test('the pages and PDFs open; without the Ausrüstungsverwaltung there is a hint', function () {
    $protocol = $this->w->recordProtocol($this->pruefer, protocolData(Task::open()->sole()), signaturePng(), [UploadedFile::fake()->image('foto.jpg', 800, 600)]);
    $this->w->saveProvider($this->gw, null, ['name' => 'Atemschutzwerkstatt Muster']);
    $this->w->saveQualification($this->gw, null, ['user_id' => $this->pruefer->id, 'name' => 'Gerätewart Atemschutz', 'inspector_number' => 'AS-1']);

    foreach (['wartung.index', 'wartung.protocols', 'wartung.templates', 'wartung.template.create', 'wartung.providers', 'wartung.inspectors', 'wartung.perform'] as $route) {
        $this->actingAs($this->gw)->get(route($route))->assertOk();
    }
    $this->actingAs($this->gw)->get(route('wartung.inspectors'))->assertSee('Gerätewart Atemschutz (Prüfer-ID AS-1)');
    $this->actingAs($this->gw)->get(route('wartung.providers'))->assertSee('Atemschutzwerkstatt Muster');
    $this->actingAs($this->gw)->get(route('wartung.template', $this->template))->assertOk()->assertSee('Mantel ohne Beschädigung');
    $this->actingAs($this->member)->get(route('wartung.device', $this->line))->assertOk()->assertSee('DGUV-Lebensdauer')->assertSee($protocol->code());
    $this->actingAs($this->member)->get(route('wartung.protocol', $protocol))->assertOk()->assertSee('unverändert');

    foreach ([route('wartung.protocol.pdf', $protocol), route('wartung.template.pdf', $this->template)] as $url) {
        $response = $this->actingAs($this->member)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }

    Livewire::actingAs($this->gw)->test(Overview::class)->assertSee('Protokolle '.now('Europe/Berlin')->year);

    Module::where('key', 'ausruestung')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->gw)->get(route('wartung.index'))->assertOk()->assertSee('Bitte zuerst dieses Modul einschalten');
});
