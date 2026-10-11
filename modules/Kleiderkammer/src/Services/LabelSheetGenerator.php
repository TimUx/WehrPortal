<?php

namespace Modules\Kleiderkammer\src\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Modules\Kleiderkammer\src\Models\Item;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a printable A4 label sheet (3 × 7 grid) for a collection of items.
 * Each label contains the item code, a QR code and a Code128 barcode.
 */
class LabelSheetGenerator
{
    public function __construct(
        private readonly QrCodeRenderer $qr,
        private readonly BarcodeRenderer $barcode,
    ) {}

    /**
     * Generate a PDF and return its binary content.
     *
     * @param  Collection<int, Item>  $items
     */
    public function generate(Collection $items): string
    {
        $labels = $items->map(fn (Item $item) => [
            'code' => $item->code,
            'qr' => $this->qr->dataUri($item),
            'barcode' => $this->barcode->dataUri($item),
        ])->all();

        $html = $this->buildHtml($labels);

        return Pdf::loadHtml($html)
            ->setPaper('a4')
            ->output();
    }

    /**
     * Stream the PDF to the HTTP response with appropriate headers.
     *
     * @param  Collection<int, Item>  $items
     */
    public function download(Collection $items, string $filename = 'etiketten.pdf'): Response
    {
        $pdf = $this->generate($items);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @param array<int, array{code: string, qr: string, barcode: string}> $labels */
    private function buildHtml(array $labels): string
    {
        $cells = '';
        foreach ($labels as $label) {
            $code = htmlspecialchars($label['code'], ENT_QUOTES);
            $qr = $label['qr'];
            $barcode = $label['barcode'];
            $cells .= <<<HTML
                <td class="label">
                    <div class="code">{$code}</div>
                    <img class="qr" src="{$qr}" alt="{$code}" />
                    <img class="bc" src="{$barcode}" alt="{$code}" />
                </td>
                HTML;
        }

        // Pad to full rows of 3
        $remainder = count($labels) % 3;
        if ($remainder > 0) {
            $padding = 3 - $remainder;
            $cells .= str_repeat('<td class="label empty"></td>', $padding);
        }

        // Wrap cells into rows of 3
        $rows = '';
        preg_match_all('/<td.*?<\/td>/s', $cells, $matches);
        foreach (array_chunk($matches[0], 3) as $rowCells) {
            $rows .= '<tr>'.implode('', $rowCells).'</tr>';
        }

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
            <meta charset="utf-8">
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: sans-serif; }
                table { width: 100%; border-collapse: collapse; }
                td.label {
                    width: 33.33%;
                    height: 37mm;
                    border: 0.5pt solid #ccc;
                    padding: 3mm;
                    text-align: center;
                    vertical-align: middle;
                }
                td.label.empty { border: none; }
                .code { font-size: 9pt; font-weight: bold; margin-bottom: 2mm; }
                .qr { width: 25mm; height: 25mm; display: block; margin: 0 auto 1mm; }
                .bc { width: 45mm; height: 8mm; display: block; margin: 0 auto; }
            </style>
            </head>
            <body>
            <table>
            {$rows}
            </table>
            </body>
            </html>
            HTML;
    }
}
