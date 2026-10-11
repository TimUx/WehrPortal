<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * An admin sees the app exactly as a Kamerad does: logged in as the Kamerad, with the admin's id
 * kept in the session for "Zurück zum Admin". Admins and inactive Kameraden can't be taken over.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $member): void
    {
        if (! $admin->is_admin || $this->active()) {
            throw ValidationException::withMessages(['user' => 'Nur Admins können die App als Kamerad ansehen.']);
        }

        if ($member->is($admin) || $member->is_admin || ! $member->is_active) {
            throw ValidationException::withMessages(['user' => 'Admins und inaktive Kameraden lassen sich nicht übernehmen.']);
        }

        Log::info('Admin views the app as Kamerad', ['admin' => $admin->id, 'member' => $member->id]);
        app(ActivityRecorder::class)->record('impersonate', User::class, $member->id, $member->name, userId: $admin->id);
        session()->put(self::SESSION_KEY, $admin->id);
        Auth::guard('web')->login($member);
    }

    /** Back to the admin's own login; null if nobody was impersonated */
    public function stop(): ?User
    {
        $admin = User::find(session()->pull(self::SESSION_KEY));

        if (! $admin?->is_admin || ! $admin->is_active) {
            Auth::guard('web')->logout();

            return null;
        }

        Auth::guard('web')->login($admin);

        return $admin;
    }

    public function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    public function impersonator(): ?User
    {
        return $this->active() ? User::find(session(self::SESSION_KEY)) : null;
    }
}
