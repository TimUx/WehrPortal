<?php

use App\Filament\Resources\FeedbackThreadResource;
use App\Filament\Resources\FeedbackThreadResource\Pages\ListFeedbackThreads;
use App\Filament\Resources\FeedbackThreadResource\Pages\ViewFeedbackThread;
use App\Livewire\Feedback\FeedbackConversation;
use App\Livewire\Feedback\FeedbackList;
use App\Models\FeedbackThread;
use App\Models\User;
use App\Notifications\FeedbackAnsweredNotification;
use App\Notifications\FeedbackReceivedNotification;
use App\Services\FeedbackService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->member = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

function sendFeedback(): FeedbackThread
{
    Livewire::actingAs(test()->member)
        ->test(FeedbackList::class)
        ->set('showForm', true)
        ->set('subject', 'Idee')
        ->set('body', 'Bitte Monatsansicht farbiger')
        ->call('send')
        ->assertHasNoErrors();

    return FeedbackThread::sole();
}

test('logo links to the module overview', function () {
    $this->actingAs($this->member)->get(route('profile'))
        ->assertSee('href="'.route('home').'"', false);
});

test('member sends feedback and admins get a mail with a link', function () {
    $thread = sendFeedback();

    expect($thread->user_id)->toBe($this->member->id)
        ->and($thread->unread_for_admin)->toBeTrue()
        ->and($thread->messages)->toHaveCount(1);

    Notification::assertSentTo($this->admin, FeedbackReceivedNotification::class,
        fn ($n, $c, $notifiable) => $n->toMail($notifiable)->actionUrl === FeedbackThreadResource::getUrl('view', ['record' => $thread]));
    Notification::assertNotSentTo($this->member, FeedbackReceivedNotification::class);
});

test('feedback needs subject and message', function () {
    Livewire::actingAs($this->member)
        ->test(FeedbackList::class)
        ->call('send')
        ->assertHasErrors(['subject', 'body']);
});

test('admin sees feedback, reading marks it read, reply reaches the member', function () {
    $thread = sendFeedback();
    $this->actingAs($this->admin);

    Livewire::test(ListFeedbackThreads::class)->assertCanSeeTableRecords([$thread]);
    expect(FeedbackThreadResource::getNavigationBadge())->toBe('1');

    Livewire::test(ViewFeedbackThread::class, ['record' => $thread->id])
        ->assertSee('Bitte Monatsansicht farbiger')
        ->callAction('reply', data: ['body' => 'Danke, kommt!', 'close' => true])
        ->assertHasNoActionErrors();

    $thread->refresh();
    expect($thread->unread_for_admin)->toBeFalse()
        ->and($thread->unread_for_user)->toBeTrue()
        ->and($thread->isClosed())->toBeTrue()
        ->and($thread->messages()->where('from_admin', true)->sole()->body)->toBe('Danke, kommt!')
        ->and(FeedbackThreadResource::getNavigationBadge())->toBeNull();

    Notification::assertSentTo($this->member, FeedbackAnsweredNotification::class,
        fn ($n, $c, $notifiable) => $n->toMail($notifiable)->actionUrl === route('feedback.show', $thread));
});

test('member reads the conversation, sees the unread dot and can reply', function () {
    $thread = sendFeedback();
    app(FeedbackService::class)->reply($thread, $this->admin, 'Danke, kommt!', fromAdmin: true);
    app(FeedbackService::class)->setStatus($thread, FeedbackThread::STATUS_CLOSED);

    $this->actingAs($this->member)->get(route('profile'))
        ->assertSee('Neue Antwort')
        ->assertSee('Neue Antwort auf dein Feedback', false);

    Livewire::actingAs($this->member)
        ->test(FeedbackConversation::class, ['thread' => $thread->fresh()])
        ->assertSee('Danke, kommt!')
        ->set('body', 'Super, danke')
        ->call('reply')
        ->assertHasNoErrors();

    $thread->refresh();
    expect($thread->unread_for_user)->toBeFalse()
        ->and($thread->unread_for_admin)->toBeTrue()
        ->and($thread->isClosed())->toBeFalse();
    Notification::assertSentTo($this->admin, FeedbackReceivedNotification::class, fn ($n, $c, $notifiable) => str_starts_with($n->toMail($notifiable)->subject, 'Antwort'));
});

test('members cannot open other people\'s feedback or the admin list', function () {
    $thread = sendFeedback();
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('feedback.show', $thread))->assertForbidden();
    $this->actingAs($this->member)->get(FeedbackThreadResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->member)->get(route('feedback.show', $thread))->assertOk()->assertSee('Idee');
});
