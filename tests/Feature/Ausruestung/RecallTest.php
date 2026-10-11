<?php

use Illuminate\Support\Facades\Notification;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Notifications\RecallMatchedNotification;
use Modules\Ausruestung\src\Services\LoanService;
use Modules\Ausruestung\src\Services\RecallService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    Notification::fake();
    $this->warden = ausruestungMember('geraetewart');
    $this->recalls = app(RecallService::class);
});

function recall(array $attributes = []): Recall
{
    return Recall::create($attributes + ['title' => 'Kinnriemen kann reißen', 'manufacturer' => 'Dräger', 'model' => 'HPS 7000']);
}

test('matching items are found by manufacturer and model, ignoring case and spaces', function () {
    $hit = equipment(['manufacturer' => ' dräger', 'model' => 'hps 7000 ']);
    $otherModel = equipment(['model' => 'HPS 3500']);
    $otherMaker = equipment(['manufacturer' => 'Rosenbauer']);

    $count = $this->recalls->apply(recall(), $this->warden);

    expect($count)->toBe(1)
        ->and($hit->refresh()->status)->toBe(EquipmentStatus::Locked)
        ->and($otherModel->refresh()->status)->toBe(EquipmentStatus::Available)
        ->and($otherMaker->refresh()->status)->toBe(EquipmentStatus::Available);
    Notification::assertSentTo($this->warden, RecallMatchedNotification::class);
});

test('serial ranges, batches and production periods narrow the recall', function () {
    $in = equipment(['serial_number' => '1500']);
    $below = equipment(['serial_number' => '999']); // numerically below, although "999" > "1000" as text
    $above = equipment(['serial_number' => '2001']);

    $this->recalls->apply(recall(['serial_from' => '1000', 'serial_to' => '2000']));
    expect([$in->refresh()->status, $below->refresh()->status, $above->refresh()->status])
        ->toBe([EquipmentStatus::Locked, EquipmentStatus::Available, EquipmentStatus::Available]);

    $alpha = equipment(['serial_number' => 'AB-0150']);
    $this->recalls->apply(recall(['title' => 'B', 'serial_from' => 'AB-0100', 'serial_to' => 'AB-0200']));
    expect($alpha->refresh()->status)->toBe(EquipmentStatus::Locked);

    $batch = equipment(['batch_number' => 'L-7', 'serial_number' => null]);
    $otherBatch = equipment(['batch_number' => 'L-8', 'serial_number' => null]);
    $this->recalls->apply(recall(['title' => 'C', 'batch_number' => 'L-7']));
    expect($batch->refresh()->status)->toBe(EquipmentStatus::Locked)->and($otherBatch->refresh()->status)->toBe(EquipmentStatus::Available);

    $old = equipment(['manufactured_at' => '2019-05-01', 'model' => 'X']);
    $new = equipment(['manufactured_at' => '2023-05-01', 'model' => 'X']);
    $this->recalls->apply(recall(['title' => 'D', 'model' => 'X', 'manufactured_from' => '2019-01-01', 'manufactured_to' => '2019-12-31']));
    expect($old->refresh()->status)->toBe(EquipmentStatus::Locked)->and($new->refresh()->status)->toBe(EquipmentStatus::Available);
});

test('items registered later are locked by an open recall', function () {
    recall();

    $later = equipment();

    expect($later->refresh()->status)->toBe(EquipmentStatus::Locked)
        ->and($later->recalls()->count())->toBe(1);
    Notification::assertSentTo($this->warden, RecallMatchedNotification::class);
});

test('resolving gives the item its previous status back', function () {
    $member = ausruestungMember();
    $helmet = equipment();
    app(LoanService::class)->lend($helmet, $member, $this->warden, now()->addWeek());
    $recall = recall();
    $this->recalls->apply($recall);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Locked);

    $this->recalls->resolve($recall, $helmet, $this->warden, 'Kinnriemen getauscht');

    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Loaned)
        ->and($helmet->events()->where('type', 'recall')->latest('id')->first()->body)->toContain('Kinnriemen getauscht');
});

test('an item under two recalls is released only after both', function () {
    $helmet = equipment();
    $first = recall();
    $second = recall(['title' => 'Schale']);
    $this->recalls->apply($first);
    $this->recalls->apply($second);

    $this->recalls->resolve($first, $helmet, $this->warden);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Locked);

    $this->recalls->resolve($second, $helmet, $this->warden);
    expect($helmet->refresh()->status)->toBe(EquipmentStatus::Available);
});

test('closing a recall releases all its items and new items are no longer matched', function () {
    $a = equipment();
    $b = equipment();
    $recall = recall();
    $this->recalls->apply($recall);

    $this->recalls->close($recall, $this->warden);

    expect([$a->refresh()->status, $b->refresh()->status])->toBe([EquipmentStatus::Available, EquipmentStatus::Available]);
    expect(equipment()->status)->toBe(EquipmentStatus::Available);
});

test('retired items are not affected', function () {
    $retired = equipment(['status' => EquipmentStatus::Retired]);

    expect($this->recalls->apply(recall()))->toBe(0)->and($retired->refresh()->status)->toBe(EquipmentStatus::Retired);
});
