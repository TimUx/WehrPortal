<?php

use App\Filament\Resources\LogResource\Pages\ListLogs;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\MailLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Impersonation;
use App\Services\MagicLinkService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Booking;

/** A plain notification for the log tests */
class LogTestNotification extends BaseNotification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Probealarm am Samstag')->line('Treffpunkt ist die Wache.');
    }
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'email' => 'hanna@example.com']);
    ActivityLog::query()->delete();
});

// ─── changes ─────────────────────────────────────────────────────────────────

test('changes are logged with who did what: created, changed fields, deleted', function () {
    $this->actingAs($this->hanna);
    $group = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $group->update(['name' => 'Jugendfeuerwehr Musterstadt']);
    $group->delete();

    [$created, $updated, $deleted] = ActivityLog::orderBy('id')->get();
    expect($created)->event->toBe('created')->user_id->toBe($this->hanna->id)->subject_label->toBe('Jugendfeuerwehr')
        ->and($created->changes['name'])->toBe([null, 'Jugendfeuerwehr'])
        ->and($updated->event)->toBe('updated')
        ->and($updated->changes)->toBe(['name' => ['Jugendfeuerwehr', 'Jugendfeuerwehr Musterstadt']])
        ->and($deleted->event)->toBe('deleted')
        ->and(ActivityLog::typeLabel(Group::class))->toBe('Abteilung');
});

test('changes of the Kameraden in modules are logged with the module name', function () {
    $this->actingAs($this->hanna);
    $booking = Booking::factory()->create(['user_id' => $this->hanna->id]);

    $log = ActivityLog::where('subject_type', $booking::class)->first();
    expect($log->user_id)->toBe($this->hanna->id)
        ->and(ActivityLog::typeLabel($booking::class))->toBe('Fahrzeugbuchung: Buchung');
});

test('secrets and bookkeeping are left out', function () {
    $this->actingAs($this->admin);
    $this->hanna->update(['last_login_at' => now()]);                 // bookkeeping only
    $this->hanna->update(['remember_token' => 'geheim', 'name' => 'Hanna H.']);
    Setting::set('services_openrouter_key', 'sk-or-geheim');

    expect(ActivityLog::where('event', 'updated')->where('subject_type', User::class)->count())->toBe(1)
        ->and(json_encode(ActivityLog::pluck('changes')))->not->toContain('geheim')->not->toContain('sk-or');
});

test('system changes (no one logged in) are shown as System', function () {
    Group::create(['name' => 'Musikzug', 'sort_order' => 3]);

    $this->actingAs($this->admin);
    Livewire::test(ListLogs::class)->assertSee('System')->assertSee('Musikzug');
});

test('a login is logged', function () {
    Notification::fake();
    app(MagicLinkService::class)->sendLink($this->hanna->email, Request::create('/anmelden', 'POST'));

    $raw = null;
    Notification::assertSentTo($this->hanna, MagicLinkNotification::class, function ($n) use (&$raw) {
        $raw = (fn () => $this->rawToken)->call($n);

        return true;
    });

    app(MagicLinkService::class)->consumeToken($raw, Request::create('/anmelden/'.$raw, 'POST'));

    expect(ActivityLog::where('event', 'login')->where('user_id', $this->hanna->id)->exists())->toBeTrue();
});

test('"Als Kamerad ansehen" is logged, and changes made meanwhile name the admin behind them', function () {
    $this->actingAs($this->admin);
    app(Impersonation::class)->start($this->admin, $this->hanna);
    Group::create(['name' => 'Alters- und Ehrenabteilung', 'sort_order' => 4]);

    expect(ActivityLog::where('event', 'impersonate')->first())->user_id->toBe($this->admin->id)->subject_label->toBe('Hanna Hydrant');
    $change = ActivityLog::where('event', 'created')->latest('id')->first();
    expect($change)->user_id->toBe($this->hanna->id)->impersonator_id->toBe($this->admin->id);
});

// ─── page ────────────────────────────────────────────────────────────────────

test('the Logs page is for admins, with search and filters', function () {
    $this->actingAs($this->hanna);
    Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->actingAs($this->admin);
    Group::create(['name' => 'Musikzug', 'sort_order' => 2]);
    [$byHanna, $byAdmin] = ActivityLog::orderBy('id')->get();

    $this->get('/admin/logs')->assertOk()->assertSee('Logs');
    $this->actingAs($this->hanna)->get('/admin/logs')->assertForbidden();

    Livewire::actingAs($this->admin)->test(ListLogs::class)
        ->assertCanSeeTableRecords([$byAdmin, $byHanna], inOrder: true)
        ->searchTable('Musik')->assertCanSeeTableRecords([$byAdmin])->assertCanNotSeeTableRecords([$byHanna])
        ->searchTable('')->filterTable('user_id', $this->hanna->id)->assertCanSeeTableRecords([$byHanna])->assertCanNotSeeTableRecords([$byAdmin])
        ->resetTableFilters()->filterTable('event', ['created'])->assertCanSeeTableRecords([$byHanna, $byAdmin])
        ->callTableAction('show', $byHanna)->assertSee('Jugendfeuerwehr');
});

// ─── e-mails ─────────────────────────────────────────────────────────────────

test('every sent mail is in the Logs with recipient, subject, type and content', function () {
    $this->hanna->notify(new LogTestNotification);

    $mail = MailLog::sole();
    expect($mail->to)->toBe('hanna@example.com')
        ->and($mail->user_id)->toBe($this->hanna->id)
        ->and($mail->type)->toBe(LogTestNotification::class)
        ->and($mail->html)->toContain('Treffpunkt ist die Wache');

    $entry = ActivityLog::where('event', 'mail')->sole();
    expect($entry->mail_log_id)->toBe($mail->id)->and($entry->subject_label)->toBe('An hanna@example.com: Probealarm am Samstag');

    Livewire::actingAs($this->admin)->test(ListLogs::class)->assertSee('An hanna@example.com: Probealarm am Samstag')->callTableAction('show', $entry);
});

test('login links are logged without content and cannot be sent again', function () {
    app(MagicLinkService::class)->sendLink($this->hanna->email, Request::create('/anmelden', 'POST'));

    $mail = MailLog::sole();
    expect($mail->redacted)->toBeTrue()->and($mail->html)->toBeNull()->and($mail->typeLabel())->toBe('Login-Link');

    Livewire::actingAs($this->admin)->test(ListLogs::class)->assertTableActionHidden('resend', ActivityLog::where('event', 'mail')->sole());
});

test('a mail is sent again unchanged and logged as resent', function () {
    $this->hanna->notify(new LogTestNotification);
    $entry = ActivityLog::where('event', 'mail')->sole();

    Livewire::actingAs($this->admin)->test(ListLogs::class)
        ->callTableAction('resend', $entry)
        ->assertNotified('E-Mail erneut gesendet');

    $copy = MailLog::latest('id')->first();
    expect(MailLog::count())->toBe(2)
        ->and($copy->resent_from_id)->toBe($entry->mail_log_id)
        ->and($copy->resent_by)->toBe($this->admin->id)
        ->and($copy->html)->toContain('Treffpunkt ist die Wache')
        ->and($copy->typeLabel())->toBe('Erneut gesendet');
});

test('old entries are pruned after 180 days', function () {
    ActivityLog::create(['created_at' => now()->subDays(181), 'event' => 'login', 'subject_label' => 'Alt']);
    ActivityLog::create(['created_at' => now()->subDays(10), 'event' => 'login', 'subject_label' => 'Neu']);
    MailLog::create(['sent_at' => now()->subDays(181), 'to' => 'alt@example.com', 'subject' => 'Alt']);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class, MailLog::class]])->assertSuccessful();

    expect(ActivityLog::pluck('subject_label')->all())->toBe(['Neu'])->and(MailLog::count())->toBe(0);
});
