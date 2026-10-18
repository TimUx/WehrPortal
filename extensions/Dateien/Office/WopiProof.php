<?php

namespace Extensions\Dateien\Office;

use Illuminate\Http\Request;

/**
 * Checks the WOPI proof headers (X-WOPI-Proof, X-WOPI-ProofOld, X-WOPI-TimeStamp): the request
 * must be signed by the configured office server's key from discovery, for exactly this URL and
 * token, and be at most 20 minutes old.
 */
class WopiProof
{
    /** .NET ticks between 0001-01-01 and 1970-01-01 */
    private const EPOCH_TICKS = 621355968000000000;

    public function __construct(private readonly WopiDiscovery $discovery) {}

    public function valid(Request $request, string $token): bool
    {
        $timestamp = (string) $request->header('X-WOPI-TimeStamp');
        $proof = base64_decode((string) $request->header('X-WOPI-Proof'), true);
        $proofOld = base64_decode((string) $request->header('X-WOPI-ProofOld'), true);
        if (! ctype_digit($timestamp) || $proof === false || $proof === '') {
            return false;
        }
        $seconds = intdiv((int) $timestamp - self::EPOCH_TICKS, 10000000);
        if (abs(time() - $seconds) > 20 * 60) {
            return false;
        }
        $discovery = $this->discovery->get();
        $current = $discovery['proof'] ? self::publicKey($discovery['proof']) : null;
        $old = $discovery['old'] ? self::publicKey($discovery['old']) : null;
        if ($current === null) {
            return false;
        }
        $data = self::data($token, $request->getSchemeAndHttpHost().$request->getRequestUri(), (int) $timestamp);

        return self::verify($data, $proof, $current)
            || ($proofOld !== false && $proofOld !== '' && self::verify($data, $proofOld, $current))
            || ($old !== null && self::verify($data, $proof, $old));
    }

    public static function data(string $token, string $url, int $ticks): string
    {
        $url = strtoupper($url);

        return pack('N', strlen($token)).$token.pack('N', strlen($url)).$url.pack('N', 8).pack('J', $ticks);
    }

    public static function ticks(?int $time = null): int
    {
        return ($time ?? time()) * 10000000 + self::EPOCH_TICKS;
    }

    /** PEM public key from the base64 modulus and exponent of discovery */
    public static function publicKey(array $key): ?string
    {
        $modulus = base64_decode($key['modulus'], true);
        $exponent = base64_decode($key['exponent'], true);
        if (! $modulus || ! $exponent) {
            return null;
        }
        $rsa = self::der(0x30, self::der(0x02, self::unsigned($modulus)).self::der(0x02, self::unsigned($exponent)));
        $algorithm = self::der(0x30, self::der(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01").self::der(0x05, ''));
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function verify(string $data, string $signature, string $pem): bool
    {
        $key = openssl_pkey_get_public($pem);

        return $key !== false && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function unsigned(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        return ord($bytes[0] ?? "\x00") > 0x7F ? "\x00".$bytes : ($bytes === '' ? "\x00" : $bytes);
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$value;
    }
}
