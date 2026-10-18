<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Comment;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Notifications\FileActivityNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Milestone 6: favourites, recently used, comments and subscriptions. Every list is filtered with
 * the full ACL when it is read, so an entry that is no longer readable simply disappears.
 */
class CollaborationService
{
    public const RECENT_KEEP = 30;

    /** Minutes in which one subscriber gets at most one mail per entry */
    public const NOTIFY_QUIET_MINUTES = 10;

    public function __construct(private readonly FileAccess $access) {}

    public function isFavorite(User $user, Node $node): bool
    {
        return DB::table('x_files_favorites')->where('user_id', $user->id)->where('node_id', $node->id)->exists();
    }

    /** @return bool the new state */
    public function toggleFavorite(User $user, Node $node): bool
    {
        $user = $this->access->member($user);
        $this->access->authorize($user, $node, 'read');
        if ($this->isFavorite($user, $node)) {
            DB::table('x_files_favorites')->where('user_id', $user->id)->where('node_id', $node->id)->delete();

            return false;
        }
        DB::table('x_files_favorites')->insertOrIgnore(['user_id' => $user->id, 'node_id' => $node->id, 'created_at' => now()]);

        return true;
    }

    /** @return Collection<int, Node> */
    public function favorites(User $user, int $limit = 100): Collection
    {
        $user = $this->access->member($user);
        $ids = DB::table('x_files_favorites')->where('user_id', $user->id)->orderByDesc('created_at')->limit($limit)->pluck('node_id');

        return $this->readable($user, $ids->all());
    }

    public function touchRecent(User $user, Node $node): void
    {
        try {
            DB::table('x_files_recent')->upsert([['user_id' => $user->id, 'node_id' => $node->id, 'used_at' => now()]], ['user_id', 'node_id'], ['used_at']);
            $old = DB::table('x_files_recent')->where('user_id', $user->id)->orderByDesc('used_at')->skip(self::RECENT_KEEP)->take(1000)->pluck('node_id');
            if ($old->isNotEmpty()) {
                DB::table('x_files_recent')->where('user_id', $user->id)->whereIn('node_id', $old)->delete();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, Node> */
    public function recent(User $user, int $limit = 10): Collection
    {
        $user = $this->access->member($user);
        $ids = DB::table('x_files_recent')->where('user_id', $user->id)->orderByDesc('used_at')->limit(self::RECENT_KEEP)->pluck('node_id');

        return $this->readable($user, $ids->all())->take($limit)->values();
    }

    public function isSubscribed(User $user, Node $node): bool
    {
        return DB::table('x_files_subscriptions')->where('user_id', $user->id)->where('node_id', $node->id)->exists();
    }

    /** @return bool the new state */
    public function toggleSubscription(User $user, Node $node): bool
    {
        $user = $this->access->member($user);
        $this->access->authorize($user, $node, 'read');
        if ($this->isSubscribed($user, $node)) {
            DB::table('x_files_subscriptions')->where('user_id', $user->id)->where('node_id', $node->id)->delete();

            return false;
        }
        DB::table('x_files_subscriptions')->insertOrIgnore(['user_id' => $user->id, 'node_id' => $node->id, 'created_at' => now()]);

        return true;
    }

    /** @return Collection<int, Comment> */
    public function comments(User $user, Node $node): Collection
    {
        $this->access->authorize($user, $node, 'read');

        return Comment::where('node_id', $node->id)->with('author')->orderBy('created_at')->orderBy('id')->get();
    }

    /** Anyone who can read the entry may comment */
    public function comment(User $user, Node $node, string $body): Comment
    {
        $user = $this->access->member($user);
        $this->access->authorize($user, $node, 'read');
        if ($node->trashed()) {
            throw new AuthorizationException;
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000 || ! mb_check_encoding($body, 'UTF-8')) {
            throw ValidationException::withMessages(['commentBody' => 'Der Kommentar muss 1 bis 2000 Zeichen lang sein.']);
        }
        $comment = Comment::create(['node_id' => $node->id, 'user_id' => $user->id, 'body' => $body]);
        $this->audit($user->id, 'comment.created', $node, ['comment_id' => $comment->id]);
        $this->notifySubscribers($user, $node, 'comment');

        return $comment;
    }

    /** The author, a manager of the entry or an admin */
    public function deleteComment(User $user, Comment $comment): void
    {
        $user = $this->access->member($user);
        $node = Node::findOrFail($comment->node_id);
        if ($comment->user_id !== $user->id && ! $this->access->can($user, $node, 'manage')) {
            throw new AuthorizationException;
        }
        $comment->delete();
        $this->audit($user->id, 'comment.deleted', $node, ['comment_id' => $comment->id]);
    }

    /**
     * Mails everyone who subscribed to the entry or a folder above it, still may read the entry
     * and is not the actor. Sent after the commit, at most once per subscriber and entry within
     * NOTIFY_QUIET_MINUTES; failures are reported, never thrown.
     *
     * @param  'created'|'version'|'comment'  $event
     */
    public function notifySubscribers(User $actor, Node $node, string $event): void
    {
        DB::afterCommit(function () use ($actor, $node, $event) {
            try {
                $chain = collect(DB::select(<<<'SQL'
                    WITH RECURSIVE chain AS (
                        SELECT id, parent_id, 0 AS depth FROM x_files_nodes WHERE id = ?
                        UNION ALL
                        SELECT n.id, n.parent_id, c.depth + 1 FROM x_files_nodes n JOIN chain c ON n.id = c.parent_id WHERE c.depth < 10000
                    ) SELECT id FROM chain
                    SQL, [$node->id]))->pluck('id');
                $userIds = DB::table('x_files_subscriptions')->whereIn('node_id', $chain)->where('user_id', '!=', $actor->id)->distinct()->pluck('user_id');
                $fresh = Node::find($node->id);
                if ($userIds->isEmpty() || ! $fresh) {
                    return;
                }
                foreach (User::whereIn('id', $userIds)->where('is_active', true)->get() as $recipient) {
                    if (! $this->access->can($recipient, $fresh, 'read')) {
                        continue;
                    }
                    if (! Cache::add('dateien.notify.'.$recipient->id.'.'.$fresh->id, true, now()->addMinutes(self::NOTIFY_QUIET_MINUTES))) {
                        continue;
                    }
                    try {
                        $recipient->notify(new FileActivityNotification($fresh, $actor->name, $event));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @param  list<int|string>  $ids  in the wanted order
     * @return Collection<int, Node>
     */
    private function readable(User $user, array $ids): Collection
    {
        $nodes = Node::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $nodes->get((int) $id))->filter()
            ->filter(fn (Node $node) => $this->access->can($user, $node, 'read'))->values();
    }

    private function audit(int $actor, string $action, Node $node, array $metadata): void
    {
        AuditEvent::create(['actor_id' => $actor, 'action' => $action, 'space_id' => $node->space_id, 'node_id' => $node->id, 'metadata' => $metadata]);
    }
}
