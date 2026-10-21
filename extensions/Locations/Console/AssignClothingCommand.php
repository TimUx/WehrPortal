<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\ClothingLocationAssignment;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class AssignClothingCommand extends Command
{
    protected $signature = 'locations:assign-clothing
        {plan : JSON-Liste mit table: kk_items, id, location_id und expected_location_id: null}
        {--apply : Geprüften Plan ausdrücklich anwenden}
        {--by= : ID des aktiven globalen Administrators}
        {--reason= : Prüfgrund; bestätigt bewusst geprüfte historische Ausgaben, Meldungen und Behandlungen}
        {--backup-confirmed : Operator bestätigt Backup; der Befehl prüft kein Backup}
        {--expect= : SHA256 aus der aktuellen Vorschau}';

    protected $description = 'Ordnet geprüfte standortlose Einzelkleidung zu; ohne --apply nur Vorschau. Für anwendbaren Hash auch dort --by und --reason angeben';

    public function handle(ClothingLocationAssignment $assignment): int
    {
        try {
            $by = $this->option('by');
            if ($by !== null && (preg_match('/\A[1-9][0-9]*\z/', $by) !== 1 || (string) (int) $by !== $by)) {
                throw new RuntimeException('--by muss eine positive ganzzahlige ID sein.');
            }
            $result = $assignment->run(
                (string) $this->argument('plan'), (bool) $this->option('apply'),
                $by === null ? null : (int) $by, (string) $this->option('reason'),
                $this->option('expect'), (bool) $this->option('backup-confirmed'),
            );
        } catch (RuntimeException $exception) {
            // SQL exceptions can contain private payloads or the review reason.
            $this->error($exception instanceof QueryException
                ? 'Datenbankkonflikt; nichts zugeordnet. Bitte Vorschau erneut prüfen.'
                : $exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['dry_run' => ! $this->option('apply'), ...$result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
