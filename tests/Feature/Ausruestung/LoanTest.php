<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Services\LoanService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateAusruestung();
    Notification::fake();
    $this->warden = ausruestungMember('geraetewart');
    $this->member = ausruestungMember(attributes: ['name' => 'Hanna Hydrant']);
    $this->loans = app(LoanService::class);
    $this->helmet = equipment();
});

test('lending hands the item out and the return brings it back', function () {
    $loan = $this->loans->lend($this->helmet, $this->member, $this->warden, now()->addWeek());

    expect($loan->status)->toBe(LoanStatus::Active)->and($this->helmet->refresh()->status)->toBe(EquipmentStatus::Loaned);

    $this->loans->return($loan, $this->warden);

    expect($loan->refresh()->status)->toBe(LoanStatus::Returned)->and($this->helmet->refresh()->status)->toBe(EquipmentStatus::Available);
});

test('a damage noticed on return takes the item out of service', function () {
    $loan = $this->loans->lend($this->helmet, $this->member, $this->warden, now()->addWeek());

    $this->loans->return($loan, $this->warden, 'Riss in der Schale');

    expect($this->helmet->refresh()->status)->toBe(EquipmentStatus::Defective)
        ->and($this->helmet->damages()->sole()->description)->toBe('Riss in der Schale');
});

test('overlapping reservations are refused, back-to-back ones are fine', function () {
    $this->loans->reserve($this->helmet, $this->member, $this->member, now()->addDay()->setTime(8, 0), now()->addDay()->setTime(18, 0));

    expect(fn () => $this->loans->reserve($this->helmet, $this->warden, $this->warden, now()->addDay()->setTime(12, 0), now()->addDays(2)))
        ->toThrow(ValidationException::class);

    $this->loans->reserve($this->helmet, $this->warden, $this->warden, now()->addDay()->setTime(18, 0), now()->addDays(2));
    expect($this->helmet->loans()->count())->toBe(2);
});

test('the return date must lie after the start', function () {
    expect(fn () => $this->loans->reserve($this->helmet, $this->member, $this->member, now()->addDay(), now()))
        ->toThrow(ValidationException::class);
});

test('defective, locked or retired items cannot be reserved', function () {
    foreach ([EquipmentStatus::Defective, EquipmentStatus::Locked, EquipmentStatus::Retired] as $status) {
        $this->helmet->update(['status' => $status]);

        expect(fn () => $this->loans->reserve($this->helmet, $this->member, $this->member, now()->addDay(), now()->addDays(2)))
            ->toThrow(ValidationException::class);
    }
});

test('nothing unfit for use is handed out', function () {
    $loan = $this->loans->reserve($this->helmet, $this->member, $this->member, now(), now()->addDay());

    $this->helmet->update(['next_inspection_at' => today()->subDay()]);
    expect(fn () => $this->loans->handOut($loan, $this->warden))->toThrow(ValidationException::class, 'Prüfung überfällig');

    $this->helmet->update(['next_inspection_at' => today()->addMonth(), 'manufactured_at' => today()->subYears(11)]);
    expect(fn () => $this->loans->handOut($loan, $this->warden))->toThrow(ValidationException::class, 'Lebensdauer abgelaufen');

    expect($loan->refresh()->status)->toBe(LoanStatus::Reserved);
});

test('a reservation can be cancelled but not a running loan', function () {
    $reservation = $this->loans->reserve($this->helmet, $this->member, $this->member, now()->addDay(), now()->addDays(2));
    $this->loans->cancel($reservation, $this->member);
    expect($reservation->refresh()->status)->toBe(LoanStatus::Cancelled);

    $loan = $this->loans->lend($this->helmet, $this->member, $this->warden, now()->addDay());
    expect(fn () => $this->loans->cancel($loan, $this->member))->toThrow(ValidationException::class);
});
