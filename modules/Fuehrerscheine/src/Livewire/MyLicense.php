<?php

namespace Modules\Fuehrerscheine\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Services\LicenseService;

/** Mein Führerschein: classes with their dates, photos of front and back, the state of the check */
class MyLicense extends Component
{
    use WithFileUploads;

    public string $documentValidUntil = '';

    /** @var array<string, array{on: bool, valid_until: string}> by class */
    public array $classes = [];

    public $front = null;

    public $back = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $license = License::where('user_id', auth()->id())->first();
        $this->documentValidUntil = (string) $license?->document_valid_until?->toDateString();
        $held = collect($license?->classes ?? [])->keyBy('class');
        foreach ([...License::CLASSES, ...array_keys(License::FIRE)] as $class) {
            $this->classes[$class] = ['on' => $held->has($class), 'valid_until' => (string) ($held[$class]['valid_until'] ?? '')];
        }
    }

    public function submit(LicenseService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['front' => ['nullable', 'image', 'max:10240'], 'back' => ['nullable', 'image', 'max:10240']],
            ['*.image' => 'Bitte ein Foto (JPG, PNG).', '*.max' => 'Das Foto ist zu groß (höchstens 10 MB).']);
        try {
            $service->submit(auth()->user(), $this->documentValidUntil, collect($this->classes)->filter(fn ($c) => $c['on'])
                ->map(fn ($c, $class) => ['class' => $class, 'valid_until' => $c['valid_until']])->values()->all(), $this->front, $this->back);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->reset('front', 'back');
        $this->flash = 'Eingereicht – der Führerscheinprüfer gleicht die Fotos ab. Danach werden sie gelöscht.';
    }

    public function render()
    {
        $license = License::with(['checks.checker', 'validator'])->withCount('photos')->where('user_id', auth()->id())->first();

        return view('fuehrerscheine::livewire.my-license', ['license' => $license]);
    }
}
