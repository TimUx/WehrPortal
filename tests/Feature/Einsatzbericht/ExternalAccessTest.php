<?php

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ExternalReport;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Models\ExternalUnit;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationMessage;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\ReportService;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'einsatzbericht']);

    $this->r = app(ReportService::class);
    $this->report = $this->r->create($this->leader);
    $this->r->start($this->leader, $this->report);
    $this->r->update($this->leader, $this->report, ['keyword' => 'FEU 4 – Großbrand Scheune', 'address' => 'Hof Lindenhof 3, Musterstadt', 'affected_parties' => 'Landwirt Max Muster']);
    $this->access = app(ExternalAccess::class);
});

/** The Livewire page as one phone (with its own device cookie) */
function phone(string $key, string $device = 'phonea')
{
    return Livewire::withCookie(ExternalReport::COOKIE, str_pad($device, 40, 'x'))->test(ExternalReport::class, ['code' => $key]);
}

test('the Einsatzleiter makes a QR code; the address opens without login', function () {
    $this->actingAs($this->leader);
    Livewire::test(ReportEditor::class, ['report' => $this->report])
        ->assertSee('QR-Code erzeugen')
        ->call('enableExternal')
        ->assertSee('QR-Zugang erzeugt')
        ->assertSee('QR groß anzeigen')
        // Also on top, next to "Navigation" and "Drucken / PDF"
        ->assertSeeHtml('target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">QR-Code</a>');

    $url = ExternalAccess::url($this->report->refresh());
    expect($url)->toMatch('#/extern/[A-Za-z0-9]{40}$#')
        ->and(ExternalAccess::qr($this->report))->toStartWith('data:image/svg+xml;base64,');

    auth()->logout();
    $this->get($url)->assertOk()->assertSee('Meldungen an die Einsatzleitung')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        // no report data for externals
        ->assertDontSee('Großbrand Scheune')->assertDontSee('Lindenhof')->assertDontSee('Max Muster');

    $this->actingAs($this->leader)->get('/einsatzbericht/'.$this->report->id.'/qr')->assertOk()->assertSee('QR-Zugang für externe Wehren');
    $this->actingAs($this->member)->get('/einsatzbericht/'.$this->report->id.'/qr')->assertForbidden();
    expect(fn () => $this->access->enable($this->member, $this->report))->toThrow(ValidationException::class);
});

test('an external Wehr registers once, sends status and Lagemeldungen with photo, and sees only its own', function () {
    $key = $this->access->enable($this->leader, $this->report);

    phone($key)
        ->assertSee('Einmalig anmelden')
        ->set('name', 'FF Bargteheide')->set('vehicle', 'LF 20')->set('strength', '1/8/9')
        ->call('register')
        ->assertSee('Angemeldet als')
        ->call('sendStatus', 'anfahrt')
        ->call('sendStatus', 'einsatzstelle')
        ->assertSee('Status gesendet: An Einsatzstelle')
        ->set('text', 'Abschnitt Nord: Wasserversorgung aus Teich aufgebaut')
        ->set('photos', [UploadedFile::fake()->image('nord.jpg', 2000, 1500)])
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertSee('Lagemeldung gesendet')
        ->assertSee('Abschnitt Nord')
        // The own status changes are in the list too, newest first
        ->assertSeeInOrder(['Deine Meldungen', 'Status', 'An Einsatzstelle', 'Status', 'Auf Anfahrt']);

    $unit = ExternalUnit::sole();
    expect($unit->label())->toBe('FF Bargteheide · LF 20 · 1/8/9')
        ->and($unit->statuses()->pluck('status')->all())->toBe(['einsatzstelle', 'anfahrt'])
        ->and(SituationMessage::sole())->external_unit_id->toBe($unit->id)->user_id->toBeNull()
        ->and(SituationMessage::sole()->photos)->toHaveCount(1);

    // A second phone is a second Wehr and does not see the first one's messages
    phone($key, 'phoneb')->assertSee('Einmalig anmelden')->assertDontSee('Abschnitt Nord')
        ->set('name', 'FF Elmenhorst')->call('register')->assertDontSee('Abschnitt Nord');
    // ... and the first phone keeps its registration
    phone($key)->assertSee('Angemeldet als')->assertSee('FF Bargteheide');

    // Internal: in the Einsatztagebuch and the report
    $this->actingAs($this->leader)->get('/einsatzbericht/'.$this->report->id)->assertOk()
        ->assertSee('Extern: FF Bargteheide · LF 20 · 1/8/9')->assertSee('An Einsatzstelle')->assertSee('FF Elmenhorst');
    $this->actingAs($this->leader)->get('/einsatzbericht/'.$this->report->id.'/druck')->assertSee('Externe Wehren (QR-Zugang)');
});

test('the code stops working when blocked, renewed, or the Einsatz ends', function () {
    $key = $this->access->enable($this->leader, $this->report);
    phone($key)->set('name', 'FF Bargteheide')->call('register');

    $this->access->revoke($this->leader, $this->report->refresh());
    $this->get('/extern/'.$key)->assertOk()->assertSee('gesperrt oder der Einsatz ist beendet');
    phone($key)->call('sendStatus', 'abgerueckt')->assertSee('gesperrt oder der Einsatz ist beendet');

    $new = $this->access->enable($this->leader, $this->report->refresh());
    $old = $this->access->enable($this->leader, $this->report->refresh());
    expect(ExternalAccess::find($new))->toBeNull()->and(ExternalAccess::find($old))->not->toBeNull();

    $this->r->update($this->leader, $this->report->refresh(), ['ended_at' => Carbon::now()]);
    expect(ExternalAccess::find($old))->toBeNull();
    expect(fn () => $this->access->enable($this->leader, $this->report->refresh()))->toThrow(ValidationException::class, 'laufenden Einsatz');

    // Unknown codes
    $this->get('/extern/'.str_repeat('a', 40))->assertOk()->assertSee('gesperrt');
});

test('messages per phone are limited in number and rate, and the input is checked', function () {
    $key = $this->access->enable($this->leader, $this->report);
    phone($key)->set('name', '')->call('register')->assertHasErrors('name');
    phone($key)->set('name', 'FF Bargteheide')->set('strength', 'viele')->call('register')->assertHasErrors('strength');
    phone($key)->set('name', 'FF Bargteheide')->call('register');
    $unit = ExternalUnit::sole();

    foreach (range(1, ExternalAccess::PER_MINUTE) as $i) {
        $this->access->status($unit, 'einsatzbereit');
    }
    expect(fn () => $this->access->status($unit, 'einsatzbereit'))->toThrow(ValidationException::class, 'Moment warten');

    $this->travel(2)->minutes();
    expect(fn () => $this->access->status($unit, 'unsinn'))->toThrow(ValidationException::class);
    foreach (range(1, ExternalAccess::MAX_MESSAGES) as $i) {
        SituationMessage::create(['report_id' => $this->report->id, 'external_unit_id' => $unit->id, 'text' => "Meldung $i"]);
    }
    expect(fn () => $this->access->message($unit, 'eine zu viel'))->toThrow(ValidationException::class, 'Höchstzahl');

    phone($key)->set('photos', array_map(fn ($i) => UploadedFile::fake()->image("f$i.jpg"), range(1, 4)))->call('sendMessage')->assertHasErrors('photos');
});

test('the key is never written to the logs in clear', function () {
    $key = $this->access->enable($this->leader, $this->report);

    expect(json_encode(ActivityLog::pluck('changes')))->not->toContain($key)
        ->and($this->report->refresh()->toArray())->not->toHaveKey('external_key');
});
