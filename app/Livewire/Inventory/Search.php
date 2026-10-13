<?php

namespace App\Livewire\Inventory;

use App\Services\InventoryRegistry;
use App\Services\ScanRegistry;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Bestand: one field for searching and scanning across the modules (Fahrzeuge, Geräte,
 * Kleidung, Schlüssel …). A barcode or RFID reader types into it like a keyboard, a phone with
 * NFC reads a tag's serial number; a code that a module knows opens that object directly.
 * Gerätewarte and Fahrzeugwarte also see the Ersatzbeschaffung of the next years.
 */
class Search extends Component
{
    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'ansicht')]
    public string $tab = 'suche';

    public int $years = 10;

    public ?string $notFound = null;

    public function lookup(ScanRegistry $scan)
    {
        $code = trim($this->q);
        $this->notFound = null;
        if ($code === '') {
            return null;
        }
        // An NFC serial "04:a2:3b:…" is stored without colons
        foreach (array_unique([$code, self::normalizeTag($code)]) as $candidate) {
            if ($url = $scan->resolveKnown($candidate)) {
                return redirect()->to($url);
            }
        }
        if (app(InventoryRegistry::class)->search($code, auth()->user())->isEmpty()) {
            $this->notFound = $code;
        }

        return null;
    }

    public static function normalizeTag(string $value): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value));
    }

    public function render(InventoryRegistry $inventory)
    {
        $user = auth()->user();
        $canPlan = $inventory->canPlan($user);
        $tab = $this->tab === 'ersatz' && $canPlan ? 'ersatz' : 'suche';
        $year = (int) now('Europe/Berlin')->year;
        $replacements = $tab === 'ersatz' ? $inventory->replacements($user, $year, $year + max(1, min(30, $this->years))) : collect();

        return view('livewire.inventory.search', [
            'view' => $tab,
            'canPlan' => $canPlan,
            'labels' => $inventory->labels(),
            'results' => $tab === 'suche' ? $inventory->search($this->q, $user) : collect(),
            'replacements' => $replacements,
            'overdue' => $replacements->where('year', '<', $year),
            'byYear' => $replacements->where('year', '>=', $year)->groupBy('year'),
            'year' => $year,
        ]);
    }
}
