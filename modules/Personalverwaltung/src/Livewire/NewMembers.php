<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\Group;
use App\Models\User;
use App\Services\Invitation;
use App\Support\QrCode;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** Neue Mitglieder: one by hand with an Einladung right away, or many from a CSV with a preview */
class NewMembers extends Component
{
    use WithFileUploads;

    public array $member = ['name' => '', 'email' => '', 'group_ids' => [], 'joined_at' => '', 'rank' => '', 'gender' => ''];

    /** The Einladung of the Kamerad just taken in */
    public ?array $invite = null;

    public $file = null;

    public string $csv = '';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(PersonnelService::canManage(auth()->user()), 403);
    }

    public function create(PersonnelService $personnel, Invitation $invitation): void
    {
        $this->resetErrorBag();
        try {
            $member = $personnel->createMember(auth()->user(), $this->member);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        if (! $member->hasLogin()) {
            $this->member = ['name' => '', 'email' => '', 'group_ids' => [], 'joined_at' => '', 'rank' => '', 'gender' => ''];
            $this->flash = $member->name.' ist ohne E-Mail-Adresse angelegt – ohne Login, aber in allen Modulen auswählbar (z. B. Kleidung, Unterweisungen, Verbandbuch).';

            return;
        }
        $link = $invitation->createForScreen($member, auth()->user());
        $this->invite = ['id' => $member->id, 'name' => $member->name, 'url' => $link['url'], 'until' => $link['expires_at']->setTimezone('Europe/Berlin')->format('d.m.Y H:i')];
        $this->member = ['name' => '', 'email' => '', 'group_ids' => [], 'joined_at' => '', 'rank' => '', 'gender' => ''];
        $this->flash = $member->name.' ist angelegt. Zeig den QR-Code zum Scannen oder schick die Einladung per Mail.';
    }

    public function mailInvite(Invitation $invitation): void
    {
        $member = User::findOrFail($this->invite['id'] ?? 0);
        abort_unless(PersonnelService::mayView(auth()->user(), $member), 403);
        $invitation->send($member, auth()->user());
        $this->flash = 'Einladung an '.$member->email.' geschickt.';
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:2048']]);
        $this->csv = (string) file_get_contents($this->file->getRealPath());
    }

    public function import(PersonnelService $personnel): void
    {
        try {
            $result = $personnel->importApply(auth()->user(), $this->csv);
        } catch (ValidationException $e) {
            $this->addError('import', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = $result['created'].' angelegt, '.$result['updated'].' ergänzt. Einladungen verschickst du in der Akte jedes Kameraden.';
        $this->reset('file', 'csv');
    }

    public function render(PersonnelService $personnel)
    {
        $rows = [];
        if ($this->csv !== '') {
            try {
                $rows = $personnel->importPreview(auth()->user(), $this->csv);
            } catch (ValidationException $e) {
                $this->addError('import', collect($e->errors())->flatten()->first());
            }
        }
        $scope = PersonnelService::scopeOf(auth()->user());

        return view('personalverwaltung::livewire.new-members', [
            'groups' => Group::when($scope !== [], fn ($q) => $q->whereIn('id', $scope))->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'qr' => $this->invite ? QrCode::dataUri($this->invite['url'], 260) : null,
            'rows' => collect($rows),
        ]);
    }
}
