<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Services\PreviewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreviewController
{
    public function __invoke(Request $request, int $node, PreviewService $previews): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $preview = $previews->for($user, Node::query()->findOrFail($node)) ?? abort(404);

        return response($preview['data'], 200, [
            'Content-Type' => $preview['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
