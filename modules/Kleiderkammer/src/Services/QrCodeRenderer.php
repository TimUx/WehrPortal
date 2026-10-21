<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use App\Support\QrCode;
use Modules\Kleiderkammer\src\Models\Item;

/** Renders a QR code for an item's scan URL as an SVG data URI. */
class QrCodeRenderer
{
    /** Returns a data:image/svg+xml;base64,… string for embedding in HTML/PDF. */
    public function dataUri(Item $item, ?User $actor = null): string
    {
        return QrCode::dataUri($this->scanUrl($item, $actor), 150, 5);
    }

    /** The scan URL for an item – used both for QR generation and label printing. */
    public function scanUrl(Item $item, ?User $actor = null): string
    {
        app(PersonalAccess::class)->authorizeItem($actor ?? auth()->user(), $item);

        return rtrim(config('app.url'), '/').'/i/'.rawurlencode($item->code);
    }
}
