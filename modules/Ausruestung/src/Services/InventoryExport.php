<?php

namespace Modules\Ausruestung\src\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Ausruestung\src\Models\Equipment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Inventory list for stocktaking and insurance, as CSV that opens directly in Excel */
class InventoryExport
{
    private const COLUMNS = [
        'Code', 'Bezeichnung', 'Kategorie', 'Hersteller', 'Modell', 'Seriennummer', 'Charge', 'Barcode',
        'Standort', 'Status', 'Hergestellt', 'In Betrieb seit', 'Gekauft', 'Kaufpreis (EUR)',
        'Lebensdauer bis', 'Nächste Prüfung', 'Hinweise',
    ];

    public function download(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel reads the umlauts
            fputcsv($out, self::COLUMNS, ';');

            $query->with(['category', 'location', 'compartment.location'])->orderBy('code')
                ->chunk(500, function ($items) use ($out) {
                    foreach ($items as $equipment) {
                        fputcsv($out, $this->row($equipment), ';');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<int, string> */
    public function row(Equipment $e): array
    {
        $date = fn ($d) => $d?->format('d.m.Y') ?? '';

        return [
            $e->code, $e->name, $e->category?->name ?? '', $e->manufacturer ?? '', $e->model ?? '', $e->serial_number ?? '',
            $e->batch_number ?? '', $e->barcode ?? '', $e->placeLabel(), $e->status->label(),
            $date($e->manufactured_at), $date($e->commissioned_at), $date($e->purchased_at),
            $e->purchase_price !== null ? number_format((float) $e->purchase_price, 2, ',', '') : '',
            $date($e->lifetimeEndsAt()), $date($e->next_inspection_at), implode(', ', $e->warnings()),
        ];
    }
}
