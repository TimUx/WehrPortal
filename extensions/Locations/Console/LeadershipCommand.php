<?php

namespace Extensions\Locations\Console;

use App\Models\User;
use Extensions\Locations\Services\LeadershipAssignments;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeadershipCommand extends Command
{
    protected $signature = 'locations:leadership
        {operation : grant, approve oder revoke; nur Grundlage für künftige explizite Ansichten}
        {--by= : ID des aktiven globalen Administrators}
        {--user= : ID der berechtigten Person}
        {--location= : Genau eine Standort-ID, keine Platzhalter}
        {--profile= : buergermeister, kommune, brandschutzamt, gbi, spi oder kommunaler_admin}
        {--capability=* : Exakte Capability; mehrfach möglich}
        {--reason= : Verpflichtende Begründung bei grant/revoke}
        {--expires= : Verpflichtendes Ablaufdatum, höchstens 366 Tage ab Vergabe}
        {--valid-from= : Optionaler künftiger Gültigkeitsbeginn}
        {--assignment= : ID der separat freizugebenden oder zu widerrufenden Vergabe}';

    protected $description = 'Vergibt oder widerruft befristete Führungs-Capabilities; schaltet keine bestehenden Modulansichten frei';

    public function handle(LeadershipAssignments $assignments): int
    {
        try {
            $by = User::withoutGlobalScopes()->findOrFail($this->idOption('by'));
            $location = $this->idOption('location');
            if ($this->argument('operation') === 'grant') {
                if ($this->option('assignment') !== null) {
                    throw new InvalidArgumentException('--assignment ist nur bei approve oder revoke erlaubt.');
                }
                $grant = $assignments->grant(
                    $by, $this->idOption('user'), $location, (string) $this->option('profile'),
                    $this->option('capability'), (string) $this->option('reason'), (string) $this->option('expires'),
                    $this->option('valid-from'),
                );
                $this->info('Vergabe '.$grant->id.' gespeichert. Keine bestehenden Modulansichten freigeschaltet.');
                if ($grant->getAttribute('profile') === 'kommunaler_admin') {
                    $this->warn('Ausstehend: Ein unabhängiger zweiter Administrator muss separat approve ausführen.');
                }
            } elseif (in_array($this->argument('operation'), ['approve', 'revoke'], true)) {
                foreach (['user', 'profile', 'expires', 'valid-from'] as $option) {
                    if ($this->option($option) !== null) {
                        throw new InvalidArgumentException('approve/revoke erlaubt nur --assignment, --location, --by und bei revoke --reason.');
                    }
                }
                if ($this->option('capability') !== []) {
                    throw new InvalidArgumentException('approve/revoke betrifft die gesamte exakte Vergabe.');
                }
                if ($this->argument('operation') === 'approve') {
                    if ($this->option('reason') !== null) {
                        throw new InvalidArgumentException('approve bestätigt den unveränderten ursprünglichen Auftrag.');
                    }
                    $assignments->approve($by, $this->idOption('assignment'), $location);
                    $this->info('Vergabe durch zweiten Administrator separat freigegeben.');
                } else {
                    $assignments->revoke($by, $this->idOption('assignment'), $location, (string) $this->option('reason'));
                    $this->info('Vergabe widerrufen.');
                }
            } else {
                throw new InvalidArgumentException('Nur grant, approve oder revoke erlaubt.');
            }
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (InvalidArgumentException|ModelNotFoundException|HttpException|QueryException $exception) {
            $this->error('Keine Änderung: aktive globale Administratoren, exakte IDs und gültige Vergabe erforderlich.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function idOption(string $name): int
    {
        $value = $this->option($name);
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || (string) (int) $value !== $value) {
            throw new InvalidArgumentException('--'.$name.' muss eine positive ganzzahlige ID sein.');
        }

        return (int) $value;
    }
}
