<?php

namespace Modules\Fuehrerscheine\src\Livewire;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Services\LicenseService;

/** The Führerscheinprüfer: pending checks with the photos, every Kamerad's state, classes per Abteilung */
class Review extends Component
{
    /** @var array<int, string> */
    public array $reasons = [];

    public int $interval = 6;

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(LicenseService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->interval = LicenseService::interval();
    }

    public function validateLicense(int $id, LicenseService $service): void
    {
        $this->decide($id, fn (License $l) => $service->validate(auth()->user(), $l, $this->reasons[$id] ?? null), 'bestätigt');
    }

    public function rejectLicense(int $id, LicenseService $service): void
    {
        $this->decide($id, fn (License $l) => $service->reject(auth()->user(), $l, (string) ($this->reasons[$id] ?? '')), 'zurückgewiesen');
    }

    public function saveInterval(): void
    {
        $this->interval = max(1, min(36, $this->interval));
        Setting::set(LicenseService::INTERVAL_KEY, (string) $this->interval);
        $this->flash = 'Geprüft wird alle '.$this->interval.' Monate.';
    }

    private function decide(int $id, callable $action, string $done): void
    {
        $this->resetErrorBag();
        $license = License::with('user')->findOrFail($id);
        try {
            $action($license);
        } catch (ValidationException $e) {
            $this->addError('license-'.$id, collect($e->errors())->flatten()->first());

            return;
        }
        unset($this->reasons[$id]);
        $this->flash = $license->user->name.': '.$done.', die Fotos sind gelöscht.';
    }

    public function render(LicenseService $service)
    {
        $matrix = $service->matrix();
        $all = License::with(['user', 'photos'])->get()->filter(fn (License $l) => $l->user)->sortBy(fn (License $l) => $l->user->name);

        return view('fuehrerscheine::livewire.review', [
            'pending' => $all->where('status', 'pending')->values(),
            'all' => $all->values(),
            'matrix' => $matrix,
            'classes' => collect([...License::CLASSES, ...array_keys(License::FIRE)])->filter(fn ($c) => collect($matrix)->contains(fn ($row) => isset($row[$c])))->values(),
        ]);
    }
}
