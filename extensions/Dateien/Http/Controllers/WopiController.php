<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\WopiLocks;
use Extensions\Dateien\Office\WopiProof;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * WOPI host endpoints for Collabora: CheckFileInfo, GetFile, PutFile and the lock operations.
 * Called by the office server only: access_token per request, proof signature of the configured
 * server. Writing needs an edit session (and edit rights now) and the current lock.
 */
class WopiController
{
    public function __construct(
        private readonly OfficeService $office,
        private readonly WopiProof $proof,
        private readonly WopiLocks $locks,
    ) {}

    public function checkFileInfo(Request $request, int $node): JsonResponse
    {
        [$session, $user, $file, $version] = $this->authorize($request, $node);
        $edit = $session->level === 'edit';

        return response()->json([
            'BaseFileName' => $file->name,
            'OwnerId' => (string) $version->created_by,
            'Size' => $version->size,
            'UserId' => (string) $user->id,
            'UserFriendlyName' => $user->name,
            'Version' => (string) $version->id,
            'LastModifiedTime' => self::modified($version),
            'ReadOnly' => ! $edit,
            'UserCanWrite' => $edit,
            'UserCanRename' => false,
            'UserCanNotWriteRelative' => true,
            'SupportsLocks' => $edit,
            'SupportsGetLock' => $edit,
            'SupportsUpdate' => $edit,
            'SupportsRename' => false,
            'DisableExport' => false,
            'DisablePrint' => false,
            'HidePrintOption' => false,
            'HideSaveOption' => ! $edit,
            'PostMessageOrigin' => rtrim((string) config('app.url'), '/'),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function getFile(Request $request, int $node, FileService $files): Response
    {
        [, $user, $file, $version] = $this->authorize($request, $node);
        $response = $files->download($user, $file, $version, $request);
        $response->headers->set('X-WOPI-ItemVersion', (string) $version->id);

        return $response;
    }

    /** POST on the file: LOCK, GET_LOCK, REFRESH_LOCK, UNLOCK; PUT_RELATIVE and RENAME_FILE are not offered */
    public function fileOperation(Request $request, int $node): Response
    {
        $override = strtoupper((string) $request->header('X-WOPI-Override', ''));
        if (in_array($override, ['PUT_RELATIVE', 'RENAME_FILE', 'DELETE'], true)) {
            return response('', 501);
        }
        [$session] = $this->authorize($request, $node, true);
        $lock = (string) $request->header('X-WOPI-Lock', '');
        if ($override === 'GET_LOCK') {
            return response('', 200, ['X-WOPI-Lock' => (string) $this->locks->current($node)]);
        }
        if (! in_array($override, ['LOCK', 'REFRESH_LOCK', 'UNLOCK'], true)) {
            return response('', 501);
        }
        if (! WopiLocks::valid($lock)) {
            return response('', 400);
        }
        $old = $request->headers->has('X-WOPI-OldLock') ? (string) $request->header('X-WOPI-OldLock') : null;
        [$ok, $current] = match ($override) {
            'LOCK' => $this->locks->lock($node, $session->id, $lock, $old),
            'REFRESH_LOCK' => $this->locks->refresh($node, $lock),
            'UNLOCK' => $this->locks->unlock($node, $lock),
        };
        if ($override === 'UNLOCK' && $ok) {
            $this->office->end($session, 'unlocked');
        }

        return $ok ? response('', 200) : $this->conflict($current, 'Datei ist von einer anderen Sitzung gesperrt.');
    }

    /** PutFile: only with the current lock (or unlocked and empty), conflicts end in 409 */
    public function putFile(Request $request, int $node): Response
    {
        if (strtoupper((string) $request->header('X-WOPI-Override', '')) !== 'PUT') {
            return response('', 501);
        }
        [$session, $user, $file, $version] = $this->authorize($request, $node, true);
        $lock = (string) $request->header('X-WOPI-Lock', '');
        $current = $this->locks->current($node);
        if ($current === null ? $version->size > 0 : $current !== $lock) {
            return $this->conflict((string) $current, 'Die Datei ist nicht für diese Sitzung gesperrt.');
        }
        // Collabora sends the modification time it loaded; a newer version means a conflict
        $loaded = $request->header('X-COOL-WOPI-Timestamp', $request->header('X-LOOL-WOPI-Timestamp'));
        if (is_string($loaded) && $loaded !== '' && $loaded !== self::modified($version)) {
            return response()->json(['COOLStatusCode' => 1010, 'LOOLStatusCode' => 1010], 409, ['X-WOPI-Lock' => (string) $current]);
        }
        $max = FileService::maxUploadKb() * 1024;
        if ((int) $request->header('Content-Length', '0') > $max) {
            $this->office->failed($session, $file, 'Die Datei überschreitet das Upload-Limit.', ['provider' => 'collabora']);

            return response('', 413);
        }
        $autosave = strtolower((string) $request->header('X-COOL-WOPI-IsAutosave', $request->header('X-LOOL-WOPI-IsAutosave', 'false'))) === 'true';
        try {
            $path = OfficeService::toTemp($request->getContent(true), $max);
        } catch (Throwable $e) {
            $this->office->failed($session, $file, $e->getMessage(), ['provider' => 'collabora']);

            return response('', 413);
        }
        try {
            $saved = $this->office->save($session, $user, $file, $path, $autosave, ['provider' => 'collabora']);
        } catch (Throwable) {
            return response()->json(['message' => 'Speichern abgelehnt (Rechte, Speicherlimit oder Dateiinhalt).'], 500);
        }

        return response()->json(['LastModifiedTime' => self::modified($saved)], 200, ['X-WOPI-ItemVersion' => (string) $saved->id]);
    }

    private function conflict(string $current, string $reason): Response
    {
        return response('', 409, ['X-WOPI-Lock' => $current, 'X-WOPI-LockFailureReason' => $reason]);
    }

    private static function modified(Version $version): ?string
    {
        // Whole seconds: the database keeps no fractions for created_at, the timestamp must round-trip
        return $version->created_at?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{0: OfficeSession, 1: User, 2: Node, 3: Version} */
    private function authorize(Request $request, int $node, bool $write = false): array
    {
        $token = (string) $request->query('access_token', '');
        $this->office->resolve($token, 'collabora', $node);
        abort_unless($this->proof->valid($request, $token), 401);
        $resolved = $this->office->resolve($token, 'collabora', $node);
        // A read session never writes or locks, even if the Kamerad could edit by now
        abort_if($write && $resolved[0]->level !== 'edit', 401);

        return $resolved;
    }
}
