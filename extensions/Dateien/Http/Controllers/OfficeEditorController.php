<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Services\CollaborationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page that frames the office server. It sends its own, enforced Content-Security-Policy:
 * scripts only from the app (with nonce) and the office host, frames and form posts only to the
 * office host. The page also asks for no referrer (meta tag; the global SecurityHeaders sets the header).
 */
class OfficeEditorController
{
    public function __invoke(Request $request, Node $node, OfficeService $office, OfficeSettings $settings): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($settings->enabled(), 404);
        [$session, $token] = $office->open($user, $node, $request->boolean('bearbeiten'));
        app(CollaborationService::class)->touchRecent($user, $node);
        try {
            $editor = $office->connector()->editor($session, $token, $user, $node, $session->version()->firstOrFail());
        } catch (RuntimeException $e) {
            $session->forceFill(['revoked_at' => now()])->save();
            abort(502, $e->getMessage());
        }
        $nonce = Str::random(32);
        $origin = (string) $settings->serverOrigin();
        $csp = "default-src 'none'; script-src 'nonce-{$nonce}' {$origin}; style-src 'unsafe-inline'; img-src 'self' data:; "
            ."connect-src 'self' {$origin}; frame-src {$origin}; child-src {$origin}; form-action {$origin}; "
            ."base-uri 'none'; object-src 'none'; frame-ancestors 'self'";

        return response()->view('dateien::office.editor', [
            'node' => $node, 'editor' => $editor, 'nonce' => $nonce, 'editing' => $session->level === 'edit',
            'back' => route('dateien.index', ['node' => $node->id]),
        ])->withHeaders([
            'Content-Security-Policy' => $csp,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
