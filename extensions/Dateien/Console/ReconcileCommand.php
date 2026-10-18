<?php

namespace Extensions\Dateien\Console;

use Extensions\Dateien\Services\Maintenance;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'dateien:reconcile {--verify : Prüfsummen aller Versionen nachrechnen (langsam)}';

    protected $description = 'Compares the file store with the database (missing, changed and orphaned files); deletes nothing';

    public function handle(Maintenance $maintenance): int
    {
        $report = $maintenance->reconcile((bool) $this->option('verify'));
        $this->line(sprintf('%d Versionen, %d Dateien geprüft.', $report['versions'], $report['files']));
        foreach (['missing' => 'Fehlende Dateien', 'size_mismatch' => 'Größe abweichend', 'checksum_mismatch' => 'Prüfsumme abweichend'] as $key => $label) {
            foreach ($report[$key] as $entry) {
                $this->warn("{$label}: Version {$entry['version']} (Eintrag {$entry['node']}) {$entry['key']}");
            }
        }
        if ($report['orphans'] !== []) {
            $this->warn(count($report['orphans']).' verwaiste Dateien (Löschen nur über die Admin-Seite).');
        }

        return Maintenance::problems($report) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
