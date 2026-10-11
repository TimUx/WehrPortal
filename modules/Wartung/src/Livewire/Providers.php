<?php

namespace Modules\Wartung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Provider;
use Modules\Wartung\src\Services\WartungService;

/** External Fachfirmen with contact; how often and how much they were booked */
class Providers extends Component
{
    public ?int $editing = null;

    /** @var array{name: string, contact_person: string, email: string, phone: string, notes: string, is_active: bool} */
    public array $form = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function edit(?int $id = null): void
    {
        $this->resetErrorBag();
        $provider = $id ? Provider::findOrFail($id) : null;
        $this->editing = $provider?->id ?? 0;
        $this->form = $provider
            ? ['name' => $provider->name, 'contact_person' => (string) $provider->contact_person, 'email' => (string) $provider->email,
                'phone' => (string) $provider->phone, 'notes' => (string) $provider->notes, 'is_active' => $provider->is_active]
            : $this->blank();
    }

    public function save(WartungService $service): void
    {
        $this->resetErrorBag();
        try {
            $service->saveProvider(auth()->user(), $this->editing ? Provider::findOrFail($this->editing) : null, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = 'Fachfirma gespeichert.';
        $this->resetForm();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->form = $this->blank();
    }

    private function blank(): array
    {
        return ['name' => '', 'contact_person' => '', 'email' => '', 'phone' => '', 'notes' => '', 'is_active' => true];
    }

    public function render()
    {
        return view('wartung::livewire.providers', [
            'providers' => Provider::orderByDesc('is_active')->orderBy('name')->get(),
            'stats' => Protocol::whereNull('voided_at')->whereNotNull('provider_id')
                ->selectRaw('provider_id, count(*) as n, sum(external_cost_cents) as cost, max(performed_on) as last')->groupBy('provider_id')->get()->keyBy('provider_id'),
        ]);
    }
}
