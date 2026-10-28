<?php

use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Resources\MemberFunctionResource\Pages\ManageMemberFunctions;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use App\Services\MemberStructureTransfer;
use Extensions\Locations\Models\Location;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'email' => 'hanna@example.com']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht', 'email' => 'bernd@example.com']);
});

test('Abteilungen are exported as CSV with order and members', function () {
    Group::query()->delete();
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $active = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $youth->users()->attach([$this->hanna->id, $this->bernd->id]);

    $csv = app(MemberStructureTransfer::class)->exportGroups();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain("Abteilung;Standort;Reihenfolge;Mitglieder\nEinsatzabteilung;Hauptstandort;1;\nJugendfeuerwehr;Hauptstandort;2;\"bernd@example.com, hanna@example.com\"");

    Livewire::test(ListGroups::class)->callAction('export')->assertFileDownloaded('abteilungen-'.now()->format('Y-m-d').'.csv');
});

test('Abteilung transfers distinguish same-named groups by Standort', function () {
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'wache-sued']);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'wache-nord']);
    $southGroup = $south->groups()->where('name', 'Einsatzabteilung')->firstOrFail();
    $northGroup = $north->groups()->where('name', 'Einsatzabteilung')->firstOrFail();
    $csv = "Abteilung;Standort;Reihenfolge;Mitglieder\nEinsatzabteilung;Wache Süd;1;hanna@example.com\nEinsatzabteilung;Wache Nord;2;bernd@example.com\n";

    $stats = app(MemberStructureTransfer::class)->importGroups($csv, true);

    expect($stats['updated'])->toBe(2)
        ->and($southGroup->fresh()->users()->pluck('users.id')->all())->toBe([$this->hanna->id])
        ->and($northGroup->fresh()->users()->pluck('users.id')->all())->toBe([$this->bernd->id]);
});

test('legacy Abteilung transfers are rejected when duplicate names need a Standort', function () {
    $south = Location::create(['name' => 'Wache Süd', 'slug' => 'wache-sued']);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'wache-nord']);
    expect(fn () => app(MemberStructureTransfer::class)->importGroups(
        "Abteilung;Reihenfolge;Mitglieder\nEinsatzabteilung;1;\n", false
    ))->toThrow(ValidationException::class, 'mehreren Standorten');
});

test('importing Abteilungen adds and updates by name, adds members, reports unknown addresses', function () {
    Group::query()->delete();
    Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 5]);
    $csv = "\xEF\xBB\xBFAbteilung;Reihenfolge;Mitglieder\neinsatzabteilung;1;HANNA@example.com\nAlters- und Ehrenabteilung;3;bernd@example.com, niemand@example.com\n";

    $stats = app(MemberStructureTransfer::class)->importGroups($csv, true);

    expect($stats)->toMatchArray(['created' => 1, 'updated' => 1, 'members' => 2, 'unknown' => ['niemand@example.com']])
        ->and(Group::where('name', 'Einsatzabteilung')->value('sort_order'))->toBe(1)
        ->and(Group::count())->toBe(2)
        ->and($this->hanna->groups()->pluck('name')->all())->toBe(['Einsatzabteilung']);

    // Without members only the Abteilungen
    $stats = app(MemberStructureTransfer::class)->importGroups("Abteilung;Reihenfolge;Mitglieder\nJugendfeuerwehr;4;hanna@example.com\n", false);
    expect($stats['members'])->toBe(0)->and($this->hanna->groups()->count())->toBe(1);
});

test('the import action in the admin reads an uploaded file', function () {
    Group::query()->delete();
    $file = UploadedFile::fake()->createWithContent('abteilungen.csv', "Abteilung;Reihenfolge;Mitglieder\nJugendfeuerwehr;1;hanna@example.com\n");

    Livewire::test(ListGroups::class)
        ->callAction('import', data: ['file' => $file, 'members' => true])
        ->assertNotified('Abteilungen importiert');

    expect($this->hanna->groups()->pluck('name')->all())->toBe(['Jugendfeuerwehr']);
});

test('a file with other columns is refused', function () {
    expect(fn () => app(MemberStructureTransfer::class)->importGroups("Name;Irgendwas\nA;B\n", true))
        ->toThrow(ValidationException::class, 'erwarteten Spalten');
});

test('Funktionen go out and come back with sub-functions and members', function () {
    MemberFunction::query()->delete();
    $youth = MemberFunction::create(['name' => 'Jugendwart', 'sort_order' => 1]);
    $deputy = $youth->children()->create(['name' => 'Stellvertretung', 'sort_order' => 1]);
    $press = MemberFunction::create(['name' => 'Pressewart', 'sort_order' => 2]);
    $deputy->users()->attach($this->hanna);
    $press->users()->attach($this->bernd);

    $csv = app(MemberStructureTransfer::class)->exportFunctions();
    expect($csv)->toContain("Funktion;Unterfunktion;Reihenfolge;Mitglieder\nJugendwart;;1;\nJugendwart;Stellvertretung;1;hanna@example.com\nPressewart;;2;bernd@example.com");

    MemberFunction::query()->delete();
    $stats = app(MemberStructureTransfer::class)->importFunctions($csv, true);

    expect($stats)->toMatchArray(['created' => 3, 'members' => 2])
        ->and(MemberFunction::where('name', 'Stellvertretung')->first()->parent->name)->toBe('Jugendwart')
        ->and($this->hanna->functions()->pluck('name')->all())->toBe(['Stellvertretung']);

    // A second import changes nothing
    expect(app(MemberStructureTransfer::class)->importFunctions($csv, true))->toMatchArray(['created' => 0, 'members' => 0]);

    Livewire::test(ManageMemberFunctions::class)->callAction('export')->assertFileDownloaded('funktionen-'.now()->format('Y-m-d').'.csv');
});
