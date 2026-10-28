<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Module::whereIn('key', ['einsatzbericht', 'kalender', 'fahrzeugbuchung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 20:00', 'Europe/Berlin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->former = User::factory()->create(['name' => 'Ehemalige Mitglieder', 'status' => 'left']);
    $this->actingAs($this->admin);

    // Hanna's traces: a booking, an event she created, two Einsätze (one together with "Ehemalige Mitglieder")
    $vehicle = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->booking = app(BookingService::class)->create(['vehicle_id' => $vehicle->id, 'purpose' => 'Übungsfahrt',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)], $this->hanna->id);
    $this->event = app(EventService::class)->create(['title' => 'Übung', 'type' => 'uebung', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)], [], null)->first();
    $this->event->forceFill(['created_by' => $this->hanna->id])->save();

    $reports = app(ReportService::class);
    foreach ([[$this->hanna, $this->former], [$this->hanna]] as $i => $crew) {
        $report = $reports->create($this->admin, now()->subDays($i + 1));
        $onVehicle = $reports->addVehicle($this->admin, $report, $vehicle->id);
        foreach ($crew as $person) {
            $reports->assign($this->admin, $onVehicle, $person);
        }
    }
    $this->hanna->moduleAccess()->create(['module' => 'kalender']);
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $this->hanna->id, 'data' => '{}', 'created_at' => now()]);
});

test('a Kamerad with data is deleted after handing everything to another one', function () {
    expect(app(MemberRemoval::class)->preview($this->hanna))->toMatchArray(['Einsatzbericht' => 2, 'Fahrzeugbuchung' => 1, 'Kalender' => 1]);
    $hannaId = $this->hanna->id;

    Livewire::test(EditUser::class, ['record' => $hannaId])
        ->assertActionVisible('transfer_delete')
        ->callAction(TestAction::make('transfer_delete'), ['to' => $this->former->id])
        ->assertHasActionErrors(['confirm']);
    expect(User::find($hannaId))->not->toBeNull();

    Livewire::test(EditUser::class, ['record' => $hannaId])
        ->callAction(TestAction::make('transfer_delete'), ['to' => $this->former->id, 'confirm' => true])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    expect(User::find($hannaId))->toBeNull()
        ->and($this->booking->fresh()->user_id)->toBe($this->former->id)
        ->and(Event::find($this->event->id)->created_by)->toBe($this->former->id)
        // In the Einsatz both were on, the crew row of Hanna is dropped instead of doubling
        ->and(DB::table('eb_report_crew')->where('user_id', $this->former->id)->count())->toBe(2)
        ->and(DB::table('module_user')->where('user_id', $hannaId)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $hannaId)->where('notifiable_type', User::class)->exists())->toBeFalse()
        // The log keeps its history: emptied, not moved
        ->and(ActivityLog::where('user_id', $this->former->id)->count())->toBe(0);

    $entry = ActivityLog::where('event', 'transferred')->sole();
    expect($entry->subject_label)->toBe('Hanna Hydrant → Ehemalige Mitglieder')
        ->and($entry->changes['Fahrzeugbuchung'])->toBe(['1 Einträge', 'Ehemalige Mitglieder']);
});

test('nobody hands to themselves or deletes themselves; nothing changes then', function () {
    $removal = app(MemberRemoval::class);

    expect(fn () => $removal->transferAndDelete($this->hanna, $this->hanna, $this->admin))->toThrow(ValidationException::class, 'anderen Kameraden')
        ->and(fn () => $removal->transferAndDelete($this->admin, $this->former, $this->admin))->toThrow(ValidationException::class, 'selbst');
    Livewire::test(EditUser::class, ['record' => $this->admin->id])->assertActionHidden('transfer_delete');

    expect($this->booking->fresh()->user_id)->toBe($this->hanna->id);
});

test('the Verbandbuch moves no health data: the links are emptied, the names stay', function () {
    expect(MemberRemoval::UNLINK)->toContain('vb_entries.injured_user_id', 'vb_entries.first_aider_user_id', 'vb_entries.recorded_by')
        ->and(app(MemberRemoval::class)->references()->map(fn ($r) => $r['table'].'.'.$r['column'])->all())
        ->toContain('bookings.user_id', 'eb_report_crew.user_id', 'vb_entries.injured_user_id')
        ->not->toContain('activity_logs.user_id', 'group_user.user_id');
});
