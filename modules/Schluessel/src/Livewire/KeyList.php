<?php

namespace Modules\Schluessel\src\Livewire;

use App\Filament\Resources\UserResource;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

/** Alle Schlüssel for the Schlüsselwart: search, filter, who has which */
class KeyList extends Component
{
    #[Url(as: 'suche')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function render(KeyService $keys)
    {
        $term = trim($this->search);
        $list = Key::with(['current.user', 'place', 'vehicle', 'object'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$term.'%')->orWhere('code', 'ilike', '%'.$term.'%')
                ->orWhere('locking', 'ilike', '%'.$term.'%')->orWhereHas('current.user', fn ($u) => $u->where('name', 'ilike', '%'.$term.'%'))))
            ->when($this->status === 'issued', fn ($q) => $q->whereHas('current'))
            ->when($this->status === 'available', fn ($q) => $q->where('status', 'available')->whereDoesntHave('current'))
            ->when(in_array($this->status, ['lost', 'retired'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->status === '', fn ($q) => $q->where('status', '!=', 'retired'))
            ->orderBy('code')->get();

        // Admins open the Kamerad who has the key (Admin → Kameraden)
        $memberUrl = fn ($user) => auth()->user()->is_admin ? UserResource::getUrl('edit', ['record' => $user]) : null;

        return view('schluessel::livewire.key-list', ['keys' => $list, 'former' => $keys->atFormerMembers(), 'memberUrl' => $memberUrl]);
    }
}
