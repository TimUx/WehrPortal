<?php

namespace Extensions\Vereinsverwaltung\Livewire;

use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Board extends Component
{
    use WithPagination;

    public string $tab = 'applications';

    #[Locked]
    public ?int $memberId = null;

    #[Locked]
    public bool $creating = false;

    public array $form = [];

    public string $associationNamesText = '';

    public function mount(MembershipService $service): void
    {
        Gate::authorize('manage-verein');
        $this->associationNamesText = implode("\n", $service->associationNames());
    }

    public function saveAssociationNames(MembershipService $service): void
    {
        Gate::authorize('manage-verein');
        $this->validate(['associationNamesText' => ['required', 'string', 'max:8000']]);
        $names = $service->saveAssociationNames($this->associationNamesText);
        $this->associationNamesText = implode("\n", $names);
        session()->flash('flash', 'Vereinsnamen gespeichert.');
    }

    public function approve(int $applicationId, MembershipService $service): void
    {
        Gate::authorize('manage-verein');
        $service->approveApplication(auth()->user(), $applicationId);
        session()->flash('flash', 'Der Antrag wurde angenommen und als Mitglied angelegt.');
    }

    public function reject(int $applicationId, MembershipService $service): void
    {
        Gate::authorize('manage-verein');
        $service->rejectApplication(auth()->user(), $applicationId);
        session()->flash('flash', 'Der Antrag wurde abgelehnt; die personenbezogenen Angaben wurden gelöscht.');
    }

    public function edit(int $memberId): void
    {
        Gate::authorize('manage-verein');
        $member = Member::query()->findOrFail($memberId);
        $this->memberId = $member->id;
        $this->creating = false;
        $this->form = [
            'user_id' => $member->user_id,
            'association_name' => $member->association_name,
            'membership_type' => $member->membership_type,
            'youth_member' => $member->youth_member,
            'status' => $member->status,
            'title' => $member->title ?? '',
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'birth_date' => $member->birth_date ?? '',
            'marital_status' => $member->marital_status ?? '',
            'phone' => $member->phone ?? '',
            'mobile' => $member->mobile ?? '',
            'email' => $member->email ?? '',
            'street' => $member->street ?? '',
            'postal_code' => $member->postal_code ?? '',
            'city' => $member->city ?? '',
            'joined_at' => $member->joined_at?->format('Y-m-d'),
            'left_at' => $member->left_at?->format('Y-m-d') ?? '',
            'annual_fee' => number_format($member->annual_fee_cents / 100, 2, '.', ''),
            'mandate_status' => $member->mandate_status,
            'account_holder' => $member->account_holder ?? '',
            'bank_name' => $member->bank_name ?? '',
            'bic' => $member->bic ?? '',
            'iban' => $member->iban ?? '',
            'mandate_reference' => $member->mandate_reference ?? '',
            'mandate_signed_at' => $member->mandate_signed_at?->format('Y-m-d') ?? '',
            'photo_consent' => $member->photo_consent,
            'youth_photo_consent' => $member->youth_photo_consent,
            'photo_consent_recorded_at' => $member->photo_consent_recorded_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '',
        ];
        $this->resetValidation();
    }

    public function newMember(): void
    {
        Gate::authorize('manage-verein');
        $this->memberId = null;
        $this->creating = true;
        $this->form = [
            'user_id' => '',
            'association_name' => app(MembershipService::class)->associationNames()[0],
            'membership_type' => 'passive',
            'youth_member' => false,
            'status' => 'member',
            'title' => '',
            'first_name' => '',
            'last_name' => '',
            'birth_date' => '',
            'marital_status' => '',
            'phone' => '',
            'mobile' => '',
            'email' => '',
            'street' => '',
            'postal_code' => '',
            'city' => '',
            'joined_at' => now()->toDateString(),
            'left_at' => '',
            'annual_fee' => number_format(config('vereinsverwaltung.annual_fee_cents', 1500) / 100, 2, '.', ''),
            'mandate_status' => 'awaiting_signature',
            'account_holder' => '',
            'bank_name' => '',
            'bic' => '',
            'iban' => '',
            'mandate_reference' => '',
            'mandate_signed_at' => '',
            'photo_consent' => false,
            'youth_photo_consent' => false,
            'photo_consent_recorded_at' => '',
        ];
        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->memberId = null;
        $this->creating = false;
        $this->form = [];
        $this->resetValidation();
    }

    public function save(MembershipService $service): void
    {
        Gate::authorize('manage-verein');
        $this->validate(['form.annual_fee' => ['required', 'numeric', 'between:0,100000']]);
        $data = $this->form;
        $data['annual_fee_cents'] = (int) round((float) $data['annual_fee'] * 100);
        unset($data['annual_fee']);
        unset($data['photo_consent_recorded_at']);
        if ($this->creating) {
            $service->createMember(auth()->user(), $data);
        } else {
            $service->updateMember(auth()->user(), $this->memberId, $data);
        }
        $this->closeEditor();
        session()->flash('flash', 'Mitgliedsdaten gespeichert.');
    }

    public function updatedForm($value, string $key): void
    {
        if ($key !== 'youth_member') {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->form['annual_fee'] = '0.00';
            $this->form['mandate_status'] = 'not_required';
            foreach (['account_holder', 'bank_name', 'bic', 'iban', 'mandate_reference', 'mandate_signed_at'] as $field) {
                $this->form[$field] = '';
            }
        } else {
            $this->form['annual_fee'] = number_format(config('vereinsverwaltung.annual_fee_cents', 1500) / 100, 2, '.', '');
            $this->form['mandate_status'] = 'awaiting_signature';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('manage-verein');

        return view('vereinsverwaltung::livewire.board', [
            'applications' => MembershipApplication::query()
                ->select(['id', 'status', 'association_name', 'title', 'first_name', 'last_name', 'email', 'submitted_at', 'youth_member', 'photo_consent', 'youth_photo_consent'])
                ->where('status', 'pending')->oldest('submitted_at')->get(),
            'members' => Member::query()
                ->select(['id', 'user_id', 'association_name', 'membership_type', 'youth_member', 'status', 'title', 'first_name', 'last_name', 'annual_fee_cents', 'mandate_status', 'photo_consent', 'youth_photo_consent'])
                ->with('user:id,name')->orderByDesc('id')->paginate(20),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'editing' => $this->memberId ? Member::query()->findOrFail($this->memberId) : null,
            'associationNames' => app(MembershipService::class)->associationNames(),
        ]);
    }
}
