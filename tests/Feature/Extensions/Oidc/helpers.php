<?php

use App\Models\User;
use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

const OIDC_ISSUER = 'https://sso.example.com/realms/musterstadt';

/** Saves a working OIDC configuration (the client secret goes through the encrypting setter) */
function oidcConfigure(array $values = []): OidcSettings
{
    $settings = app(OidcSettings::class);
    foreach (array_merge([
        'enabled' => true, 'issuer' => OIDC_ISSUER, 'client_id' => 'openwehr', 'link_by_email' => true, 'jit' => false, 'exclusive' => false,
    ], $values) as $key => $value) {
        $settings->set($key, $value);
    }
    $settings->saveSecret('s3cr3t-value');

    return $settings;
}

function oidcSign(JWK $key, array $claims, string $alg = 'RS256'): string
{
    $jws = (new JWSBuilder(new AlgorithmManager([new RS256])))->create()
        ->withPayload(json_encode($claims))
        ->addSignature($key, ['alg' => $alg, 'kid' => 'k1'])
        ->build();

    return (new CompactSerializer)->serialize($jws, 0);
}

/**
 * A fake IdP: discovery, JWKS and token endpoint. $idp->claims are merged over valid claims when
 * the token is issued; $idp->signWith can swap the signing key.
 */
function oidcIdp(): stdClass
{
    $idp = new stdClass;
    $idp->key = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1']);
    $idp->signWith = $idp->key;
    $idp->claims = [];
    $idp->nonce = null;
    $idp->tokenStatus = 200;

    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'authorization_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(['keys' => [$idp->key->toPublic()->jsonSerialize()]]),
        OIDC_ISSUER.'/protocol/openid-connect/token' => function (Request $request) use ($idp) {
            $claims = array_merge([
                'iss' => OIDC_ISSUER, 'aud' => 'openwehr', 'sub' => 'subject-1', 'exp' => time() + 300, 'iat' => time(),
                'nonce' => $idp->nonce, 'email' => 'hanna@example.com', 'email_verified' => true, 'name' => 'Hanna Hydrant',
            ], $idp->claims);

            return Http::response(['id_token' => oidcSign($idp->signWith, array_filter($claims, fn ($v) => $v !== '__unset__')), 'token_type' => 'Bearer'], $idp->tokenStatus);
        },
    ]);

    return $idp;
}

/** Runs the browser flow: redirect to the IdP, then the callback. Returns the callback response. */
function oidcFlow(stdClass $idp, array $callback = []): TestResponse
{
    $redirect = test()->get('/auth/oidc/redirect');
    $redirect->assertRedirect();
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    $idp->nonce = $query['nonce'];

    return test()->get('/auth/oidc/callback?'.http_build_query(array_merge(['code' => 'abc', 'state' => $query['state']], $callback)));
}

function oidcLocalAdmin(array $attributes = []): User
{
    $admin = User::factory()->admin()->create($attributes);

    return $admin->forceFill(['ext_local_admin' => true]);
}
