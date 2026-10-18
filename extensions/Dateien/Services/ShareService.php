<?php

namespace Extensions\Dateien\Services;

use App\Models\Setting;
use App\Models\User;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Share;
use Extensions\Dateien\Models\Space;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public links (milestone 5). Off unless the admin switch and the space's flag are both on.
 * Creating needs "manage" on the entry. Every request checks again: switch, module, space flag,
 * expiry, revocation, download limit, the entry and all its ancestors not in the trash, and the
 * creator still holding "manage". Anything else is a plain 404, never a hint why.
 */
class ShareService
{
    private const PREFIX = 'dateien.shares.';

    public function __construct(private readonly FileAccess $access, private readonly FileService $files) {}

    public static function enabled(): bool
    {
        return (string) Setting::get(self::PREFIX.'enabled', '0') === '1';
    }

    public static function maxDays(): int
    {
        return max(1, min(365, (int) Setting::get(self::PREFIX.'max_days', config('dateien.shares.max_days', 30))));
    }

    public function saveSettings(User $admin, bool $enabled, int $maxDays): void
    {
        if (! $this->access->member($admin)->is_admin) {
            throw new AuthorizationException;
        }
        if ($maxDays < 1 || $maxDays > 365) {
            throw ValidationException::withMessages(['shareMaxDays' => 'Die Höchstdauer muss zwischen 1 und 365 Tagen liegen.']);
        }
        Setting::set(self::PREFIX.'enabled', $enabled ? '1' : '0');
        Setting::set(self::PREFIX.'max_days', (string) $maxDays);
        AuditEvent::create(['actor_id' => $admin->id, 'action' => 'share.settings_changed', 'metadata' => ['enabled' => $enabled, 'max_days' => $maxDays]]);
    }

    public function allowSpace(User $admin, Space $space, bool $allowed): void
    {
        if (! $this->access->member($admin)->is_admin) {
            throw new AuthorizationException;
        }
        $space->forceFill(['public_shares' => $allowed])->save();
        AuditEvent::create(['actor_id' => $admin->id, 'action' => 'share.space_changed', 'space_id' => $space->id, 'metadata' => ['allowed' => $allowed]]);
    }

    public function canCreate(User $user, Node $node): bool
    {
        if (! self::enabled() || $node->trashed()) {
            return false;
        }
        $space = Space::find($node->space_id);

        return $space !== null && $space->public_shares && $this->access->can($user, $node, 'manage');
    }

    /** @return array{0: Share, 1: string} the share and the raw token (shown once) */
    public function create(User $user, Node $node, int $days, ?string $password = null, ?int $maxDownloads = null, ?string $label = null): array
    {
        $user = $this->access->member($user);
        if (! $this->canCreate($user, $node)) {
            throw new AuthorizationException('Öffentliche Links sind hier nicht erlaubt.');
        }
        if ($days < 1 || $days > self::maxDays()) {
            throw ValidationException::withMessages(['shareDays' => 'Die Gültigkeit muss zwischen 1 und '.self::maxDays().' Tagen liegen.']);
        }
        if ($password !== null && $password !== '' && mb_strlen($password) < 8) {
            throw ValidationException::withMessages(['sharePassword' => 'Das Passwort muss mindestens 8 Zeichen lang sein.']);
        }
        if ($maxDownloads !== null && ($maxDownloads < 1 || $maxDownloads > 10000)) {
            throw ValidationException::withMessages(['shareMaxDownloads' => 'Das Download-Limit muss zwischen 1 und 10000 liegen.']);
        }
        $token = Str::random(48);
        $share = Share::create([
            'token_hash' => hash('sha256', $token),
            'node_id' => $node->id,
            'created_by' => $user->id,
            'label' => $label !== null && trim($label) !== '' ? Str::limit(trim($label), 150, '') : null,
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'max_downloads' => $maxDownloads,
            'expires_at' => now()->addDays($days),
        ]);
        $this->audit($user->id, 'share.created', $node, [
            'share_id' => $share->id, 'expires_at' => $share->expires_at->toIso8601String(),
            'password' => $share->password_hash !== null, 'max_downloads' => $maxDownloads,
        ]);

        return [$share, $token];
    }

    /** Creator with "manage" on the entry, anyone with "manage" there, or an admin */
    public function revoke(User $user, Share $share): void
    {
        $user = $this->access->member($user);
        $node = Node::withTrashed()->find($share->node_id);
        if (! $user->is_admin && (! $node || ! $this->access->can($user, $node, 'manage'))) {
            throw new AuthorizationException;
        }
        if ($share->revoked_at !== null) {
            return;
        }
        $share->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id])->save();
        if ($node) {
            $this->audit($user->id, 'share.revoked', $node, ['share_id' => $share->id]);
        }
    }

    /** @return Collection<int, Share> active links of an entry, for its managers */
    public function forNode(User $user, Node $node): Collection
    {
        if (! $this->access->can($user, $node, 'manage')) {
            return collect();
        }

        return Share::where('node_id', $node->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('expires_at')->get()
            ->filter(fn (Share $share) => $share->isActive())->values();
    }

    /**
     * Resolves a raw token to its active share and entry, or 404.
     *
     * @return array{0: Share, 1: Node}
     */
    public function resolve(string $token): array
    {
        if (! self::enabled() || ! $this->moduleActive() || strlen($token) !== 48 || ! ctype_alnum($token)) {
            throw new NotFoundHttpException;
        }
        $share = Share::where('token_hash', hash('sha256', $token))->first();
        if (! $share || ! $share->isActive()) {
            throw new NotFoundHttpException;
        }
        $node = Node::find($share->node_id);
        $space = $node ? Space::find($node->space_id) : null;
        if (! $node || ! $space || ! $space->public_shares || $this->path($share, $node) === null) {
            throw new NotFoundHttpException;
        }
        $creator = User::withoutGlobalScopes()->find($share->created_by);
        try {
            if (! $creator || ! $this->access->can($this->access->member($creator), $node, 'manage')) {
                throw new NotFoundHttpException;
            }
        } catch (AuthorizationException) {
            throw new NotFoundHttpException;
        }

        return [$share, $node];
    }

    /**
     * The entries from the shared root down to $target, or null when $target is not inside the
     * shared subtree or anything on the way (up to the space root) is in the trash.
     *
     * @return list<Node>|null
     */
    public function path(Share $share, Node $target): ?array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT id, parent_id, space_id, deleted_at, 0 AS depth FROM x_files_nodes WHERE id = ?
                UNION ALL
                SELECT n.id, n.parent_id, n.space_id, n.deleted_at, c.depth + 1
                FROM x_files_nodes n JOIN chain c ON n.id = c.parent_id WHERE c.depth < 10000
            ) SELECT id, parent_id, space_id, deleted_at FROM chain ORDER BY depth
            SQL, [$target->id]);
        $ids = [];
        $inside = false;
        foreach ($rows as $row) {
            if ($row->deleted_at !== null || (int) $row->space_id !== (int) $target->space_id) {
                return null;
            }
            if (! $inside) {
                $ids[] = (int) $row->id;
            }
            if ((int) $row->id === $share->node_id) {
                $inside = true;
            }
        }
        if (! $inside || $rows === [] || end($rows)->parent_id !== null) {
            return null;
        }
        $nodes = Node::whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_map(fn (int $id) => $nodes[$id], array_reverse($ids)));
    }

    /** @return Collection<int, Node> */
    public function children(Share $share, Node $folder): Collection
    {
        if ($folder->kind !== 'folder' || $this->path($share, $folder) === null) {
            throw new NotFoundHttpException;
        }

        return Node::where('parent_id', $folder->id)->where('space_id', $folder->space_id)
            ->orderByRaw("kind = 'file'")->orderByRaw('lower(name)')->get();
    }

    /** Streams a file of the shared subtree; a full download counts against the limit atomically */
    public function download(Share $share, Node $file, Request $request): Response
    {
        if ($file->kind !== 'file' || $this->path($share, $file) === null) {
            throw new NotFoundHttpException;
        }
        // Partial requests are not counted, so a public link serves whole files only
        $request->headers->remove('Range');
        $request->headers->remove('If-Range');

        $response = $this->files->stream($file, null, $request, function (Node $node, $version) use ($share, $request) {
            $counted = Share::whereKey($share->id)->whereNull('revoked_at')->where('expires_at', '>', now())
                ->where(fn ($q) => $q->whereNull('max_downloads')->orWhereColumn('download_count', '<', 'max_downloads'))
                ->update(['download_count' => DB::raw('download_count + 1'), 'last_access_at' => now()]);
            if ($counted === 0) {
                throw new NotFoundHttpException;
            }
            $this->audit(null, 'share.downloaded', $node, ['share_id' => $share->id, 'version_id' => $version->id, 'ip' => self::shortIp($request->ip())]);
        });
        $response->headers->set('Accept-Ranges', 'none');

        return $response;
    }

    public function checkPassword(Share $share, string $password, Request $request): bool
    {
        $ok = $share->password_hash !== null && Hash::check($password, $share->password_hash);
        $node = Node::find($share->node_id);
        if ($node) {
            $this->audit(null, $ok ? 'share.unlocked' : 'share.password_failed', $node, ['share_id' => $share->id, 'ip' => self::shortIp($request->ip())]);
        }

        return $ok;
    }

    public function touch(Share $share): void
    {
        if ($share->last_access_at === null || $share->last_access_at->lt(now()->subMinutes(5))) {
            Share::whereKey($share->id)->update(['last_access_at' => now()]);
        }
    }

    /** Removes links 30 days after they expired or were revoked */
    public function prune(): int
    {
        return Share::where(fn ($q) => $q->where('expires_at', '<', now()->subDays(30))->orWhere('revoked_at', '<', now()->subDays(30)))->delete();
    }

    /** IPv4 to /24, IPv6 to /48, so the log shows the network, not the person */
    public static function shortIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
    }

    private function moduleActive(): bool
    {
        return DB::table('modules')->where('key', 'dateien')->where('is_active', true)->exists();
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(?int $actor, string $action, Node $node, array $metadata): void
    {
        AuditEvent::create(['actor_id' => $actor, 'action' => $action, 'space_id' => $node->space_id, 'node_id' => $node->id, 'metadata' => $metadata]);
    }
}
