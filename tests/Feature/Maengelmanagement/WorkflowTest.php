<?php

use App\Models\Module;
use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Livewire\DefectDetail;
use Modules\Maengelmanagement\src\Livewire\DefectList;
use Modules\Maengelmanagement\src\Livewire\ReportForm;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Maengelmanagement\src\Notifications\DefectDigestNotification;
use Modules\Maengelmanagement\src\Notifications\DefectUpdatedNotification;
use Modules\Maengelmanagement\src\Services\DefectService;

beforeEach(function () {
    Module::whereIn('key', ['maengelmanagement', 'ausruestung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'maengelmanagement']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->bernd->moduleAccess()->create(['module' => 'maengelmanagement', 'role' => 'verantwortlicher']);
    $this->gerd = User::factory()->create(['name' => 'Gerd Gerätewart']);
    $this->gerd->moduleAccess()->create(['module' => 'maengelmanagement']);
    $this->gerd->moduleAccess()->create(['module' => 'ausruestung', 'role' => 'geraetewart']);
    $this->tor = Device::create(['name' => 'Tor 2', 'location' => 'Fahrzeughalle', 'responsible_id' => $this->gerd->id]);
    $this->s = app(DefectService::class);
});

function photoPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'mm').'.png';
    imagepng(imagecreatetruecolor(40, 30), $path);

    return $path;
}

test('the Zuständige of an object gets new defects right away and works on them in the app', function () {
    $defect = $this->s->report($this->hanna, ['device_id' => $this->tor->id, 'title' => 'Tor schließt nicht', 'urgency' => 'high']);

    expect($defect->assigned_to)->toBe($this->gerd->id);
    Notification::assertSentTo($this->gerd, DefectUpdatedNotification::class);

    // The Zuständige (no Verantwortlicher) sets status, urgency and a note right on the detail page
    Livewire::actingAs($this->gerd)->test(DefectDetail::class, ['defect' => $defect])
        ->assertSee('Bearbeiten')
        ->set('status', 'in_progress')->set('urgency', 'critical')->set('note', 'Ersatzteil bestellt')->call('saveWork')->assertHasNoErrors()->assertSee('Gespeichert.');
    expect($defect->refresh()->status)->toBe(DefectStatus::InProgress)->and($defect->urgency->value)->toBe('critical');

    // A plain member can't
    Livewire::actingAs($this->hanna)->test(DefectDetail::class, ['defect' => $defect])->assertDontSee('Bearbeiten')->call('saveWork')->assertForbidden();
});

test('a Rückfrage waits for the reporter, who answers with a photo', function () {
    $defect = $this->s->report($this->hanna, ['location' => 'Umkleide', 'title' => 'Spind kaputt', 'urgency' => 'low']);

    Livewire::actingAs($this->bernd)->test(DefectDetail::class, ['defect' => $defect])
        ->set('mode', 'question')->set('comment', 'Welcher Spind genau?')->call('send')->assertHasNoErrors()->assertSee('Rückfrage gestellt');
    expect($defect->refresh()->question_open_at)->not->toBeNull();
    Notification::assertSentTo($this->hanna, DefectUpdatedNotification::class, fn ($n) => $n->toMail($this->hanna)->subject === 'Rückfrage zu deinem Mangel: Spind kaputt');

    Livewire::actingAs($this->hanna)->test(DefectList::class)->set('view', 'rueckfrage')->assertSee('Spind kaputt')->assertSee('Rückfrage');
    $card = collect(app(DashboardRegistry::class)->for($this->hanna))->implode('');
    expect($card)->toContain('Rückfrage zu „Spind kaputt“');

    Livewire::actingAs($this->hanna)->test(DefectDetail::class, ['defect' => $defect])
        ->assertSee('Rückfrage an dich')
        ->set('comment', 'Nr. 14, Tür hängt')->set('photos', [UploadedFile::fake()->image('spind.jpg', 400, 300)])->call('send')->assertHasNoErrors()
        ->assertSee('hat die Rückfrage beantwortet');

    $defect->refresh();
    expect($defect->question_open_at)->toBeNull()
        ->and($defect->events()->where('type', 'answer')->sole()->photos()->count())->toBe(1)
        ->and($defect->photos()->count())->toBe(0);
    Notification::assertSentTo($this->bernd, DefectUpdatedNotification::class, fn ($n) => str_starts_with($n->toMail($this->bernd)->subject, 'Antwort auf deine Rückfrage'));
});

test('the reporter can reopen a fixed defect for a while', function () {
    $defect = $this->s->report($this->hanna, ['location' => 'Küche', 'title' => 'Wasserhahn tropft', 'urgency' => 'low']);
    $this->s->assign($defect, $this->bernd, $this->bernd);
    $this->s->setStatus($defect->refresh(), $this->bernd, DefectStatus::Resolved, 'Dichtung getauscht');

    Livewire::actingAs($this->hanna)->test(DefectDetail::class, ['defect' => $defect->refresh()])
        ->set('mode', 'reopen')->set('comment', 'Tropft wieder')->call('send')->assertHasNoErrors();
    expect($defect->refresh()->status)->toBe(DefectStatus::Open)->and($defect->resolved_at)->toBeNull();
    Notification::assertSentTo($this->bernd, DefectUpdatedNotification::class, fn ($n) => str_starts_with($n->toMail($this->bernd)->subject, 'Doch nicht behoben'));

    // Not someone else, and not long after
    $this->s->setStatus($defect, $this->bernd, DefectStatus::Resolved);
    expect(fn () => $this->s->reopen($defect->refresh(), $this->bernd, 'x'))->toThrow(ValidationException::class);
    $this->travel(Defect::REOPEN_DAYS + 1)->days();
    expect($defect->refresh()->canReopen($this->hanna))->toBeFalse();
});

test('a defect on a Gerät of the Ausrüstung marks it defective until it is fixed', function () {
    $saw = Equipment::create(['name' => 'Motorkettensäge', 'code' => 'AR-0042']);

    Livewire::actingAs($this->hanna)->withQueryParams(['geraet' => 'AR-0042'])->test(ReportForm::class)
        ->assertSet('equipmentId', $saw->id)->set('title', 'Kette stumpf')->call('save')->assertHasNoErrors();
    $defect = Defect::sole();
    expect($defect->equipment_id)->toBe($saw->id)->and($defect->subject())->toBe('Motorkettensäge (AR-0042)')
        ->and($saw->refresh()->status)->toBe(EquipmentStatus::Defective)->and($defect->ar_damage_id)->not->toBeNull();

    $this->s->setStatus($defect, $this->bernd, DefectStatus::Resolved, 'geschärft');
    expect($saw->refresh()->status)->toBe(EquipmentStatus::Available);
});

test('reminders for defects nobody takes on, the start page for Verantwortliche and the Jahresbericht', function () {
    $old = $this->s->report($this->hanna, ['location' => 'Hof', 'title' => 'Schlagloch Ausfahrt', 'urgency' => 'critical']);
    Defect::whereKey($old->id)->update(['created_at' => now()->subDays(3)]);
    $this->s->report($this->hanna, ['location' => 'Hof', 'title' => 'Neu gemeldet', 'urgency' => 'low']);

    expect($this->s->remind())->toBe(1)->and($this->s->remind())->toBe(0);
    Notification::assertSentTo($this->bernd, DefectDigestNotification::class, fn ($n) => str_contains($lines = implode(' ', $n->toMail($this->bernd)->introLines), 'Schlagloch Ausfahrt') && str_contains($lines, 'noch niemand zuständig') && ! str_contains($lines, 'Neu gemeldet'));

    $card = collect(app(DashboardRegistry::class)->for($this->bernd))->implode('');
    expect($card)->toContain('2 offen')->toContain('1 kritisch')->toContain('2 ohne Zuständigen');

    $section = collect(app(AnnualReportRegistry::class)->for((int) now('Europe/Berlin')->year))->firstWhere('title', 'Mängel');
    expect($section['html'])->toContain('Gemeldet')->toContain('Zum Jahresende offen');
});
