<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\ClothingStockLocationAssignment;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class AssignClothingStockCommand extends Command
{
    protected $signature = 'locations:assign-clothing-stock
        {plan : JSON-Liste mit table: kk_stock, id, location_id und expected_location_id: null}
        {--apply : Geprüften Plan ausdrücklich anwenden}
        {--by= : ID des aktiven globalen Administrators}
        {--reason= : Prüfgrund; bestätigt bewusst geprüfte ganze Bestandszeilen und deren Historie}
        {--backup-confirmed : Operator bestätigt Backup; der Befehl prüft kein Backup}
        {--expect= : SHA256 aus der aktuellen Vorschau}';

    protected $description = 'Ordnet ganze standortlose Mengenbestandszeilen zu, ohne Aufteilung oder operative Isolation; ohne --apply nur Vorschau';

    public function handle(ClothingStockLocationAssignment $assignment): int
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
            $this->error($exception instanceof QueryException
                ? 'Datenbankkonflikt; nichts zugeordnet. Bitte Vorschau erneut prüfen.'
                : $exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['dry_run' => ! $this->option('apply'), ...$result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
