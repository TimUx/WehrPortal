<?php

namespace Modules\Wasserkarte\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\WaterService;

/** Create or change a Löschwasserquelle; the position is set on the map or by GPS on site */
class SourceForm extends Component
{
    public ?Source $source = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?Source $source = null): void
    {
        $this->source = $source?->exists ? $source : null;
        $s = $this->source;
        $this->form = [
            'number' => (string) $s?->number, 'kind' => $s?->kind ?? 'unterflur', 'latitude' => $s ? (string) $s->latitude : '', 'longitude' => $s ? (string) $s->longitude : '',
            'location' => (string) $s?->location, 'flow_lpm' => (string) ($s?->flow_lpm ?? ''), 'pressure_bar' => $s?->pressure_bar !== null ? str_replace('.', ',', (string) $s->pressure_bar) : '',
            'capacity_m3' => (string) ($s?->capacity_m3 ?? ''), 'suction_height_m' => (string) ($s?->suction_height_m ?? ''), 'pipe_dn' => (string) ($s?->pipe_dn ?? ''), 'operator' => (string) $s?->operator,
            'inspection_interval_months' => (string) ($s ? ($s->inspection_interval_months ?? '') : WaterService::DEFAULT_INTERVAL), 'notes' => (string) $s?->notes,
            'is_active' => $s?->is_active ?? true,
        ];
    }

    public function save(WaterService $water)
    {
        $this->resetErrorBag();
        try {
            $source = $water->saveSource(auth()->user(), $this->source, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }
        session()->flash('wasserkarte.flash', 'Gespeichert.');

        return redirect()->route('wasserkarte.source', $source);
    }

    public function render()
    {
        return view('wasserkarte::livewire.source-form');
    }
}
