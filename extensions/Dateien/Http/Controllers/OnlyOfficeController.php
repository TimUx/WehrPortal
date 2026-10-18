<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Office\Jwt;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Called by the ONLYOFFICE Document Server, not by browsers: no session, no CSRF. Every request
 * needs the session token in the path and a JWT signed with the shared secret.
 */
class OnlyOfficeController
{
    public function __construct(private readonly OfficeService $office, private readonly OfficeSettings $settings) {}

    public function file(Request $request, string $token, FileService $files): Response
    {
        [, $user, $node, $version] = $this->office->resolve($token, 'onlyoffice');
        abort_if($this->jwt($request) === null, 401);

        return $files->download($user, $node, $version, $request);
    }

    /**
     * Status 1: editing (keeps the session alive), 2: closed with changes, 6: force save; both
     * save a new version from the offered URL. 3/7: the server could not save (logged). 4: closed
     * without changes. Only data from the verified JWT is used. {"error":1} lets ONLYOFFICE keep
     * the document and report the failure.
     */
    public function callback(Request $request, string $token): JsonResponse
    {
        [$session, $user, $node] = $this->office->resolve($token, 'onlyoffice');
        $payload = $this->jwt($request);
        abort_if($payload === null, 401);
        // Header tokens wrap the body in "payload"; body tokens carry it directly
        $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $status = (int) ($data['status'] ?? 0);
        $key = (string) ($data['key'] ?? '');
        if ($key !== '' && ! str_starts_with($key, $node->id.'-')) {
            abort(401);
        }
        if (in_array($status, [3, 7], true)) {
            $this->office->failed($session, $node, 'Der Office-Server konnte nicht speichern (Status '.$status.').', ['provider' => 'onlyoffice']);
        }
        if (in_array($status, [2, 6], true)) {
            if (! $this->save($session, $user, $node, $data, $status)) {
                return response()->json(['error' => 1]);
            }
        }
        if (in_array($status, [2, 4], true)) {
            $this->office->end($session, $status === 2 ? 'saved' : 'closed');
        }

        return response()->json(['error' => 0]);
    }

    /** @param  array<string, mixed>  $data */
    private function save(OfficeSession $session, User $user, Node $node, array $data, int $status): bool
    {
        $meta = ['provider' => 'onlyoffice', 'status' => $status,
            'editors' => array_values(array_filter(array_map('intval', is_array($data['users'] ?? null) ? $data['users'] : [])))];
        $type = strtolower((string) ($data['filetype'] ?? ''));
        if ($type !== '' && $type !== strtolower(pathinfo($node->name, PATHINFO_EXTENSION))) {
            $this->office->failed($session, $node, 'Der Office-Server liefert einen anderen Dateityp ('.$type.').', $meta);

            return false;
        }
        try {
            $path = $this->office->fetch((string) ($data['url'] ?? ''));
        } catch (Throwable $e) {
            $this->office->failed($session, $node, $e->getMessage(), $meta);

            return false;
        }
        try {
            // forcesavetype 2: the server's timer, i.e. an autosave
            $this->office->save($session, $user, $node, $path, $status === 6 && (int) ($data['forcesavetype'] ?? 0) === 2, $meta);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function jwt(Request $request): ?array
    {
        $secret = (string) $this->settings->secret();
        $header = (string) $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : (string) $request->input('token', '');

        return $token === '' ? null : Jwt::decode($token, $secret);
    }
}
