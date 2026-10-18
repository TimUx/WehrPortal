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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Office sessions. A session binds one random token to one Kamerad, one file, the version it was
 * opened at and a level (read or edit). Every call of the office server is checked again: token,
 * provider, file, expiry and revocation, and the Kamerad's current rights through FileAccess.
 * Saves from the office server become versions through FileService (milestone 4).
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

    public function canOpen(User $user, Node $node, bool $edit = false): bool
    {
        if ($node->kind !== 'file' || $node->trashed() || ! $this->settings->enabled()) {
            return false;
        }
        $extension = strtolower(pathinfo($node->name, PATHINFO_EXTENSION));
        $supported = $edit ? $this->settings->editable($extension) : $this->settings->supports($extension);

        return $supported && $this->access->can($user, $node, $edit ? 'edit' : 'read');
    }

    /** @return array{0: OfficeSession, 1: string} the session and the raw token (shown only to the office server) */
    public function open(User $user, Node $node, bool $edit = false): array
    {
        $user = $this->access->member($user);
        if (! $this->settings->enabled()) {
            throw new HttpException(404);
        }
        $this->access->authorize($user, $node, $edit ? 'edit' : 'read');
        $version = $this->files->versions($user, $node)->first();
        if ($node->kind !== 'file' || $node->trashed() || ! $version instanceof Version
            || ! ($edit ? $this->settings->editable($version->extension) : $this->settings->supports($version->extension))) {
            throw new HttpException(404);
        }
        $token = Str::random(64);
        $session = OfficeSession::create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'node_id' => $node->id,
            'version_id' => $version->id,
            'provider' => (string) $this->settings->provider(),
            'level' => $edit ? 'edit' : 'read',
            'expires_at' => now()->addMinutes(max(5, (int) config('dateien.office.session_minutes', 480))),
        ]);
        $this->audit($user->id, 'office.opened', $node, ['session_id' => $session->id, 'version_id' => $version->id, 'provider' => $session->provider, 'level' => $session->level]);

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
        // Editors always work on the newest version; viewers see the version they opened
        $version = $session->level === 'edit'
            ? Version::where('node_id', $session->node_id)->orderByDesc('number')->first()
            : ($session->version_id ? Version::where('node_id', $session->node_id)->find($session->version_id) : null);
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

    /**
     * Saves a file the office server produced as a new version. Failures (rights, quota, type,
     * size) are written to the audit log as office.save_failed and rethrown, so the office server
     * keeps the document and may retry.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function save(OfficeSession $session, User $user, Node $node, string $path, bool $autosave, array $metadata = []): Version
    {
        try {
            if ($session->level !== 'edit' || ! $this->settings->editing()) {
                throw new HttpException(403);
            }
            // Someone stored a version outside the office since this session started (e.g. after the idle window)
            $conflict = Version::where('node_id', $node->id)->where('id', '>', (int) $session->version_id)->where('source', '!=', 'office')->exists();
            $version = $this->files->addOfficeVersion($user, $node, new UploadedFile($path, $node->name, null, UPLOAD_ERR_OK, true), $session->id, $autosave);
        } catch (Throwable $e) {
            $reason = match (true) {
                $e instanceof ValidationException => collect($e->errors())->flatten()->first(),
                $e instanceof AuthorizationException, $e instanceof HttpException => 'Keine Bearbeitungsrechte mehr.',
                default => 'Speichern fehlgeschlagen.',
            };
            $this->failed($session, $node, (string) $reason, $metadata);
            throw $e;
        } finally {
            @unlink($path);
        }
        $this->audit($user->id, 'office.saved', $node, $metadata + array_filter([
            'session_id' => $session->id, 'version_id' => $version->id, 'number' => $version->number,
            'autosave' => $autosave ?: null, 'conflict' => $conflict ?: null,
        ]));

        return $version;
    }

    /** @param  array<string, mixed>  $metadata */
    public function failed(OfficeSession $session, Node $node, string $reason, array $metadata = []): void
    {
        $this->audit($session->user_id, 'office.save_failed', $node, $metadata + ['session_id' => $session->id, 'reason' => Str::limit($reason, 200)]);
    }

    /**
     * Downloads a file the office server offers (ONLYOFFICE callback). Only from the configured
     * office server (browser or internal address, the browser address is fetched internally), no
     * redirects, at most the upload limit. Returns the path of a temporary file.
     */
    public function fetch(string $url): string
    {
        if (! OfficeSettings::validUrlWithQuery($url)) {
            throw new RuntimeException('Ungültige Download-Adresse.');
        }
        $origin = OfficeSettings::origin($url);
        $internal = $this->settings->internalUrl();
        $allowed = array_filter([$this->settings->serverOrigin(), $internal ? OfficeSettings::origin($internal) : null]);
        if (! in_array($origin, $allowed, true)) {
            throw new RuntimeException('Download nur vom eingestellten Office-Server.');
        }
        if ($internal !== null && $origin === $this->settings->serverOrigin()) {
            $url = OfficeSettings::origin($internal).substr($url, strlen($origin));
        }
        $max = FileService::maxUploadKb() * 1024;
        $response = Http::timeout(max(5, (int) config('dateien.office.save_timeout', 60)))
            ->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Der Office-Server liefert die Datei nicht (HTTP '.$response->status().').');
        }
        if ((int) $response->header('Content-Length') > $max) {
            throw new RuntimeException('Die Datei überschreitet das Upload-Limit.');
        }

        return self::toTemp($response->toPsrResponse()->getBody(), $max);
    }

    /**
     * Copies a stream into a temporary file, at most $max bytes.
     *
     * @param  StreamInterface|resource  $stream
     */
    public static function toTemp(mixed $stream, int $max): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dateien-office-');
        $out = $path === false ? false : fopen($path, 'wb');
        if ($path === false || $out === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        $total = 0;
        try {
            while (is_resource($stream) ? ! feof($stream) : ! $stream->eof()) {
                $chunk = is_resource($stream) ? fread($stream, 65536) : $stream->read(65536);
                if ($chunk === false) {
                    throw new RuntimeException('Die Datei konnte nicht gelesen werden.');
                }
                $total += strlen($chunk);
                if ($total > $max) {
                    throw new RuntimeException('Die Datei überschreitet das Upload-Limit.');
                }
                fwrite($out, $chunk);
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($path);
            throw $e;
        }
        fclose($out);

        return $path;
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

    /**
     * Removes sessions a day after they expired or were revoked and records the end of those never
     * closed; ends edit sessions idle longer than the idle window and drops expired WOPI locks.
     */
    public function prune(): int
    {
        $count = DB::table('x_files_office_locks')->where('expires_at', '<=', now())->delete();
        $idle = now()->subMinutes(max(5, (int) config('dateien.office.edit_idle_minutes', 60)));
        OfficeSession::where('level', 'edit')->whereNull('ended_at')
            ->where(fn ($q) => $q->where('last_seen_at', '<', $idle)->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('created_at', '<', $idle)))
            ->orderBy('id')->each(function (OfficeSession $session) use (&$count) {
                $this->end($session, 'idle');
                $count++;
            });
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
