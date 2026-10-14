<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Pages\Page;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;

/**
 * QR/barcode scanner page.
 * JS scanner implementation added in task 2.5.
 */
class ScanPage extends Page
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/scanner';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Scanner';

    protected static ?int $navigationSort = 5;

    protected string $view = 'kleiderkammer::filament.pages.scan';
}
