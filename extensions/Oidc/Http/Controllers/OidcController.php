<?php

namespace Extensions\Oidc\Http\Controllers;

use Extensions\Oidc\OidcException;
use Extensions\Oidc\Services\OidcClient;
use Extensions\Oidc\Services\OidcLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class OidcController
{
    private const FLOW_TTL = 600;

    public function redirect(Request $request, OidcClient $client): RedirectResponse
    {
        try {
            $hint = $request->query('login_hint');
            $start = $client->authorizationRequest(is_string($hint) ? $hint : null);
        } catch (OidcException $e) {
            OidcLoginService::logFailure($e);

            return $this->failed($e->getMessage());
        }

        $request->session()->put('oidc.flow', $start['flow']);

        return redirect()->away($start['url']);
    }

    public function callback(Request $request, OidcClient $client, OidcLoginService $login): RedirectResponse
    {
        $flow = $request->session()->pull('oidc.flow');

        try {
            if (! is_array($flow) || time() - (int) ($flow['started_at'] ?? 0) > self::FLOW_TTL) {
                throw OidcException::of('generic', 'flow_missing');
            }
            if ($request->filled('error')) {
                // Only the standard error code is logged
                throw OidcException::of('generic', 'idp_error_'.preg_replace('/[^a-z_]/', '', (string) $request->query('error')));
            }
            $state = $request->query('state');
            if (! is_string($state) || ! hash_equals((string) $flow['state'], $state)) {
                throw OidcException::of('generic', 'state_mismatch');
            }
            $code = $request->query('code');
            if (! is_string($code) || $code === '') {
                throw OidcException::of('generic', 'code_missing');
            }

            $claims = $client->validateIdToken($client->exchangeCode($code, (string) $flow['verifier']), (string) $flow['nonce']);
            $user = $login->login($claims, $request);
        } catch (OidcException $e) {
            OidcLoginService::logFailure($e);

            return $this->failed($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('oidc::oidc.error.generic'));
        }

        $request->session()->regenerate();

        return $request->session()->pull('first_login') ? redirect()->route('welcome') : redirect()->intended(route('home'));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('auth.magic-link.form')->withErrors(['auth' => $message]);
    }
}
