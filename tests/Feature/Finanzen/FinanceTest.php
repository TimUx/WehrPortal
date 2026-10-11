<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Finanzen\src\Livewire\BudgetPlan;
use Modules\Finanzen\src\Livewire\EntryDetail;
use Modules\Finanzen\src\Livewire\EntryForm;
use Modules\Finanzen\src\Livewire\EntryList;
use Modules\Finanzen\src\Livewire\Overview;
use Modules\Finanzen\src\Livewire\YearReport;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Notifications\ExpenseDecidedNotification;
use Modules\Finanzen\src\Notifications\ExpensePaidNotification;
use Modules\Finanzen\src\Notifications\ExpenseSubmittedNotification;
use Modules\Finanzen\src\Services\FinanceExport;
use Modules\Finanzen\src\Services\FinanceService;

beforeEach(function () {
    Module::where('key', 'finanzen')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 12:00', 'Europe/Berlin'));
    Notification::fake();

    $this->kasse = User::factory()->create(['name' => 'Klara Kübel']);
    $this->kasse->moduleAccess()->create(['module' => 'finanzen', 'role' => 'kassenwart']);
    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $this->chief->moduleAccess()->create(['module' => 'finanzen', 'role' => 'wehrführung']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'finanzen']);
    $this->other = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->other->moduleAccess()->create(['module' => 'finanzen']);

    $this->f = app(FinanceService::class);
    $this->jf = $this->f->saveCostCenter($this->kasse, null, ['name' => 'Jugendfeuerwehr', 'code' => '200']);
    $this->ea = $this->f->saveCostCenter($this->kasse, null, ['name' => 'Einsatzabteilung', 'code' => '100']);
    $this->f->setBudget($this->kasse, $this->jf, 2027, 150000);
});

function expense(User $by, int $cents = 2490, ?CostCenter $center = null, array $files = []): Entry
{
    return app(FinanceService::class)->create($by, ['cost_center_id' => ($center ?? test()->jf)->id, 'title' => 'Verpflegung Zeltlager', 'amount_cents' => $cents, 'booked_on' => '2027-05-08', 'vendor' => 'Edeka'], $files);
}

test('amounts are typed and shown the German way', function () {
    expect(Money::parse('1.234,56'))->toBe(123456)->and(Money::parse('24,9'))->toBe(2490)->and(Money::parse('12.50'))->toBe(1250)
        ->and(Money::parse('1.500'))->toBe(150000)->and(Money::parse('12 €'))->toBe(1200)->and(Money::parse('abc'))->toBeNull()->and(Money::parse('1,234'))->toBeNull()
        ->and(Money::format(123456))->toBe('1.234,56 €')->and(Money::format(-500))->toBe('−5,00 €');
});

test('a Kamerad submits an Ausgabe with a photo; the reviewers are told, not himself', function () {
    $this->travelBack();                       // Livewire's temporary uploads expire under time travel

    Livewire::actingAs($this->member)->test(EntryForm::class)
        ->set('title', 'Verpflegung Zeltlager')->set('amount', '24,90')->set('costCenterId', (string) $this->jf->id)
        ->set('uploads', [UploadedFile::fake()->image('bon.jpg', 2000, 1500)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $entry = Entry::sole();
    expect($entry)->status->toBe('submitted')->amount_cents->toBe(2490)->code()->toBe(now('Europe/Berlin')->year.'-001')
        ->and($entry->receipts()->sole()->mime)->toBe('image/webp');
    Notification::assertSentTo([$this->kasse, $this->chief], ExpenseSubmittedNotification::class);
    Notification::assertNotSentTo($this->member, ExpenseSubmittedNotification::class);

    // Without a Beleg a Kamerad can't submit
    Livewire::actingAs($this->member)->test(EntryForm::class)
        ->set('title', 'Ohne')->set('amount', '5')->set('costCenterId', (string) $this->jf->id)->call('save')->assertHasErrors('uploads');
});

test('input is checked: amount, Kostenstelle, date', function () {
    expect(fn () => expense($this->member, 0))->toThrow(ValidationException::class, 'Betrag')
        ->and(fn () => $this->f->create($this->member, ['cost_center_id' => 999, 'title' => 'x', 'amount_cents' => 100, 'booked_on' => '2027-05-08']))->toThrow(ValidationException::class, 'Kostenstelle')
        ->and(fn () => $this->f->create($this->member, ['cost_center_id' => $this->jf->id, 'title' => 'x', 'amount_cents' => 100, 'booked_on' => '2027-09-01']))->toThrow(ValidationException::class, 'Zukunft');

    $this->f->saveCostCenter($this->kasse, $this->ea, ['name' => 'Einsatzabteilung', 'is_active' => false]);
    expect(fn () => expense($this->member, 100, $this->ea))->toThrow(ValidationException::class, 'Kostenstelle');
});

test('approve, pay: the Kamerad hears of each step; nobody approves the own', function () {
    $entry = expense($this->member);

    Livewire::actingAs($this->member)->test(EntryDetail::class, ['entry' => $entry])->assertDontSee('Genehmigen?');
    expect(fn () => $this->f->decide($this->member, $entry, true))->toThrow(ValidationException::class);

    Livewire::actingAs($this->chief)->test(EntryDetail::class, ['entry' => $entry])
        ->assertSee('Genehmigen?')->call('approve')->assertSee('Genehmigt.');
    expect($entry->refresh())->status->toBe('approved')->decided_by->toBe($this->chief->id);
    Notification::assertSentTo($this->member, ExpenseDecidedNotification::class);

    // The Wehrführung can't pay, the Kassenwart can
    expect(fn () => $this->f->pay($this->chief, $entry))->toThrow(ValidationException::class);
    Livewire::actingAs($this->kasse)->test(EntryDetail::class, ['entry' => $entry])
        ->set('paymentNote', 'Überweisung')->call('pay')->assertSee('Als bezahlt eingetragen.');
    expect($entry->refresh())->status->toBe('paid')->payment_note->toBe('Überweisung');
    Notification::assertSentTo($this->member, ExpensePaidNotification::class);

    // A second decision is refused
    expect(fn () => $this->f->decide($this->kasse, $entry, false, 'zu spät'))->toThrow(ValidationException::class, 'schon entschieden');

    // The own submission of a reviewer goes to the other one
    $own = expense($this->kasse);
    expect(fn () => $this->f->decide($this->kasse, $own, true))->toThrow(ValidationException::class, 'eigene');
    $this->f->decide($this->chief, $own, true);
    expect($own->refresh()->status)->toBe('approved');
});

test('a rejection needs a comment and tells the Kamerad', function () {
    $entry = expense($this->member);

    Livewire::actingAs($this->kasse)->test(EntryDetail::class, ['entry' => $entry])
        ->call('reject')->assertHasErrors('comment')
        ->set('comment', 'Bitte mit der Jugendwartin absprechen')->call('reject')->assertSee('Abgelehnt.');

    expect($entry->refresh())->status->toBe('rejected')->decision_comment->toBe('Bitte mit der Jugendwartin absprechen');
    Notification::assertSentTo($this->member, ExpenseDecidedNotification::class);
});

test('the submitter changes or withdraws only while it waits; others see neither it nor its Beleg', function () {
    $entry = expense($this->member, files: [UploadedFile::fake()->create('rechnung.pdf', 100, 'application/pdf')]);
    $receipt = $entry->receipts()->sole();

    $this->actingAs($this->other)->get(route('finanzen.entry', $entry))->assertForbidden();
    $this->actingAs($this->other)->get(route('finanzen.receipt', $receipt))->assertForbidden();
    $this->actingAs($this->member)->get(route('finanzen.receipt', $receipt))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->chief)->get(route('finanzen.receipt', $receipt))->assertOk();

    $this->f->update($this->member, $entry, ['cost_center_id' => $this->jf->id, 'title' => 'Verpflegung Zeltlager', 'amount_cents' => 2990, 'booked_on' => '2027-05-08']);
    expect($entry->refresh()->amount_cents)->toBe(2990);

    $this->f->decide($this->chief, $entry, true);
    expect(fn () => $this->f->update($this->member, $entry->refresh(), ['cost_center_id' => $this->jf->id, 'title' => 'x', 'amount_cents' => 1, 'booked_on' => '2027-05-08']))
        ->toThrow(ValidationException::class, 'Kassenwart');
    $this->actingAs($this->member)->get(route('finanzen.edit', $entry))->assertForbidden();

    $waiting = expense($this->member);
    Livewire::actingAs($this->member)->test(EntryDetail::class, ['entry' => $waiting])->call('delete')->assertRedirect(route('finanzen.index'));
    expect(Entry::find($waiting->id))->toBeNull();
});

test('everyone sees the budget status and the spent money, without Belege of others', function () {
    $entry = expense($this->member, 50000);
    $this->f->decide($this->chief, $entry, true);
    $paid = expense($this->member, 25000);
    $this->f->decide($this->chief, $paid, true);
    $this->f->pay($this->kasse, $paid);
    expense($this->member, 9999);                       // waiting: not counted

    $row = $this->f->overview(2027)->firstWhere(fn ($r) => $r['center']->is($this->jf));
    expect($row)->toMatchArray(['budget' => 150000, 'approved' => 50000, 'paid' => 25000, 'available' => 75000, 'percent' => 50]);

    Livewire::actingAs($this->other)->test(Overview::class)
        ->assertSee('Jugendfeuerwehr')->assertSee('1.500,00 €')->assertSee('750,00 €')
        ->assertSee('Verpflegung Zeltlager')->assertSee('genehmigt, noch nicht bezahlt')
        ->assertDontSee('99,99 €');
    $this->actingAs($this->other)->get('/finanzen/buchungen')->assertForbidden();
    $this->actingAs($this->other)->get('/finanzen/budget')->assertForbidden();
});

test('the Kassenwart books Einnahmen and paid Ausgaben directly; Belegnummern run per year', function () {
    Livewire::actingAs($this->kasse)->test(EntryForm::class, ['direct' => true])
        ->set('kind', 'income')->set('title', 'Spende Sparkasse')->set('amount', '500')->set('costCenterId', (string) $this->jf->id)->set('bookedOn', '2027-03-01')
        ->call('save')->assertHasNoErrors();
    $income = Entry::sole();
    expect($income)->kind->toBe('income')->status->toBe('paid')->code()->toBe('2027-001')->statusLabel()->toBe('Gebucht');
    Notification::assertNothingSent();

    // A Kamerad can't book directly
    $this->f->create($this->member, ['kind' => 'income', 'cost_center_id' => $this->jf->id, 'title' => 'x', 'amount_cents' => 100, 'booked_on' => '2027-03-01'], [], direct: true);
    expect(Entry::latest('id')->first())->kind->toBe('expense')->status->toBe('submitted')->number->toBe(2);

    $this->f->create($this->kasse, ['cost_center_id' => $this->jf->id, 'title' => 'Altes Jahr', 'amount_cents' => 100, 'booked_on' => '2026-12-30'], [], direct: true);
    expect(Entry::where('year', 2026)->sole()->code())->toBe('2026-001');

    Livewire::actingAs($this->chief)->test(EntryList::class)->assertSee('Spende Sparkasse')->assertSee('+500,00 €');
    $this->actingAs($this->member)->get('/finanzen/buchen')->assertForbidden();
});

test('budgets are planned per year and can be copied from the year before', function () {
    Livewire::actingAs($this->kasse)->test(BudgetPlan::class)
        ->set("amounts.{$this->ea->id}", '2.000,00')->call('saveBudgets')->assertSee('Budget 2027 gespeichert.')
        ->set('year', 2028)->call('copyPrevious')->assertSee('2 Budgets aus 2027 übernommen.');

    expect($this->f->overview(2028)->sum('budget'))->toBe(350000);

    Livewire::actingAs($this->kasse)->test(BudgetPlan::class)->set("amounts.{$this->jf->id}", 'viel')->call('saveBudgets')->assertHasErrors("amounts.{$this->jf->id}");

    // Used Kostenstellen stay
    expense($this->member);
    expect(fn () => $this->f->deleteCostCenter($this->kasse, $this->jf))->toThrow(ValidationException::class, 'Buchungen');
    $unused = $this->f->saveCostCenter($this->kasse, null, ['name' => 'Kameradschaftskasse']);
    $this->f->deleteCostCenter($this->kasse, $unused);
    expect(CostCenter::count())->toBe(2);
});

test('the Jahresabschluss needs all Ausgaben settled and locks the year; only admins open it again', function () {
    $entry = expense($this->member);

    Livewire::actingAs($this->kasse)->test(YearReport::class)->assertSee('1 offene Ausgabe');
    expect(fn () => $this->f->closeYear($this->kasse, 2027))->toThrow(ValidationException::class, 'noch nicht entschieden');

    $this->f->decide($this->chief, $entry, true);
    $this->f->pay($this->kasse, $entry);
    Livewire::actingAs($this->kasse)->test(YearReport::class)->call('close')->assertSee('ist abgeschlossen');

    expect(fn () => expense($this->member))->toThrow(ValidationException::class, 'abgeschlossen')
        ->and(fn () => $this->f->delete($this->kasse, $entry->refresh()))->toThrow(ValidationException::class, 'abgeschlossen')
        ->and(fn () => $this->f->setBudget($this->kasse, $this->jf, 2027, 1))->toThrow(ValidationException::class, 'abgeschlossen')
        ->and(fn () => $this->f->reopenYear($this->kasse, 2027))->toThrow(ValidationException::class, 'Admins');

    $this->f->reopenYear(User::factory()->admin()->create(), 2027);
    expect(FinanceService::isClosed(2027))->toBeFalse();
});

test('export: CSV for Excel and a ZIP of the Belege, named by Belegnummer; print view', function () {
    $entry = expense($this->member, 2490, files: [UploadedFile::fake()->create('rechnung.pdf', 50, 'application/pdf')]);
    $this->f->decide($this->chief, $entry, true);
    expense($this->member, 999);                                           // waiting, not exported

    $csv = app(FinanceExport::class)->csv(2027);
    expect($csv)->toStartWith("\u{FEFF}Belegnummer;Belegdatum")
        ->toContain('2027-001;08.05.2027;Ausgabe;200;Jugendfeuerwehr;"Verpflegung Zeltlager";Edeka;-24,90')
        ->not->toContain('9,99');

    $this->actingAs($this->chief)->get('/finanzen/export/2027/buchungen.csv')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Buchungen-2027.csv"');
    $zip = $this->actingAs($this->chief)->get('/finanzen/export/2027/belege.zip')->assertOk();
    $archive = new ZipArchive;
    $archive->open($zip->getFile()->getPathname());
    expect($archive->locateName('2027-001_verpflegung-zeltlager.pdf'))->not->toBeFalse()
        ->and($archive->locateName('Buchungen 2027.csv'))->not->toBeFalse();

    $this->actingAs($this->chief)->get('/finanzen/jahresabschluss/2027/druck')->assertOk()->assertSee('Jahresabschluss 2027')->assertSee('Kassenprüfer');
    $this->actingAs($this->member)->get('/finanzen/export/2027/buchungen.csv')->assertForbidden();
});

test('the start page tells reviewers, the Kassenwart and the Kamerad what waits', function () {
    $entry = expense($this->member);
    Livewire::actingAs($this->chief)->test(Home::class)->assertSee('1 Ausgabe zu genehmigen');
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('1 eigene Ausgabe in Bearbeitung');

    $this->f->decide($this->chief, $entry, true);
    Livewire::actingAs($this->kasse)->test(Home::class)->assertSee('1 genehmigte Ausgabe noch zu bezahlen');
    Livewire::actingAs($this->other)->test(Home::class)->assertDontSee('zu genehmigen')->assertDontSee('in Bearbeitung');
});

test('the pages open', function () {
    $entry = expense($this->member);
    foreach (['/finanzen', '/finanzen/einreichen', '/finanzen/buchungen', '/finanzen/budget', '/finanzen/jahresabschluss', '/finanzen/buchen', '/finanzen/buchung/'.$entry->id, '/finanzen/buchung/'.$entry->id.'/bearbeiten'] as $url) {
        $this->actingAs($this->kasse)->get($url)->assertOk();
    }
    Module::where('key', 'finanzen')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->kasse)->get('/finanzen')->assertNotFound();
});
