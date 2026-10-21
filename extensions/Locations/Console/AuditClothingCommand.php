<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\ClothingReadinessReport;
use Illuminate\Console\Command;
use Throwable;

class AuditClothingCommand extends Command
{
    protected $signature = 'locations:audit-clothing {--json : Maschinenlesbare Ausgabe ausschließlich mit Summen}';

    protected $description = 'Prüft die Kleiderkammer-Standortbereitschaft nur lesend, ohne Zuordnung oder Freigabe';

    public function handle(ClothingReadinessReport $inventory): int
    {
        try {
            $report = $inventory->report();
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'read_only' => true,
                    'enforcement_verified' => false,
                    'error' => 'clothing_readiness_unavailable',
                ], JSON_THROW_ON_ERROR));
            } else {
                $this->error(ClothingReadinessReport::FAILURE);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->warn('Nur lesend. Keine Isolationsbestätigung oder Zielzuordnung. Prüfbedarf ist kein bestätigter Verstoß: standortübergreifende Zentrallager-Nutzung und persönliche Zuordnungen benötigen fachliche Freigabe.');
            $this->line('Alle Zeilen, einschließlich zurückgegebener und gelöschter Ausgaben. Nicht verfügbare Prüfungen sind nicht als null Fehler verifiziert.');
            $this->warn('Mengenbestand: Standort ist nur Eigentumsmetadatum. Ausgabe sucht weiterhin global nach Typ/Größe/Gruppe; globale Eindeutigkeit bleibt. Neue Mengenausgaben mit Herkunft kehren in ihre ursprüngliche Bestandszeile zurück; alte Ausgaben ohne Herkunft weiterhin nach Typ/Größe/Gruppe. Alle Bestandszeilen sind operativ ungeklärt, auch zugeordnete. Keine Isolation oder Durchsetzung.');
            $this->table(['Tabelle', 'Klasse', 'Verfügbarkeit', 'Zeilen'], array_map(
                fn (array $table): array => [...array_slice(array_values($table), 0, 3), $table['rows'] ?? '—'],
                $report['tables'],
            ));
            foreach ($report['checks'] as $key => $check) {
                $this->line($key.': '.json_encode($check, JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }
}
