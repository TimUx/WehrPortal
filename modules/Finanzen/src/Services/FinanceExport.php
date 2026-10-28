<?php

namespace Modules\Finanzen\src\Services;

use App\Support\Csv;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Finanzen\src\Models\Entry;
use RuntimeException;
use ZipArchive;

/**
 * For the Steuerberater and the Behörden: all approved and paid Buchungen of a year as CSV (Excel
 * opens it directly: semicolon, decimal comma, UTF-8 with BOM) and all Belege as ZIP, named by
 * Belegnummer so they match the list.
 */
class FinanceExport
{
    /** @return Collection<int, Entry> */
    public function entries(int $year): Collection
    {
        $relations = ['costCenter', 'submitter', 'decider', 'payer', 'receipts:id,entry_id,name'];
        if (FinanceService::locationsEnabled()) {
            $relations[] = 'location';
        }

        return Entry::with($relations)
            ->where('year', $year)->whereIn('status', Entry::BOOKED)
            ->orderBy('booked_on')->orderBy('number')->get();
    }

    public function csv(int $year): string
    {
        $headers = ['Belegnummer', 'Belegdatum', 'Art', 'Kostenstelle Nr.', 'Kostenstelle'];
        if (FinanceService::locationsEnabled()) {
            $headers[] = 'Standort';
        }
        $rows = [array_merge($headers, ['Bezeichnung', 'Händler / Zahler', 'Betrag', 'Einnahme', 'Ausgabe',
            'Status', 'Eingereicht von', 'Genehmigt von', 'Genehmigt am', 'Bezahlt am', 'Zahlungshinweis', 'Belege'])];

        foreach ($this->entries($year) as $e) {
            $amount = number_format($e->amount_cents / 100, 2, ',', '');
            $row = [$e->code(), $e->booked_on->format('d.m.Y'), Entry::KINDS[$e->kind], (string) $e->costCenter->code, $e->costCenter->name];
            if (FinanceService::locationsEnabled()) {
                $row[] = $e->location->getAttribute('name') ?? 'ohne Standort';
            }
            $rows[] = array_merge($row, [
                $e->title, (string) $e->vendor,
                ($e->isIncome() ? '' : '-').$amount, $e->isIncome() ? $amount : '', $e->isIncome() ? '' : $amount,
                $e->statusLabel(), (string) $e->submitter?->name, (string) $e->decider?->name,
                (string) $e->decided_at?->setTimezone('Europe/Berlin')->format('d.m.Y'), (string) $e->paid_at?->setTimezone('Europe/Berlin')->format('d.m.Y'),
                (string) $e->payment_note, (string) $e->receipts->count(),
            ]);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\u{FEFF}");
        foreach ($rows as $row) {
            Csv::put($handle, $row, ';', '"', '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /** Path of a temporary ZIP with all Belege of the year; the caller deletes it */
    public function receiptsZip(int $year): string
    {
        $path = tempnam(sys_get_temp_dir(), 'belege');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die ZIP-Datei lässt sich nicht anlegen.');
        }

        $zip->addFromString('Buchungen '.$year.'.csv', $this->csv($year));
        foreach ($this->entries($year) as $entry) {
            foreach ($entry->receipts()->get() as $i => $receipt) {
                $extension = pathinfo($receipt->name, PATHINFO_EXTENSION) ?: ($receipt->mime === 'application/pdf' ? 'pdf' : 'webp');
                $name = $entry->code().'_'.Str::slug(Str::limit($entry->title, 40, ''), '-', 'de').($i > 0 ? '_'.($i + 1) : '').'.'.$extension;
                $zip->addFromString($name, (string) base64_decode($receipt->data));
            }
        }
        $zip->close();

        return $path;
    }
}
