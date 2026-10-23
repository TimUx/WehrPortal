<?php

namespace Extensions\Locations\Console;

use App\Models\User;
use Extensions\Locations\Services\LeadershipAssignments;
use Extensions\Locations\Services\LeadershipProjections;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeadershipCommand extends Command
{
    protected $signature = 'locations:leadership
        {operation : grant, revoke oder summary; approve ist stillgelegt}
        {--by= : Aktive Person; grant/revoke erfordern globale Administration}
        {--user= : ID der berechtigten Person}
        {--location= : Genau eine Standort-ID, keine Platzhalter}
        {--profile= : buergermeister, kommune, brandschutzamt, gbi oder spi}
        {--capability=* : Exakte Capability; mehrfach möglich}
        {--reason= : Verpflichtende Begründung bei grant/revoke}
        {--expires= : Verpflichtendes Ablaufdatum, höchstens 366 Tage ab Vergabe}
        {--valid-from= : Optionaler künftiger Gültigkeitsbeginn}
        {--year= : Genau ein Jahr für jährliche Einsatz-/Prüfungszahlen bei summary}
        {--assignment= : ID der zu widerrufenden Vergabe}';

    protected $description = 'Verwaltet Führungs-Capabilities und liest begrenzte Zusammenfassungen; keine bestehenden Modulansichten';

    public function handle(LeadershipAssignments $assignments): int
    {
        try {
            $by = User::withoutGlobalScopes()->findOrFail($this->idOption('by'));
            $location = $this->idOption('location');
            if ($this->argument('operation') === 'grant') {
                if ($this->option('year') !== null) {
                    throw new InvalidArgumentException('--year ist nur bei summary erlaubt.');
                }
                if ($this->option('assignment') !== null) {
                    throw new InvalidArgumentException('--assignment ist nur bei approve oder revoke erlaubt.');
                }
                $grant = $assignments->grant(
                    $by, $this->idOption('user'), $location, (string) $this->option('profile'),
                    $this->option('capability'), (string) $this->option('reason'), (string) $this->option('expires'),
                    $this->option('valid-from'),
                );
                $this->info('Vergabe '.$grant->id.' gespeichert. Keine bestehenden Modulansichten freigeschaltet.');
            } elseif ($this->argument('operation') === 'summary') {
                foreach (['user', 'profile', 'expires', 'valid-from', 'assignment', 'reason'] as $option) {
                    if ($this->option($option) !== null) {
                        throw new InvalidArgumentException('summary erlaubt nur --by, --location, genau eine --capability und gegebenenfalls --year.');
                    }
                }
                $capabilities = $this->option('capability');
                if (count($capabilities) !== 1) {
                    throw new InvalidArgumentException('Genau eine Capability für die Zusammenfassung erforderlich.');
                }
                $year = $this->option('year');
                if ($year !== null && preg_match('/\A[1-9][0-9]{3}\z/', $year) !== 1) {
                    throw new InvalidArgumentException('--year muss ein vierstelliges Jahr sein.');
                }
                $summary = app(LeadershipProjections::class)->read($by, $location, $capabilities[0], $year === null ? null : (int) $year);
                $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } elseif (in_array($this->argument('operation'), ['approve', 'revoke'], true)) {
                foreach (['user', 'profile', 'expires', 'valid-from', 'year'] as $option) {
                    if ($this->option($option) !== null) {
                        throw new InvalidArgumentException('approve/revoke erlaubt nur --assignment, --location, --by und bei revoke --reason.');
                    }
                }
                if ($this->option('capability') !== []) {
                    throw new InvalidArgumentException('approve/revoke betrifft die gesamte exakte Vergabe.');
                }
                if ($this->argument('operation') === 'approve') {
                    if ($this->option('reason') !== null) {
                        throw new InvalidArgumentException('approve ist stillgelegt; --reason ist dabei nicht zulässig.');
                    }
                    $assignments->approve($by, $this->idOption('assignment'), $location);
                } else {
                    $assignments->revoke($by, $this->idOption('assignment'), $location, (string) $this->option('reason'));
                    $this->info('Vergabe widerrufen.');
                }
            } else {
                throw new InvalidArgumentException('Nur grant, revoke oder summary erlaubt; approve ist stillgelegt.');
            }
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (InvalidArgumentException|ModelNotFoundException|HttpException|QueryException $exception) {
            $this->error('Zugriff verweigert: aktive Person, exakte IDs und gültige Berechtigung erforderlich; Änderungen nur durch globale Administration.');

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
