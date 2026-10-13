<?php

namespace App\Livewire\Profile;

use App\Services\HoursRegistry;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Personalakte → Stunden: the own Stundennachweis of a year, by source */
class Hours extends Component
{
    #[Url(as: 'jahr')]
    public int $year = 0;

    #[Url(as: 'herkunft')]
    public string $source = '';

    public function mount(): void
    {
        $this->year = $this->year ?: (int) now('Europe/Berlin')->year;
    }

    public function render(HoursRegistry $hours)
    {
        $rows = $hours->forUser(auth()->user(), ...self::period($this->year));

        return view('livewire.profile.hours', [
            'rows' => $this->source !== '' ? $rows->where('source', $this->source)->values() : $rows,
            'totals' => $rows->groupBy('source')->map->sum('minutes'),
            'sources' => $hours->sources(),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} the year in Berlin time, as UTC */
    public static function period(int $year): array
    {
        return [Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc(), Carbon::create($year, 12, 31, 23, 59, 59, 'Europe/Berlin')->utc()];
    }
}
