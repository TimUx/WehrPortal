<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Pages\Page;

/**
 * QR/barcode scanner page.
 * JS scanner implementation added in task 2.5.
 */
class ScanPage extends Page
{
    protected static ?string $slug = 'scanner';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Scanner';

    protected static ?int $navigationSort = 5;

    protected string $view = 'kleiderkammer::filament.pages.scan';
}
