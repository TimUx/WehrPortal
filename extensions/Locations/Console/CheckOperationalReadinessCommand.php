<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\OperationalReadiness;
use Illuminate\Console\Command;

class CheckOperationalReadinessCommand extends Command
{
    protected $signature = 'locations:check-readiness {--json : Maschinenlesbare Ausgabe ohne personenbezogene Inhalte}';

    protected $description = 'Prüft ausschließlich lesend technische Inventur und offene Freigabesperren; erteilt keine Betriebsfreigabe';

    public function handle(OperationalReadiness $readiness): int
    {
        $report = $readiness->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->warn('Nur lesend: keine Betriebsfreigabe, keine bestätigte Isolation oder Durchsetzung. Technische Verfügbarkeit ist keine Abnahme.');
            if ($report['status'] === 'unavailable') {
                $this->error(OperationalReadiness::FAILURE);
            } else {
                $this->warn('Technische Inventur: CHECK — Prüfkandidaten, keine bestätigten Verstöße. Nicht geprüft ist nicht null; Beziehungssummen sind keine eindeutigen Betroffenenzahlen.');
                $this->table(['Inventur', 'Anzahl'], array_map(
                    fn (string $key, int $count): array => [$key, $count],
                    array_keys($report['technical_inventory']['summary']),
                    array_values($report['technical_inventory']['summary']),
                ));
                $rows = [];
                foreach ($report['technical_inventory']['findings'] as $category => $finding) {
                    $rows[] = [$category, $finding['count'] ?? 'nicht vollständig geprüft', $finding['observed_count'], $finding['uninspected']];
                }
                $this->table(['Prüfkategorie', 'Anzahl', 'Beobachtet', 'Nicht geprüft'], $rows);
            }
            $this->line('P1: Administrativ abgeschlossen; fachlich nicht verifiziert, kein privater Originalnachweis.');
            $this->warn('Freigabe gesperrt — auch bei leeren Daten, null Prüfkandidaten oder grünen Tests:');
            foreach ($report['release_blockers'] as $label) {
                $this->line($label);
            }
        }

        return $report['status'] === 'unavailable' ? self::FAILURE : 2;
    }
}
