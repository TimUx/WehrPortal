<?php

use App\Models\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Source;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kalender\src\Services\IcsExporter;
use Modules\Kalender\src\Services\IcsParser;
use Modules\Kalender\src\Services\SourceSync;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
});

function ics(string ...$events): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n".implode("\r\n", $events)."\r\nEND:VCALENDAR\r\n";
}

test('the parser reads time zones, whole days, folded and escaped text and skips cancelled events', function () {
    $content = ics(
        "BEGIN:VEVENT\r\nUID:a\r\nSUMMARY:Kreisübung\\, Teil 1\r\nLOCATION:Feuerwehrhaus\r\n Bad Segeberg\r\nDTSTART;TZID=Europe/Berlin:20270612T100000\r\nDTEND;TZID=Europe/Berlin:20270612T140000\r\nDESCRIPTION:Zeile 1\\nZeile 2\r\nEND:VEVENT",
        "BEGIN:VEVENT\r\nUID:b\r\nSUMMARY:Sommerferien\r\nDTSTART;VALUE=DATE:20270705\r\nDTEND;VALUE=DATE:20270814\r\nEND:VEVENT",
        "BEGIN:VEVENT\r\nUID:c\r\nSUMMARY:UTC-Termin\r\nDTSTART:20270615T170000Z\r\nDURATION:PT90M\r\nEND:VEVENT",
        "BEGIN:VEVENT\r\nUID:d\r\nSUMMARY:Fällt aus\r\nSTATUS:CANCELLED\r\nDTSTART:20270616T170000Z\r\nEND:VEVENT",
    );

    $events = collect(app(IcsParser::class)->parse($content, now(), now()->addYear()))->keyBy('uid');

    expect($events->keys()->sort()->values()->all())->toBe(['a', 'b', 'c'])
        ->and($events['a']['title'])->toBe('Kreisübung, Teil 1')
        ->and($events['a']['location'])->toBe('FeuerwehrhausBad Segeberg')
        ->and($events['a']['description'])->toBe("Zeile 1\nZeile 2")
        ->and($events['a']['starts_at']->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('12.06.2027 10:00')
        ->and($events['b']['all_day'])->toBeTrue()
        ->and($events['b']['ends_at']->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'))->toBe('14.08.2027')
        ->and($events['c']['ends_at']->diffInMinutes($events['c']['starts_at'], true))->toBe(90.0);
});

test('repetitions are expanded with count, exceptions and moved occurrences', function () {
    $content = ics(
        "BEGIN:VEVENT\r\nUID:jf\r\nSUMMARY:JF-Dienst\r\nDTSTART;TZID=Europe/Berlin:20270602T180000\r\nDTEND;TZID=Europe/Berlin:20270602T200000\r\nRRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=4\r\nEXDATE;TZID=Europe/Berlin:20270616T180000\r\nEND:VEVENT",
        "BEGIN:VEVENT\r\nUID:jf\r\nRECURRENCE-ID;TZID=Europe/Berlin:20270630T180000\r\nSUMMARY:JF-Dienst (verlegt)\r\nDTSTART;TZID=Europe/Berlin:20270701T180000\r\nDTEND;TZID=Europe/Berlin:20270701T200000\r\nEND:VEVENT",
    );

    $events = collect(app(IcsParser::class)->parse($content, now(), now()->addYear()))->sortBy('starts_at');

    expect($events->map(fn ($e) => $e['starts_at']->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i').' '.$e['title'])->values()->all())
        ->toBe(['02.06. 18:00 JF-Dienst', '01.07. 18:00 JF-Dienst (verlegt)', '14.07. 18:00 JF-Dienst']);
});

test('the export reads back the same events', function () {
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['location' => 'Gerätehaus; Halle 2', 'description' => "Mit PSA,\nbitte pünktlich"]);
    $whole = app(EventService::class)->create(['title' => 'Feuerwehrfest', 'type' => 'veranstaltung', 'all_day' => true,
        'starts_at' => Carbon::parse('2027-07-03', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-07-05', 'Europe/Berlin')], [], null)->first();

    $ics = app(IcsExporter::class)->render(collect([$event, $whole]));
    $back = collect(app(IcsParser::class)->parse($ics, now()->subYear(), now()->addYear()))->sortBy('starts_at')->values();

    expect($ics)->toContain("BEGIN:VCALENDAR\r\n")
        ->and(collect(explode("\r\n", $ics))->every(fn ($l) => strlen($l) <= 75))->toBeTrue()
        ->and($back[0]['title'])->toBe('Übungsdienst')
        ->and($back[0]['location'])->toBe('Gerätehaus; Halle 2')
        ->and($back[0]['description'])->toBe("Mit PSA,\nbitte pünktlich")
        ->and($back[0]['starts_at']->equalTo($event->starts_at))->toBeTrue()
        ->and($back[1]['all_day'])->toBeTrue()
        ->and($back[1]['ends_at']->copy()->setTimezone('Europe/Berlin')->format('d.m.'))->toBe('05.07.');
});

test('an external calendar is imported, updated and cleaned up', function () {
    $source = Source::create(['name' => 'Kreisfeuerwehrverband', 'url' => 'webcal://kfv.example.com/termine.ics', 'type' => 'veranstaltung']);
    Http::fake(['kfv.example.com/*' => Http::sequence()
        ->push(ics("BEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Kreisfeuerwehrtag\r\nDTSTART:20270620T080000Z\r\nDTEND:20270620T150000Z\r\nEND:VEVENT",
            "BEGIN:VEVENT\r\nUID:2\r\nSUMMARY:Lehrgang\r\nDTSTART:20270701T170000Z\r\nDTEND:20270701T200000Z\r\nEND:VEVENT"))
        ->push(ics("BEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Kreisfeuerwehrtag (neu)\r\nDTSTART:20270620T080000Z\r\nDTEND:20270620T150000Z\r\nEND:VEVENT"))
        ->push('Server kaputt', 500)]);

    expect(app(SourceSync::class)->sync($source))->toBe(2)
        ->and($source->events()->pluck('type')->unique()->all())->toBe(['veranstaltung']);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://kfv.example.com/'));

    expect(app(SourceSync::class)->sync($source))->toBe(1)
        ->and($source->events()->sole()->title)->toBe('Kreisfeuerwehrtag (neu)');

    expect(app(SourceSync::class)->sync($source))->toBe(-1)
        ->and($source->refresh()->last_error)->toContain('500')
        ->and($source->events()->count())->toBe(1);
});

test('external events cannot be edited in the app', function () {
    $manager = kalenderUser('terminverwalter');
    $source = Source::create(['name' => 'Gemeinde', 'url' => 'https://gemeinde.example.com/k.ics']);
    $event = Event::create(['title' => 'Dorffest', 'type' => 'veranstaltung', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'source_id' => $source->id, 'external_uid' => 'x']);

    $this->actingAs($manager)->get(route('kalender.edit', $event))->assertForbidden();
    $this->actingAs($manager)->get(route('kalender.show', $event))->assertOk()->assertSee('aus „Gemeinde“', false);
});

test('the subscription link shows the own events and can be renewed', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $member = kalenderUser();
    kalenderEvent('2027-06-15 19:00', attributes: ['title' => 'Für alle']);
    kalenderEvent('2027-06-16 19:00', attributes: ['title' => 'Nur JF'], groups: [$youth->id]);

    $url = app(FeedService::class)->url($member);
    expect(app(FeedService::class)->url($member))->toBe($url);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('SUMMARY:Für alle')->assertDontSee('Nur JF');

    app(FeedService::class)->regenerate($member);
    $this->get($url)->assertNotFound();
    $this->get(route('kalender.feed', ['token' => str_repeat('a', 48)]))->assertNotFound();
});
