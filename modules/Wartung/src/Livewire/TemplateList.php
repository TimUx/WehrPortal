<?php

namespace Modules\Wartung\src\Livewire;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Services\WartungService;

/** The Wartungsvorlagen and the hourly rate for the cost calculation */
class TemplateList extends Component
{
    public string $hourlyRate = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->hourlyRate = Money::input(WartungService::hourlyRateCents());
        $this->flash = session('wartung.flash');
    }

    public function saveRate(WartungService $service): void
    {
        $this->resetErrorBag();
        try {
            $service->setHourlyRate(auth()->user(), Money::parse($this->hourlyRate));
        } catch (ValidationException $e) {
            $this->addError('hourly_rate', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Stundensatz gespeichert. Er gilt für neue Protokolle.';
    }

    public function render()
    {
        return view('wartung::livewire.template-list', [
            'templates' => Template::with(['category', 'provider'])->withCount('equipment')->orderByDesc('is_active')->orderBy('name')->get()
                ->each(fn (Template $t) => $t->setAttribute('devices_count', $t->devices()->count())),
            'open' => Task::open()->whereNotNull('template_id')->selectRaw('template_id, count(*) as n')->groupBy('template_id')->pluck('n', 'template_id'),
        ]);
    }
}
