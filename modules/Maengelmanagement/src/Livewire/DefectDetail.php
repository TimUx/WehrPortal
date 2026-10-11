<?php

namespace Modules\Maengelmanagement\src\Livewire;

use Livewire\Component;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Services\DefectService;

/** One defect with photos, history and a comment box – open to every member of the module */
class DefectDetail extends Component
{
    public Defect $defect;

    public string $comment = '';

    public function addComment(DefectService $service): void
    {
        $this->validate(
            ['comment' => ['required', 'string', 'max:3000']],
            ['comment.required' => 'Bitte schreib einen Kommentar.'],
        );

        $service->comment($this->defect, auth()->user(), trim($this->comment));
        $this->reset('comment');
    }

    public function render()
    {
        return view('maengelmanagement::livewire.defect-detail', [
            'photos' => $this->defect->photos()->get(['id']),
            'events' => $this->defect->events()->with('user')->get(),
        ]);
    }
}
