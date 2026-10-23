<?php

namespace Extensions\Locations\Console;

use App\Models\User;
use Extensions\Locations\Services\ClothingOperations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CorrectClothingHistoryCommand extends Command
{
    protected $signature = 'locations:correct-clothing-history
        {issuance : ID einer abgeschlossenen Mengenausgabe}
        {source? : ID der belegten historischen Bestandsquelle}
        {--by= : ID des aktiven globalen Administrators}
        {--reason= : Verpflichtender Prüfgrund}
        {--evidence= : Belegreferenz; keine Datei wird gelesen oder automatisch geprüft}
        {--apply : Geprüfte Herkunftsaussage im separaten Korrekturjournal ergänzen}
        {--expect= : SHA256 der unveränderten Vorschau}
        {--confirmed : Explizite Bestätigung der fachlichen Prüfung}
        {--history : Admin-Prüfbericht mit Originalquelle und letzter belegter Quelle anzeigen; keine Rücknahme oder Mengenänderung}';

    protected $description = 'Prüft historische Herkunftsaussagen; Originalausgabe, Rücknahmeziel und Bestandsmengen bleiben unverändert';

    public function handle(ClothingOperations $operations): int
    {
        try {
            $history = (bool) $this->option('history');
            if ($history && ($this->option('apply') || $this->argument('source') !== null)) {
                throw new RuntimeException('--history ist nur ohne Quellkonto und ohne --apply zulässig.');
            }
            $input = ['issuance' => $this->argument('issuance'), 'by' => $this->option('by')];
            if (! $history) {
                $input['source'] = $this->argument('source');
            }
            foreach ($input as $name => $value) {
                if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || (string) (int) $value !== $value) {
                    throw new RuntimeException("{$name} muss eine positive ganzzahlige ID sein.");
                }
            }
            $actor = User::withoutGlobalScopes()->findOrFail((int) $input['by']);
            $result = $history
                ? ['historical_report' => $operations->history($actor, (int) $input['issuance'])]
                : ['dry_run' => ! $this->option('apply'), ...$operations->correctHistory(
                    $actor, (int) $input['issuance'], (int) $input['source'],
                    (string) $this->option('reason'), (string) $this->option('evidence'),
                    (bool) $this->option('apply'), $this->option('expect'), (bool) $this->option('confirmed'),
                )];
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException || $exception instanceof HttpExceptionInterface
                ? 'Nicht berechtigt oder Datenbankkonflikt; keine Korrektur angewendet.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
