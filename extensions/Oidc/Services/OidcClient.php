<?php

namespace Extensions\Oidc\Services;

use App\Support\SafeUrl;
use Extensions\Oidc\OidcException;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * OpenID Connect Authorization Code Flow with PKCE (S256), state and nonce. Discovery, JWKS and
 * the token endpoint are only called on public https addresses (App\Support\SafeUrl). ID tokens are
 * verified with web-token/jwt-library: asymmetric algorithms only (no "none", no HMAC), then issuer,
 * audience, expiry and nonce are checked.
 */
class OidcClient
{
    private const LEEWAY = 60;

    public function __construct(private readonly OidcSettings $settings) {}

    /** @return array<string, mixed> */
    public function discovery(?string $issuer = null, bool $fresh = false): array
    {
        $issuer = rtrim($issuer ?? (string) $this->settings->issuer(), '/');
        $key = 'ext_oidc_discovery_'.hash('sha256', $issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, fn () => $this->fetchDiscovery($issuer));
    }

    /** @return array<string, mixed> */
    private function fetchDiscovery(string $issuer): array
    {
        if (! str_starts_with($issuer, 'https://')) {
            throw OidcException::of('unavailable', 'issuer_not_https');
        }

        $document = $this->getJson($issuer.'/.well-known/openid-configuration');

        if (rtrim((string) ($document['issuer'] ?? ''), '/') !== $issuer) {
            throw OidcException::of('unavailable', 'discovery_issuer_mismatch');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($document[$field] ?? null) || ! str_starts_with($document[$field], 'https://')) {
                throw OidcException::of('unavailable', 'discovery_missing_'.$field);
            }
        }

        return $document;
    }

    /**
     * Start of a login: the URL to send the browser to and the data to keep in the session.
     *
     * @return array{url: string, flow: array<string, mixed>}
     */
    public function authorizationRequest(): array
    {
        $discovery = $this->discovery();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Base64UrlSafe::encodeUnpadded(random_bytes(48));
        $challenge = Base64UrlSafe::encodeUnpadded(hash('sha256', $verifier, true));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->clientId(),
            'redirect_uri' => $this->settings->redirectUri(),
            'scope' => implode(' ', $this->settings->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = $discovery['authorization_endpoint'];

        return [
            'url' => $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query,
            'flow' => ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'started_at' => time()],
        ];
    }

    /** Exchanges the code for the ID token (confidential client, PKCE verifier) */
    public function exchangeCode(string $code, string $verifier): string
    {
        $discovery = $this->discovery();
        $endpoint = $discovery['token_endpoint'];

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->settings->redirectUri(),
            'code_verifier' => $verifier,
        ];

        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $request = Http::timeout(15)->acceptJson()->asForm();
        if (in_array('client_secret_post', $methods, true) || ! in_array('client_secret_basic', $methods, true)) {
            $form['client_id'] = $this->settings->clientId();
            $form['client_secret'] = $this->settings->secret();
        } else {
            $request = $request->withBasicAuth(rawurlencode((string) $this->settings->clientId()), rawurlencode((string) $this->settings->secret()));
        }

        try {
            [$host, $port, $ip] = SafeUrl::check($endpoint);
            $response = $request
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]]])
                ->post($endpoint, $form);
        } catch (Throwable) {
            throw OidcException::of('unavailable', 'token_request_failed');
        }

        $idToken = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            throw OidcException::of('generic', 'token_response_invalid_'.$response->status());
        }

        return $idToken;
    }

    /**
     * Verifies signature, issuer, audience, expiry and nonce of an ID token.
     *
     * @return array<string, mixed> the claims
     */
    public function validateIdToken(string $idToken, string $nonce): array
    {
        $discovery = $this->discovery();

        try {
            $jws = (new CompactSerializer)->unserialize($idToken);
        } catch (Throwable) {
            throw OidcException::of('generic', 'token_malformed');
        }

        $verifier = new JWSVerifier(new AlgorithmManager([
            new RS256, new RS384, new RS512, new PS256, new PS384, new PS512, new ES256, new ES384, new ES512,
        ]));

        // A cached key set may be outdated after a key rotation: retry once with a fresh one
        $verified = false;
        foreach ([false, true] as $fresh) {
            try {
                $verified = $verifier->verifyWithKeySet($jws, $this->keySet($discovery['jwks_uri'], $fresh), 0);
            } catch (Throwable) {
                $verified = false;
            }
            if ($verified) {
                break;
            }
        }
        if (! $verified) {
            throw OidcException::of('generic', 'signature_invalid');
        }

        $claims = json_decode((string) $jws->getPayload(), true);
        if (! is_array($claims)) {
            throw OidcException::of('generic', 'claims_invalid');
        }

        $now = time();
        $clientId = (string) $this->settings->clientId();
        $audience = (array) ($claims['aud'] ?? []);

        $reason = match (true) {
            ! is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== rtrim((string) ($discovery['issuer'] ?? ''), '/') => 'issuer_mismatch',
            ! in_array($clientId, $audience, true) => 'audience_mismatch',
            count($audience) > 1 && ($claims['azp'] ?? null) !== $clientId => 'azp_mismatch',
            ! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] + self::LEEWAY < $now => 'expired',
            isset($claims['nbf']) && (! is_numeric($claims['nbf']) || (int) $claims['nbf'] - self::LEEWAY > $now) => 'not_yet_valid',
            isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] - self::LEEWAY > $now) => 'issued_in_future',
            ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce']) => 'nonce_mismatch',
            ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' => 'subject_missing',
            default => null,
        };
        if ($reason !== null) {
            throw OidcException::of('generic', $reason);
        }

        return $claims;
    }

    /**
     * Connection test for the admin page: discovery document and key set.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(?string $issuer): array
    {
        $issuer = $issuer ? rtrim($issuer, '/') : null;
        if (! $issuer) {
            return ['ok' => false, 'message' => __('oidc::oidc.test.no_issuer')];
        }

        try {
            $discovery = $this->discovery($issuer, fresh: true);
            $keys = $this->keySet($discovery['jwks_uri'], true);
        } catch (OidcException $e) {
            return ['ok' => false, 'message' => __('oidc::oidc.test.failed', ['reason' => $e->reason])];
        } catch (Throwable) {
            return ['ok' => false, 'message' => __('oidc::oidc.test.failed', ['reason' => 'jwks'])];
        }

        return ['ok' => true, 'message' => __('oidc::oidc.test.ok', ['keys' => $keys->count()])];
    }

    private function keySet(string $uri, bool $fresh): JWKSet
    {
        $key = 'ext_oidc_jwks_'.hash('sha256', $uri);
        if ($fresh) {
            Cache::forget($key);
        }

        $json = Cache::remember($key, 600, fn () => json_encode($this->getJson($uri)));

        try {
            return JWKSet::createFromJson((string) $json);
        } catch (Throwable) {
            Cache::forget($key);
            throw OidcException::of('unavailable', 'jwks_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        try {
            $response = SafeUrl::get($url, ['Accept' => 'application/json'], 10);
        } catch (Throwable) {
            throw OidcException::of('unavailable', 'unreachable');
        }

        $json = $response->successful() ? $response->json() : null;
        if (! is_array($json)) {
            throw OidcException::of('unavailable', 'http_'.$response->status());
        }

        return $json;
    }
}
