<?php

namespace Modules\Einsatzbericht\src\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;

/**
 * POST (or GET) /webhooks/divera/{key}: no login, the secret key is the protection. Answers 200 at
 * once and makes the draft after the response, so DIVERA never waits or retries because of us.
 */
class DiveraWebhookController
{
    public function __invoke(Request $request, string $key, DiveraWebhook $webhook)
    {
        $expected = DiveraWebhook::key();
        abort_unless($expected !== null && hash_equals($expected, $key), 404);

        $entry = $webhook->receive($request);

        if ($entry->status === 'pending') {
            dispatch(function () use ($entry) {
                app(DiveraWebhook::class)->process(DiveraRequest::find($entry->id));
            })->afterResponse();
        }

        return response()->json(['ok' => true]);
    }
}
