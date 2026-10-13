<?php

namespace Modules\Personalverwaltung\src\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Auswertungen for Kommune and Verband: Funktionsträger, Dienstgrade, Beförderungen, Ehrungen – printable and as CSV */
class Reports extends Component
{
    #[Url(as: 'art')]
    public string $kind = 'funktionen';

    #[Url(as: 'von')]
    public string $from = '';

    #[Url(as: 'bis')]
    public string $to = '';

    public function boot(): void
    {
        abort_unless(PersonnelService::canView(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now('Europe/Berlin')->startOfYear()->toDateString();
        $this->to = $this->to ?: now('Europe/Berlin')->endOfYear()->toDateString();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function period(string $from, string $to): array
    {
        $parse = fn (string $d, string $fallback) => rescue(fn () => Carbon::createFromFormat('!Y-m-d', $d), Carbon::parse($fallback), false);

        return [$parse($from, now('Europe/Berlin')->startOfYear()->toDateString()), $parse($to, now('Europe/Berlin')->endOfYear()->toDateString())];
    }

    public function render(PersonnelService $personnel)
    {
        $kind = array_key_exists($this->kind, PersonnelService::REPORTS) ? $this->kind : 'funktionen';

        return view('personalverwaltung::livewire.reports', [
            'rows' => collect($personnel->report(auth()->user(), $kind, ...self::period($this->from, $this->to)))->groupBy('group'),
            'periodic' => in_array($kind, ['befoerderungen', 'ehrungen'], true),
            'title' => PersonnelService::REPORTS[$kind],
        ]);
    }
}
