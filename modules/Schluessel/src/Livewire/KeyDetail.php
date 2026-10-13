<?php

namespace Modules\Schluessel\src\Livewire;

use App\Models\User;
use App\Support\QrCode;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

/** One key for the Schlüsselwart: hand out with Schlüsselquittung, take back, lost, history */
class KeyDetail extends Component
{
    public Key $item;

    public string $memberId = '';

    public string $dueOn = '';

    public string $note = '';

    public string $signature = '';

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(KeyService::canManage(auth()->user()), 403);
    }

    public function issue(KeyService $keys): void
    {
        $member = User::find((int) $this->memberId);
        if (! $member) {
            $this->addError('issue', 'Bitte wählen, wer den Schlüssel bekommt.');

            return;
        }
        if ($this->run(fn () => $keys->issue(auth()->user(), $this->item, $member, $this->dueOn ?: null, $this->note, $this->signature ?: null), 'Ausgegeben an '.$member->name.'.')) {
            $this->reset(['memberId', 'dueOn', 'note', 'signature']);
        }
    }

    public function takeBack(KeyService $keys): void
    {
        $this->run(fn () => $keys->takeBack(auth()->user(), $this->item), 'Zurückgenommen.');
    }

    public function reportLost(KeyService $keys): void
    {
        $this->run(fn () => $keys->reportLost(auth()->user(), $this->item), 'Als verloren gemeldet.');
    }

    public function setStatus(string $status, KeyService $keys): void
    {
        $this->run(fn () => $keys->setStatus(auth()->user(), $this->item, $status), $status === 'retired' ? 'Ausgesondert.' : 'Wieder vorhanden.');
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;
        $this->item->refresh();

        return true;
    }

    public function render()
    {
        $this->item->load(['current.user', 'handovers.user', 'handovers.issuer', 'handovers.receiver', 'place', 'vehicle', 'object']);

        return view('schluessel::livewire.key-detail', [
            'people' => User::active()->orderBy('name')->pluck('name', 'id'),
            'qr' => QrCode::dataUri(route('scan.code', $this->item->code), 200),
        ]);
    }
}
