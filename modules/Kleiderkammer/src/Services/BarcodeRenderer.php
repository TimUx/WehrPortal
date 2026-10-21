<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Modules\Kleiderkammer\src\Models\Item;
use Picqer\Barcode\BarcodeGeneratorSVG;

/** Renders a Code128 barcode for an item code as an SVG data URI. */
class BarcodeRenderer
{
    /** Returns a data:image/svg+xml;base64,… string for embedding in HTML/PDF. */
    public function dataUri(Item $item, ?User $actor = null): string
    {
        app(PersonalAccess::class)->authorizeItem($actor ?? auth()->user(), $item);
        $generator = new BarcodeGeneratorSVG;
        $svg = $generator->getBarcode($item->code, $generator::TYPE_CODE_128, 2, 60);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
