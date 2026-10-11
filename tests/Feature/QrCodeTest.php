<?php

use App\Support\QrCode;
use Modules\Ausruestung\src\Filament\Support\EquipmentActions;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Services\QrCodeRenderer;
use Modules\Maengelmanagement\src\Filament\Resources\DeviceResource;
use Modules\Maengelmanagement\src\Models\Device;

// endroid/qr-code 6 has no Builder::create(); every QR label goes through App\Support\QrCode
test('QR codes are rendered as SVG', function () {
    expect(QrCode::svg('https://example.com/i/WEHRAB0001'))->toContain('<svg')
        ->and(QrCode::dataUri('x'))->toStartWith('data:image/svg+xml;base64,');
});

test('the labels of Kleiderkammer, Ausrüstung and Mängelmanagement carry a QR code', function () {
    $item = (new Item)->forceFill(['code' => 'WEHRAB0001']);
    $equipment = (new Equipment)->forceFill(['code' => 'WEHRGE0001', 'name' => 'Hydraulischer Spreizer']);
    $device = (new Device)->forceFill(['code' => 'WEHRDV0001', 'name' => 'Stromerzeuger']);

    expect(app(QrCodeRenderer::class)->dataUri($item))->toStartWith('data:image/svg+xml;base64,')
        ->and(EquipmentActions::labelHtml($equipment))->toContain('data:image/svg+xml;base64,')->toContain('Hydraulischer Spreizer')
        ->and(DeviceResource::labelHtml($device))->toContain('data:image/svg+xml;base64,')->toContain('Stromerzeuger');
});
