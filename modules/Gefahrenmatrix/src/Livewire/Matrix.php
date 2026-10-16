<?php

namespace Modules\Gefahrenmatrix\src\Livewire;

use Illuminate\Support\Collection;
use Livewire\Component;

class Matrix extends Component
{
    /** @var list<string> */
    public array $selected = [];

    /** @return array<string, array{title: string, hazards: array<string, string>}> */
    public static function catalog(): array
    {
        return [
            'aaaa' => [
                'title' => 'AAAA',
                'hazards' => [
                    'atemgift' => 'Atemgift',
                    'angstreaktion' => 'Angstreaktion',
                    'ausbreitung' => 'Ausbreitung',
                    'atomare-gefahr' => 'Atomare Gefahr',
                ],
            ],
            'cccc' => [
                'title' => 'CCCC',
                'hazards' => [
                    'chemische-stoffe' => 'Chemische Stoffe',
                    'cbrnex' => 'CBRNEx',
                    'container' => 'Container',
                    'korrosiv' => 'Korrosiv (ätzend)',
                ],
            ],
            'eeee' => [
                'title' => 'EEEE',
                'hazards' => [
                    'elektrizitaet' => 'Elektrizität',
                    'erkrankung' => 'Erkrankung / Seuche',
                    'explosion' => 'Explosion',
                    'einsturz' => 'Einsturz / Absturz',
                ],
            ],
            'weitere' => [
                'title' => 'Weitere Gefahren',
                'hazards' => [
                    'gefaehrliche-tiere' => 'Gefährliche Tiere',
                    'gewalt' => 'Gewalt',
                    'wasser' => 'Wasser',
                    'hitze-kaelte' => 'Hitze / Kälte',
                    'verkehr' => 'Verkehr',
                    'umwelt' => 'Umwelt',
                    'oel-kraftstoffe' => 'Öl / Kraftstoffe',
                    'radioaktive-strahlung' => 'Radioaktive Strahlung',
                ],
            ],
        ];
    }

    public function toggle(string $hazard): void
    {
        abort_unless($this->knownHazards()->contains($hazard), 404);

        $this->selected = in_array($hazard, $this->selected, true)
            ? array_values(array_diff($this->selected, [$hazard]))
            : [...$this->selected, $hazard];
    }

    public function clear(): void
    {
        $this->selected = [];
    }

    public function render()
    {
        $labels = collect(self::catalog())->flatMap(fn (array $group) => $group['hazards'])->all();
        $selected = collect($this->selected)->filter(fn (string $key) => isset($labels[$key]));

        return view('gefahrenmatrix::livewire.matrix', [
            'groups' => self::catalog(),
            'selectedHazards' => $selected->map(fn (string $key) => $labels[$key])->values(),
        ]);
    }

    /** @return Collection<int, string> */
    private function knownHazards(): Collection
    {
        return collect(self::catalog())->flatMap(fn (array $group) => array_keys($group['hazards']));
    }
}
