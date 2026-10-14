<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource\Pages\EditDefect;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource\Pages\ListDefects;
use Modules\Maengelmanagement\src\Livewire\DefectDetail;
use Modules\Maengelmanagement\src\Livewire\ReportForm;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Maengelmanagement\src\Notifications\DefectReportedNotification;
use Modules\Maengelmanagement\src\Notifications\DefectUpdatedNotification;
use Modules\Maengelmanagement\src\Services\DefectPhotoService;
use Modules\Maengelmanagement\src\Services\DefectService;

beforeEach(function () {
    Module::where('key', 'maengelmanagement')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'maengelmanagement']);
    $this->manager = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->manager->moduleAccess()->create(['module' => 'maengelmanagement', 'role' => 'verantwortlicher']);
    $this->device = Device::create(['name' => 'Tragkraftspritze', 'location' => 'Fahrzeughalle']);
});

function reportDefect(User $by, array $overrides = []): Defect
{
    return app(DefectService::class)->report($by, $overrides + [
        'device_id' => Device::first()->id,
        'title' => 'Schlauchtrommel klemmt',
        'urgency' => 'high',
    ]);
}

// ─── access ──────────────────────────────────────────────────────────────────

test('the module is hidden while inactive and needs a grant', function () {
    $this->actingAs($this->member)->get('/maengelmanagement')->assertOk();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get('/maengelmanagement')->assertForbidden();

    Module::where('key', 'maengelmanagement')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->member)->get('/maengelmanagement')->assertNotFound();
});

test('devices get a running code', function () {
    $second = Device::create(['name' => 'Notstromaggregat']);

    expect($this->device->code)->toBe('MM-0001')->and($second->code)->toBe('MM-0002');
});

// ─── reporting ───────────────────────────────────────────────────────────────

test('a member reports a defect with a photo and the managers are notified', function () {
    Notification::fake();

    Livewire::actingAs($this->member)
        ->test(ReportForm::class)
        ->set('deviceId', $this->device->id)
        ->set('title', 'Schlauchtrommel klemmt')
        ->set('description', 'Lässt sich nicht abrollen.')
        ->set('urgency', 'high')
        ->set('photos', [UploadedFile::fake()->image('foto.jpg', 1600, 1200)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $defect = Defect::sole();
    expect($defect->status)->toBe(DefectStatus::Open)
        ->and($defect->urgency)->toBe(Urgency::High)
        ->and($defect->reporter_id)->toBe($this->member->id)
        ->and($defect->photos)->toHaveCount(1)
        ->and($defect->photos->first()->mime)->toBe('image/webp')
        ->and($defect->events->pluck('type')->all())->toBe(['reported']);

    Notification::assertSentTo($this->manager, DefectReportedNotification::class);
    Notification::assertNotSentTo($this->member, DefectReportedNotification::class);
});

test('without a manager the admins are notified', function () {
    Notification::fake();
    $this->manager->moduleAccess()->delete();
    $admin = User::factory()->admin()->create();

    reportDefect($this->member);

    Notification::assertSentTo($admin, DefectReportedNotification::class);
});

test('the report needs a title and either a device or a place', function () {
    Livewire::actingAs($this->member)
        ->test(ReportForm::class)
        ->call('save')
        ->assertHasErrors(['title']);

    Livewire::actingAs($this->member)
        ->test(ReportForm::class)
        ->set('title', 'Tür klemmt')
        ->call('save')
        ->assertHasErrors(['deviceId']);

    Livewire::actingAs($this->member)
        ->test(ReportForm::class)
        ->set('title', 'Tür klemmt')
        ->set('location', 'Umkleide')
        ->call('save')
        ->assertHasNoErrors();

    expect(Defect::sole()->subject())->toBe('Umkleide');
});

test('at most three photos are accepted', function () {
    Livewire::actingAs($this->member)
        ->test(ReportForm::class)
        ->set('deviceId', $this->device->id)
        ->set('title', 'Kaputt')
        ->set('photos', array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 4)))
        ->call('save')
        ->assertHasErrors('photos');
});

test('the QR code preselects the device in the form', function () {
    Livewire::actingAs($this->member)
        ->withQueryParams(['objekt' => $this->device->code])
        ->test(ReportForm::class)
        ->assertSet('deviceId', $this->device->id);
});

// ─── following ───────────────────────────────────────────────────────────────

test('every member sees all defects and their detail page', function () {
    $other = User::factory()->create(['name' => 'Klara Kübel']);
    $other->moduleAccess()->create(['module' => 'maengelmanagement']);
    $defect = reportDefect($other);

    $this->actingAs($this->member)->get('/maengelmanagement')->assertOk()->assertSee('Schlauchtrommel klemmt');
    $this->actingAs($this->member)->get("/maengelmanagement/{$defect->id}")->assertOk()->assertSee('Klara Kübel')->assertSee('Verlauf');
});

test('members can comment and the reporter is told', function () {
    Notification::fake();
    $defect = reportDefect($this->member);

    Livewire::actingAs($this->manager)
        ->test(DefectDetail::class, ['defect' => $defect])
        ->set('comment', 'Wir schauen nach.')
        ->call('send')
        ->assertHasNoErrors();

    expect($defect->events()->where('type', 'comment')->sole()->body)->toBe('Wir schauen nach.');
    Notification::assertSentTo($this->member, DefectUpdatedNotification::class);
});

test('photos are served to members only', function () {
    $defect = reportDefect($this->member);
    $path = tempnam(sys_get_temp_dir(), 'mm').'.png';
    imagepng(imagecreatetruecolor(40, 30), $path);
    $photo = app(DefectPhotoService::class)->store($defect, $path);
    unlink($path);

    $this->actingAs($this->member)->get(route('maengelmanagement.photo', $photo))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');

    $this->actingAs(User::factory()->create())->get(route('maengelmanagement.photo', $photo))->assertForbidden();
});

// ─── handling ────────────────────────────────────────────────────────────────

test('changing the status is logged and tells the reporter', function () {
    Notification::fake();
    $defect = reportDefect($this->member);

    app(DefectService::class)->setStatus($defect, $this->manager, DefectStatus::Resolved, 'Neue Trommel eingebaut');

    $defect->refresh();
    expect($defect->status)->toBe(DefectStatus::Resolved)->and($defect->resolved_at)->not->toBeNull();
    expect($defect->events()->where('type', 'status')->sole())
        ->from_value->toBe('open')->to_value->toBe('resolved')->body->toBe('Neue Trommel eingebaut');
    Notification::assertSentTo($this->member, DefectUpdatedNotification::class);

    app(DefectService::class)->setStatus($defect, $this->manager, DefectStatus::InProgress);
    expect($defect->refresh()->resolved_at)->toBeNull();
});

test('assigning tells the assignee but not the person assigning', function () {
    $defect = reportDefect($this->member);
    Notification::fake();

    app(DefectService::class)->assign($defect, $this->manager, $this->manager);
    Notification::assertNothingSent();

    app(DefectService::class)->assign($defect, $this->manager, $this->member);
    Notification::assertSentTo($this->member, DefectUpdatedNotification::class);
    expect($defect->refresh()->assigned_to)->toBe($this->member->id);
});

// ─── scanning ────────────────────────────────────────────────────────────────

test('scanning a device label opens the report form', function () {
    $this->actingAs($this->member)
        ->get('/i/'.$this->device->code)
        ->assertRedirect(route('maengelmanagement.report', ['objekt' => $this->device->code]));
});

test('inactive devices are not claimed by the scan', function () {
    $this->device->update(['is_active' => false]);

    $this->actingAs($this->member)->get('/i/'.$this->device->code)->assertNotFound();
});

test('a Kleiderkammer manager still gets the create form for unknown codes but not for device codes', function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $warden = User::factory()->create();
    $warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    $this->actingAs($warden)->get('/i/WEHRXYZ0001')
        ->assertRedirect(url('/admin/kleiderkammer/einzelteile/neu?code=WEHRXYZ0001'));

    $this->actingAs($warden)->get('/i/'.$this->device->code)
        ->assertRedirect(route('maengelmanagement.report', ['objekt' => $this->device->code]));
});

// ─── admin panel ─────────────────────────────────────────────────────────────

test('only managers and admins reach the panel', function () {
    $this->actingAs($this->member)->get('/admin/maengelmanagement')->assertForbidden();
    $this->actingAs($this->manager)->get('/admin/maengelmanagement')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/admin/maengelmanagement')->assertOk();
});

test('the manager sees the defects and edits one through the service', function () {
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $defect = reportDefect($this->member);

    Livewire::actingAs($this->manager)
        ->test(ListDefects::class)
        ->assertCanSeeTableRecords([$defect]);

    Livewire::actingAs($this->manager)
        ->test(EditDefect::class, ['record' => $defect->getKey()])
        ->fillForm(['status' => 'in_progress', 'urgency' => 'critical', 'assigned_to' => $this->manager->id, 'note' => 'Ersatzteil bestellt'])
        ->call('save')
        ->assertHasNoFormErrors();

    $defect->refresh();
    expect($defect->status)->toBe(DefectStatus::InProgress)
        ->and($defect->urgency)->toBe(Urgency::Critical)
        ->and($defect->assigned_to)->toBe($this->manager->id);
    expect($defect->events()->pluck('type')->sort()->values()->all())->toBe(['assigned', 'reported', 'status', 'urgency']);
    Notification::assertSentTo($this->member, DefectUpdatedNotification::class);
});
