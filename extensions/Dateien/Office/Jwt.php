<?php

namespace Extensions\Dateien\Office;

/** Minimal HS256 JSON Web Tokens for ONLYOFFICE; any other algorithm is rejected. */
class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $segments = [self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)), self::b64(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    /** @return array<string, mixed>|null the payload, or null for any malformed, unsigned, wrongly signed or expired token */
    public static function decode(string $token, string $secret, int $leeway = 60): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $secret === '') {
            return null;
        }
        $header = json_decode((string) self::unb64($parts[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true);
        $signature = self::unb64($parts[2]);
        if ($signature === null || ! hash_equals($expected, $signature)) {
            return null;
        }
        $payload = json_decode((string) self::unb64($parts[1]), true);
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && (! is_numeric($payload['exp']) || (int) $payload['exp'] + $leeway < time())) {
            return null;
        }
        if (isset($payload['nbf']) && (! is_numeric($payload['nbf']) || (int) $payload['nbf'] - $leeway > time())) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]*\z/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
