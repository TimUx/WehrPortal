<?php

namespace Extensions\Oidc\Console;

use Extensions\Oidc\Support\OidcSettings;
use Illuminate\Console\Command;

class DisableCommand extends Command
{
    protected $signature = 'oidc:disable';

    protected $description = 'Emergency: switch the OIDC login off and allow the magic link for everyone again';

    public function handle(OidcSettings $settings): int
    {
        $settings->set('enabled', false);
        $settings->set('exclusive', false);

        $this->info('OIDC-Login ist ausgeschaltet. Die Anmeldung per Link funktioniert wieder für alle Benutzer.');

        return self::SUCCESS;
    }
}
