<?php

namespace Extensions\Dateien\Office;

use App\Models\User;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Office sessions (milestone 3: read only). A session binds one random token to one Kamerad, one
 * file and one version. Every call of the office server is checked again: token, provider, file,
 * expiry and revocation, and the Kamerad's current rights through FileAccess.
 */
class OfficeService
{
    public function __construct(
        private readonly OfficeSettings $settings,
        private readonly FileAccess $access,
        private readonly FileService $files,
    ) {}

    public function connector(): OfficeConnector
    {
        return match ($this->settings->provider()) {
            'onlyoffice' => app(OnlyOfficeConnector::class),
            'collabora' => app(CollaboraConnector::class),
            default => throw new HttpException(404),
        };
    }

    public function canOpen(User $user, Node $node): bool
    {
        if ($node->kind !== 'file' || $node->trashed() || ! $this->settings->enabled()) {
            return false;
        }
        $extension = strtolower(pathinfo($node->name, PATHINFO_EXTENSION));

        return $this->settings->supports($extension) && $this->access->can($user, $node, 'read');
    }

    /** @return array{0: OfficeSession, 1: string} the session and the raw token (shown only to the office server) */
    public function open(User $user, Node $node): array
    {
        $user = $this->access->member($user);
        if (! $this->settings->enabled()) {
            throw new HttpException(404);
        }
        $this->access->authorize($user, $node, 'read');
        $version = $this->files->versions($user, $node)->first();
        if ($node->kind !== 'file' || $node->trashed() || ! $version instanceof Version || ! $this->settings->supports($version->extension)) {
            throw new HttpException(404);
        }
        $token = Str::random(64);
        $session = OfficeSession::create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'node_id' => $node->id,
            'version_id' => $version->id,
            'provider' => (string) $this->settings->provider(),
            'level' => 'read',
            'expires_at' => now()->addMinutes(max(5, (int) config('dateien.office.session_minutes', 480))),
        ]);
        $this->audit($user->id, 'office.opened', $node, ['session_id' => $session->id, 'version_id' => $version->id, 'provider' => $session->provider]);

        return [$session, $token];
    }

    /**
     * Resolves a token presented by the office server. 401 for unknown, expired or revoked tokens,
     * 403 when the Kamerad lost access, 404 when the module or the provider is off or the token
     * belongs to another file.
     *
     * @return array{0: OfficeSession, 1: User, 2: Node, 3: Version}
     */
    public function resolve(string $token, string $provider, ?int $nodeId = null): array
    {
        if (! $this->moduleActive() || $this->settings->provider() !== $provider || ! $this->settings->enabled()) {
            throw new HttpException(404);
        }
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            throw new HttpException(401);
        }
        $session = OfficeSession::where('token_hash', hash('sha256', $token))->first();
        if (! $session || $session->provider !== $provider || ! $session->isUsable()) {
            throw new HttpException(401);
        }
        if ($nodeId !== null && $session->node_id !== $nodeId) {
            throw new HttpException(401);
        }
        $node = Node::withTrashed()->find($session->node_id);
        $version = Version::where('node_id', $session->node_id)->find($session->version_id);
        $user = User::withoutGlobalScopes()->find($session->user_id);
        if (! $node || ! $version || ! $user || $node->trashed()) {
            throw new HttpException(404);
        }
        try {
            $user = $this->access->member($user);
            $this->access->authorize($user, $node, $session->level);
        } catch (AuthorizationException) {
            throw new HttpException(403);
        }
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return [$session, $user, $node, $version];
    }

    public function end(OfficeSession $session, string $reason): void
    {
        if ($session->ended_at !== null) {
            return;
        }
        $session->forceFill(['ended_at' => now()])->save();
        $node = Node::withTrashed()->find($session->node_id);
        if ($node) {
            $this->audit($session->user_id, 'office.closed', $node, ['session_id' => $session->id, 'reason' => $reason]);
        }
    }

    /** Ends every open session at once (admin button, e.g. after changing the office server) */
    public function revokeAll(User $admin): int
    {
        if (! $this->access->member($admin)->is_admin) {
            throw new AuthorizationException;
        }
        $count = OfficeSession::whereNull('revoked_at')->where('expires_at', '>', now())->update(['revoked_at' => now()]);
        AuditEvent::create(['actor_id' => $admin->id, 'action' => 'office.revoked_all', 'metadata' => ['sessions' => $count]]);

        return $count;
    }

    /** Removes sessions a day after they expired or were revoked; records the end of those never closed */
    public function prune(): int
    {
        $count = 0;
        OfficeSession::where(fn ($q) => $q->where('expires_at', '<', now()->subDay())->orWhere('revoked_at', '<', now()->subDay()))
            ->orderBy('id')->each(function (OfficeSession $session) use (&$count) {
                $this->end($session, $session->revoked_at ? 'revoked' : 'expired');
                $session->delete();
                $count++;
            });

        return $count;
    }

    private function moduleActive(): bool
    {
        return DB::table('modules')->where('key', 'dateien')->where('is_active', true)->exists();
    }

    private function audit(?int $actor, string $action, Node $node, array $metadata): void
    {
        AuditEvent::create(['actor_id' => $actor, 'action' => $action, 'space_id' => $node->space_id, 'node_id' => $node->id, 'metadata' => $metadata]);
    }
}
