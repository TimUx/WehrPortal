<?php

namespace Extensions\Anwesenheit\Livewire;

use Extensions\Anwesenheit\Services\AttendanceService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The lists the user may see: all of their Standort for managers, otherwise the own */
class ListIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $year = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $service = app(AttendanceService::class);
        $user = auth()->user();
        $query = $service->visibleTo($user)->with(['leader', 'creator'])->withCount('files');

        if (in_array($this->status, ['draft', 'submitted'], true)) {
            $query->where('status', $this->status);
        }
        if (ctype_digit($this->year) && strlen($this->year) === 4) {
            $query->whereYear('starts_at', (int) $this->year);
        }

        return view('anwesenheit::livewire.list-index', [
            'lists' => $query->orderByRaw('starts_at desc nulls first')->orderByDesc('id')->paginate(20),
            'canManage' => AttendanceService::canManage($user),
        ]);
    }
}
