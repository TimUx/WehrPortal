<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

/** QR codes as SVG (labels, scan codes, the QR access of the Einsatzbericht) */
final class QrCode
{
    public static function svg(string $data, int $size = 240, int $margin = 8): string
    {
        return (new Builder(writer: new SvgWriter, data: $data, size: $size, margin: $margin))->build()->getString();
    }

    public static function dataUri(string $data, int $size = 240, int $margin = 8): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($data, $size, $margin));
    }
}
