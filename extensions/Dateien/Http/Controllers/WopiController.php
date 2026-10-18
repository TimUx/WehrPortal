<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\WopiProof;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WOPI host endpoints for Collabora (milestone 3: CheckFileInfo and GetFile, read only). Called by
 * the office server only: access_token per request, proof signature of the configured server.
 */
class WopiController
{
    public function __construct(private readonly OfficeService $office, private readonly WopiProof $proof) {}

    public function checkFileInfo(Request $request, int $node): JsonResponse
    {
        [$session, $user, $file, $version] = $this->authorize($request, $node);

        return response()->json([
            'BaseFileName' => $file->name,
            'OwnerId' => (string) $version->created_by,
            'Size' => $version->size,
            'UserId' => (string) $user->id,
            'UserFriendlyName' => $user->name,
            'Version' => (string) $version->id,
            'LastModifiedTime' => $version->created_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'ReadOnly' => true,
            'UserCanWrite' => false,
            'UserCanRename' => false,
            'UserCanNotWriteRelative' => true,
            'SupportsLocks' => false,
            'SupportsUpdate' => false,
            'SupportsRename' => false,
            'DisableExport' => false,
            'DisablePrint' => false,
            'HidePrintOption' => false,
            'HideSaveOption' => true,
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

    /** @return array{0: OfficeSession, 1: User, 2: Node, 3: Version} */
    private function authorize(Request $request, int $node): array
    {
        $token = (string) $request->query('access_token', '');
        $this->office->resolve($token, 'collabora', $node);
        abort_unless($this->proof->valid($request, $token), 401);

        return $this->office->resolve($token, 'collabora', $node);
    }
}
