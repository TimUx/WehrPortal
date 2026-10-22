<?php

namespace Extensions\Locations\Console;

use App\Models\User;
use Extensions\Locations\Services\ClothingOperations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TransferClothingStockCommand extends Command
{
    protected $signature = 'locations:transfer-clothing-stock
        {from : ID des bestehenden Quellbestandskontos}
        {to : ID des bestehenden Zielbestandskontos}
        {quantity : Positive Stückzahl}
        {--by= : ID des aktiven Operators}
        {--reason= : Verpflichtender Buchungsgrund}
        {--key= : Neuer eindeutiger UUID-Buchungsschlüssel gegen Wiederholung}
        {--reverse= : Originalbuchungs-ID für eine vollständige Gegenbuchung}';

    protected $description = 'Bucht Mengen atomar zwischen ausdrücklich berechtigten Standorten; keine Personen- oder Herkunftsänderung';

    public function handle(ClothingOperations $operations): int
    {
        try {
            $input = ['from' => $this->argument('from'), 'to' => $this->argument('to'),
                'quantity' => $this->argument('quantity'), 'by' => $this->option('by'), 'reverse' => $this->option('reverse')];
            foreach ($input as $name => $value) {
                if ($name === 'reverse' && $value === null) {
                    continue;
                }
                if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || (string) (int) $value !== $value) {
                    throw new RuntimeException("{$name} muss eine positive ganzzahlige ID/Menge sein.");
                }
            }
            $actor = User::withoutGlobalScopes()->findOrFail((int) $input['by']);
            $id = $operations->transfer($actor, (int) $input['from'], (int) $input['to'], (int) $input['quantity'],
                (string) $this->option('reason'), (string) $this->option('key'),
                $input['reverse'] === null ? null : (int) $input['reverse']);
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException || $exception instanceof HttpExceptionInterface
                ? 'Nicht berechtigt oder Datenbankkonflikt; nichts gebucht.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['transfer_id' => $id], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
