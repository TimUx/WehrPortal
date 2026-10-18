<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Plain multipart upload for the explorer (one file per request): the browser shows real progress
 * and can abort, several files run one after another, and the size limit is the app setting
 * instead of Livewire's temporary-upload limit. All rights, quota and type checks stay in FileService.
 */
class UploadController
{
    public function __invoke(Request $request, FileService $files): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.FileService::maxUploadKb()],
            'space' => ['nullable', 'integer', 'min:1'],
            'parent' => ['nullable', 'integer', 'min:1'],
            'node' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:0'],
        ], [], ['file' => 'Datei']);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        if (! empty($data['node'])) {
            $node = Node::query()->findOrFail($data['node']);
            $version = $files->addVersion($user, $node, $file, isset($data['expected_version']) ? (int) $data['expected_version'] : null);

            return response()->json(['id' => $node->id, 'name' => $node->name, 'version' => $version->number]);
        }

        $parent = ! empty($data['parent']) ? Node::query()->findOrFail($data['parent']) : null;
        $space = Space::query()->findOrFail($parent?->space_id ?? ($data['space'] ?? 0));
        $node = $files->upload($user, $space, $parent, $file);

        return response()->json(['id' => $node->id, 'name' => $node->name, 'version' => 1], 201);
    }
}
