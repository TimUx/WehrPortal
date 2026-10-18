<?php

namespace Extensions\Dateien\Http\Controllers;

use Extensions\Dateien\Office\Jwt;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

    public function callback(Request $request, string $token): JsonResponse
    {
        [$session] = $this->office->resolve($token, 'onlyoffice');
        $payload = $this->jwt($request);
        abort_if($payload === null, 401);
        // Header tokens wrap the body in "payload"; body tokens carry it directly
        $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $status = (int) ($data['status'] ?? 0);
        if ($status === 4) {
            $this->office->end($session, 'closed');
        }

        // Milestone 3 opens read only: there is nothing to save (statuses 2, 3, 6, 7 are ignored)
        return response()->json(['error' => 0]);
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
