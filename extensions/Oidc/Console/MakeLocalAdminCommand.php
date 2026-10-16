<?php

namespace Extensions\Oidc\Console;

use App\Models\User;
use Illuminate\Console\Command;

class MakeLocalAdminCommand extends Command
{
    protected $signature = 'oidc:make-local-admin {email : E-mail address of the user} {--grant-admin : Also make the user an admin}';

    protected $description = 'Mark a user as local system admin (can always log in with the magic link, even in IdP-only mode)';

    public function handle(): int
    {
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first();

        if (! $user) {
            $this->error('Kein Benutzer mit dieser E-Mail-Adresse gefunden.');

            return self::FAILURE;
        }
        if (! $user->is_active) {
            $this->error('Der Benutzer ist deaktiviert.');

            return self::FAILURE;
        }
        if (! $user->is_admin && ! $this->option('grant-admin')) {
            $this->error('Der Benutzer ist kein Admin. Mit --grant-admin wird er zum Admin gemacht.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true, 'ext_local_admin' => true])->save();

        $this->info("{$user->email} ist jetzt lokaler System-Admin.");

        return self::SUCCESS;
    }
}
