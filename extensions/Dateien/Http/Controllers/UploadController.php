<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
            'file' => ['nullable', 'file', 'max:'.FileService::maxUploadKb()],
            'space' => ['nullable', 'integer', 'min:1'],
            'parent' => ['nullable', 'integer', 'min:1'],
            'node' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:0'],
            'path' => ['nullable', 'string', 'max:4096'],
            'directories' => ['nullable', 'array', 'max:500'],
            'directories.*' => ['required', 'string', 'max:4096'],
        ], [], ['file' => 'Datei']);

        if (isset($data['directories'])) {
            if (isset($data['file']) || ! empty($data['node'])) {
                throw ValidationException::withMessages(['directories' => 'Ordner können nur in einer Ablage oder einem Ordner angelegt werden.']);
            }
            $parent = ! empty($data['parent']) ? Node::query()->findOrFail($data['parent']) : null;
            $space = Space::query()->findOrFail($parent ? $parent->space_id : ($data['space'] ?? 0));
            $created = $files->createFolderPaths($user, $space, $parent, $data['directories']);

            return response()->json(['created' => $created], 201);
        }

        if (! isset($data['file'])) {
            throw ValidationException::withMessages(['file' => 'Bitte eine Datei oder Ordner auswählen.']);
        }
        /** @var UploadedFile $file */
        $file = $data['file'];

        if (! empty($data['node'])) {
            if (! empty($data['path'])) {
                throw ValidationException::withMessages(['path' => 'Eine neue Dateiversion kann keinen Ordnerpfad enthalten.']);
            }
            $node = Node::query()->findOrFail($data['node']);
            $version = $files->addVersion($user, $node, $file, isset($data['expected_version']) ? (int) $data['expected_version'] : null);

            return response()->json(['id' => $node->id, 'name' => $node->name, 'version' => $version->number]);
        }
        $parent = ! empty($data['parent']) ? Node::query()->findOrFail($data['parent']) : null;
        $space = Space::query()->findOrFail($parent ? $parent->space_id : ($data['space'] ?? 0));
        $node = $files->upload($user, $space, $parent, $file, $data['path'] ?? '');

        return response()->json(['id' => $node->id, 'name' => $node->name, 'version' => 1], 201);
    }
}
