<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource\Pages\ListEquipmentMessages;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Livewire\EquipmentMessageForm;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Notifications\EquipmentMessageNotification;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'kleiderkammer']);
    $this->warden = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $this->item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => ItemStatus::Issued]);
    $this->issued = IssuedItem::create(['user_id' => $this->member->id, 'item_id' => $this->item->id, 'issued_at' => now()]);
});

test('a member sends a message about their item and the Kleiderwart is notified', function () {
    Notification::fake();

    Livewire::actingAs($this->member)
        ->test(EquipmentMessageForm::class, ['issuedItemId' => $this->issued->id])
        ->set('body', 'Reißverschluss klemmt.')
        ->call('send')
        ->assertHasNoErrors();

    $message = EquipmentMessage::sole();
    expect($message->user_id)->toBe($this->member->id)
        ->and($message->item_label)->toContain('Einsatzjacke')->toContain('WEHREJA0001');

    Notification::assertSentTo($this->warden, EquipmentMessageNotification::class);
    Notification::assertNotSentTo($this->member, EquipmentMessageNotification::class);
});

test('without a Kleiderwart the admins are notified', function () {
    Notification::fake();
    $this->warden->moduleAccess()->delete();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($this->member)
        ->test(EquipmentMessageForm::class, ['issuedItemId' => $this->issued->id])
        ->set('body', 'Passt nicht.')
        ->call('send');

    Notification::assertSentTo($admin, EquipmentMessageNotification::class);
});

test('an empty message is rejected', function () {
    Livewire::actingAs($this->member)
        ->test(EquipmentMessageForm::class, ['issuedItemId' => $this->issued->id])
        ->set('body', '  ')
        ->call('send')
        ->assertHasErrors('body');

    expect(EquipmentMessage::count())->toBe(0);
});

test('members cannot write about equipment of someone else or returned equipment', function () {
    $other = User::factory()->create();
    $other->moduleAccess()->create(['module' => 'kleiderkammer']);

    Livewire::actingAs($other)
        ->test(EquipmentMessageForm::class, ['issuedItemId' => $this->issued->id])
        ->set('body', 'Hallo')
        ->call('send')
        ->assertNotFound();

    $this->issued->update(['returned_at' => now()]);

    Livewire::actingAs($this->member)
        ->test(EquipmentMessageForm::class, ['issuedItemId' => $this->issued->id])
        ->set('body', 'Hallo')
        ->call('send')
        ->assertNotFound();
});

test('my equipment shows the message form for each item', function () {
    $this->actingAs($this->member)
        ->get('/kleiderkammer')
        ->assertOk()
        ->assertSee('Nachricht an den Kleiderwart');
});

test('scanning an issued item opens the member, an available one the item', function () {
    $this->actingAs($this->warden)
        ->get('/i/WEHREJA0001')
        ->assertRedirect(url("/admin/kleiderkammer/kameraden/{$this->member->id}/bearbeiten"));

    $this->issued->update(['returned_at' => now()]);

    $this->actingAs($this->warden)
        ->get('/i/WEHREJA0001')
        ->assertRedirect(url("/admin/kleiderkammer/einzelteile/{$this->item->id}/bearbeiten"));
});

test('the member profile shows open messages per issued item', function () {
    $this->actingAs($this->warden);
    Filament::setCurrentPanel(Filament::getPanel('kleiderkammer'));
    app(EquipmentMessageService::class)->send($this->member, $this->issued, 'Reißverschluss klemmt.');

    expect(IssuancesRelationManager::getBadge($this->member, 'x'))->toBe('1');

    Livewire::test(IssuancesRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->assertCanSeeTableRecords([$this->issued])
        ->assertSee('1 offen')
        ->mountTableAction('messages', $this->issued)
        ->tap(fn ($c) => expect((string) $c->instance()->getMountedAction()->getModalContent())->toContain('Reißverschluss klemmt.'));
});

test('the message list shortens the text and opens the full message on click', function () {
    $this->actingAs($this->warden);
    Filament::setCurrentPanel(Filament::getPanel('kleiderkammer'));
    $long = str_repeat('Lang ', 40).'ENDE';
    $message = app(EquipmentMessageService::class)->send($this->member, $this->issued, $long);

    Livewire::test(ListEquipmentMessages::class)
        ->assertCanSeeTableRecords([$message])
        ->assertDontSee('ENDE')
        ->mountTableAction('open', $message)
        ->tap(fn ($c) => expect((string) $c->instance()->getMountedAction()->getModalContent())->toContain('ENDE'));
});
