<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Services\ZipService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FolderZipController
{
    public function __invoke(Request $request, int $node, ZipService $zip): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        try {
            return $zip->download($user, Node::query()->findOrFail($node));
        } catch (ValidationException $e) {
            return redirect()->route('dateien.index', ['node' => $node])->withErrors($e->errors());
        }
    }
}
