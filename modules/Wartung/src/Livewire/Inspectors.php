<?php

namespace Modules\Wartung\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Qualification;
use Modules\Wartung\src\Services\WartungService;

/**
 * Prüfer and their qualifications with Prüfer-ID and validity – the Qualifikationsnachweis:
 * who checked when with which ID.
 */
class Inspectors extends Component
{
    public ?int $editing = null;

    /** @var array{user_id: string, name: string, inspector_number: string, valid_until: string, notes: string} */
    public array $form = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function edit(?int $id = null): void
    {
        $this->resetErrorBag();
        $q = $id ? Qualification::findOrFail($id) : null;
        $this->editing = $q?->id ?? 0;
        $this->form = $q
            ? ['user_id' => (string) $q->user_id, 'name' => $q->name, 'inspector_number' => (string) $q->inspector_number,
                'valid_until' => (string) $q->valid_until?->toDateString(), 'notes' => (string) $q->notes]
            : $this->blank();
    }

    public function save(WartungService $service): void
    {
        $this->resetErrorBag();
        try {
            $service->saveQualification(auth()->user(), $this->editing ? Qualification::findOrFail($this->editing) : null, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = 'Qualifikation gespeichert.';
        $this->resetForm();
    }

    public function delete(int $id, WartungService $service): void
    {
        $service->deleteQualification(auth()->user(), Qualification::findOrFail($id));
        $this->flash = 'Qualifikation entfernt. Bestehende Protokolle behalten ihre Angaben.';
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
        return ['user_id' => '', 'name' => '', 'inspector_number' => '', 'valid_until' => '', 'notes' => ''];
    }

    public function render()
    {
        return view('wartung::livewire.inspectors', [
            'qualifications' => Qualification::with('user')->get()->sortBy(fn (Qualification $q) => $q->user?->name.' '.$q->name)->groupBy('user_id'),
            'members' => User::active()->orderBy('name')->get(['id', 'name']),
            'stats' => Protocol::whereNull('voided_at')->whereNotNull('inspector_user_id')
                ->selectRaw('inspector_user_id, count(*) as n, max(performed_on) as last')->groupBy('inspector_user_id')->get()->keyBy('inspector_user_id'),
            'names' => Qualification::query()->distinct()->orderBy('name')->pluck('name'),
        ]);
    }
}
