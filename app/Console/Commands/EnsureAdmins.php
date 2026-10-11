<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EnsureAdmins extends Command
{
    protected $signature = 'app:ensure-admins';

    protected $description = 'Legt die in ADMIN_EMAILS genannten Admin-Konten an bzw. aktiviert sie';

    public function handle(): int
    {
        $emails = collect(explode(',', (string) config('app.admin_emails')))
            ->map(fn (string $email) => Str::lower(trim($email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL));

        if ($emails->isEmpty()) {
            $this->info('ADMIN_EMAILS ist leer – nichts zu tun.');

            return self::SUCCESS;
        }

        foreach ($emails as $email) {
            $user = User::firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->name = Str::before($email, '@');
            }

            $user->is_admin = true;
            $user->is_active = true;
            $user->save();

            $this->info(($user->wasRecentlyCreated ? 'Angelegt: ' : 'Aktualisiert: ').$email);
        }

        return self::SUCCESS;
    }
}
