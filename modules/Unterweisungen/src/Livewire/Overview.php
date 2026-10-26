<?php

namespace Modules\Unterweisungen\src\Livewire;

use App\Models\Group;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Services\InstructionService;

/** Who is unterwiesen: Kameraden × kinds (required ones coloured), and the last Unterweisungen */
class Overview extends Component
{
    #[Url(as: 'abteilung')]
    public string $groupId = '';

    public function boot(): void
    {
        abort_unless(InstructionService::canManage(auth()->user()), 403);
    }

    public function render(InstructionService $service)
    {
        return view('unterweisungen::livewire.overview', [
            ...$service->matrix($this->groupId !== '' ? (int) $this->groupId : null),
            'groups' => Group::options(),
            'sessions' => Session::with('topic')->withCount(['attendances', 'attendances as signed_count' => fn ($q) => $q->whereNotNull('signed_at')])->latest('held_on')->limit(15)->get(),
        ]);
    }
}
