<?php

use App\Filament\Widgets\BirthdaysWidget;
use App\Livewire\Dashboard\Home;
use App\Models\User;
use App\Services\Birthdays;
use App\Support\MemberFields;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-06-10 09:00', 'Europe/Berlin'));
    User::query()->update(['birth_date' => null]);
});

test('the next birthdays come in date order across the turn of the year', function () {
    User::factory()->create(['name' => 'Januar', 'birth_date' => '1990-01-05']);
    User::factory()->create(['name' => 'Juni', 'birth_date' => '2000-06-12']);
    User::factory()->create(['name' => 'Heute', 'birth_date' => '1985-06-10']);
    User::factory()->create(['name' => 'Gestern', 'birth_date' => '1985-06-09']);
    User::factory()->create(['name' => 'Ausgetreten', 'birth_date' => '1985-06-11', 'status' => 'left']);

    $next = app(Birthdays::class)->upcoming(4);

    expect($next->map(fn ($b) => $b['user']->name.' '.$b['date']->format('d.m.Y').' '.$b['age'].' '.$b['days'])->all())->toBe([
        'Heute 10.06.2027 42 0',
        'Juni 12.06.2027 27 2',
        'Januar 05.01.2028 38 209',
        'Gestern 09.06.2028 43 365',
    ]);
});

test('29 February is celebrated on 28 February in other years', function () {
    User::factory()->create(['name' => 'Schalttag', 'birth_date' => '2004-02-29']);
    $this->travelTo(Carbon::parse('2027-02-01', 'Europe/Berlin'));

    expect(app(Birthdays::class)->upcoming(1)->first()['date']->format('d.m.Y'))->toBe('28.02.2027');
});

test('the birthday card shows day and month only, without year or age (when the birth date is switched on)', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'birth_date' => '1990-06-12']);
    $html = view('dashboard.birthday-card', ['birthdays' => app(Birthdays::class)->next()])->render();

    expect($html)->toContain('Nächster Geburtstag')->toContain('Hanna Hydrant')->toContain('12.06.')->not->toContain('1990');

    // The admin widget lists the age
    expect(app(Birthdays::class)->upcoming(5)->first())->toMatchArray(['age' => 37]);
    expect(BirthdaysWidget::canView())->toBe(MemberFields::BIRTH_DATE);
});

test('birthday cards are off while the birth date is switched off', function () {
    expect(MemberFields::BIRTH_DATE)->toBeFalse();
    User::factory()->create(['name' => 'Hanna Hydrant', 'birth_date' => '1990-06-12']);

    Livewire::actingAs(User::factory()->create())->test(Home::class)->assertDontSee('Nächster Geburtstag')->assertDontSee('Hanna Hydrant');
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->assertDontSee('Nächste Geburtstage');
});

test('several Kameraden on the same day appear together', function () {
    User::factory()->create(['name' => 'Anna', 'birth_date' => '1990-06-15']);
    User::factory()->create(['name' => 'Ben', 'birth_date' => '1995-06-15']);
    User::factory()->create(['name' => 'Clara', 'birth_date' => '1995-07-01']);

    expect(app(Birthdays::class)->next()->map(fn ($b) => $b['user']->name)->all())->toBe(['Anna', 'Ben']);
});

test('a Kamerad who switched the birthday off stays hidden', function () {
    User::factory()->create(['name' => 'Hanna Hydrant', 'birth_date' => '1990-06-12', 'show_birthday' => false]);

    expect(app(Birthdays::class)->next())->toBeEmpty();
});
