<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailChangeService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class EmailChangeController extends Controller
{
    public function __construct(private readonly EmailChangeService $service) {}

    /** Shows the button; does NOT confirm, so link scanners in mail systems cannot do it */
    public function show(string $token): View
    {
        $request = $this->service->find($token);

        return view('auth.confirm-email-change', [
            'token' => $token,
            'newEmail' => $request?->new_email,
            'error' => $request ? null : 'Dieser Link ist ungültig oder abgelaufen. Beantrage die Änderung im Profil noch einmal.',
        ]);
    }

    public function confirm(string $token): View
    {
        try {
            $user = $this->service->confirm($token);
        } catch (ValidationException $e) {
            return view('auth.confirm-email-change', [
                'token' => $token,
                'newEmail' => null,
                'error' => $e->errors()['token'][0] ?? $e->errors()['email'][0] ?? 'Die Änderung ist fehlgeschlagen.',
            ]);
        }

        return view('auth.email-change-done', ['email' => $user->email]);
    }
}
