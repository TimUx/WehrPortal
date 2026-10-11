<?php

namespace Modules\Lehrgaenge\src\Console\Commands;

use App\Models\Module;
use Illuminate\Console\Command;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use RuntimeException;

class ImportCatalog extends Command
{
    protected $signature = 'lehrgaenge:import-catalog {--force : also when the module is switched off}';

    protected $description = 'Read the Lehrgänge without fixed date from the fox112 page of the own Kreis into the catalog';

    public function handle(CatalogImporter $importer): int
    {
        if (! $this->option('force') && ! Module::where('key', 'lehrgaenge')->where('is_active', true)->exists()) {
            $this->info('Module lehrgaenge is not active, skipped');

            return self::SUCCESS;
        }

        // Without the address of the Kreis there is nothing to read; not an error
        if (CatalogImporter::url() === null) {
            $this->info('No fox112 address set, skipped');

            return self::SUCCESS;
        }

        try {
            $stats = $importer->import();
        } catch (RuntimeException $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d listed, %d new courses, %d no longer offered', $stats['listed'], $stats['courses'], $stats['gone']));

        return self::SUCCESS;
    }
}
