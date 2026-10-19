<?php

namespace Extensions\Vereinsverwaltung\Livewire;

use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Extensions\Vereinsverwaltung\Services\AssociationAccess;
use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Board extends Component
{
    use WithPagination;

    public string $tab = 'applications';

    public ?int $associationId = null;

    #[Locked]
    public ?int $memberId = null;

    #[Locked]
    public bool $creating = false;

    public array $form = [];

    public function mount(AssociationAccess $access): void
    {
        Gate::authorize('manage-verein');
        $this->associationId = $access->associationsFor(auth()->user())->value('id');
    }

    public function updatedAssociationId(AssociationAccess $access): void
    {
        $access->association(auth()->user(), (int) $this->associationId);
        $this->resetPage();
    }

    public function approve(int $applicationId, MembershipService $service): void
    {
        $service->approveApplication(auth()->user(), (int) $this->associationId, $applicationId);
        session()->flash('flash', 'Der Antrag wurde angenommen und als Mitglied angelegt.');
    }

    public function reject(int $applicationId, MembershipService $service): void
    {
        $service->rejectApplication(auth()->user(), (int) $this->associationId, $applicationId);
        session()->flash('flash', 'Der Antrag wurde abgelehnt; die personenbezogenen Angaben wurden gelöscht.');
    }

    public function edit(int $memberId): void
    {
        $member = app(MembershipService::class)->memberForAssociation(auth()->user(), (int) $this->associationId, $memberId);
        $this->memberId = $member->id;
        $this->creating = false;
        $this->form = [
            'user_id' => $member->user_id,
            'association_id' => $member->association_id,
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
        $association = app(AssociationAccess::class)->association(auth()->user(), (int) $this->associationId);
        $this->memberId = null;
        $this->creating = true;
        $this->form = [
            'user_id' => '',
            'association_id' => $association->id,
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
        $association = app(AssociationAccess::class)->association(auth()->user(), (int) $this->associationId);
        $this->validate(['form.annual_fee' => ['required', 'numeric', 'between:0,100000']]);
        $data = $this->form;
        $data['annual_fee_cents'] = (int) round((float) $data['annual_fee'] * 100);
        unset($data['annual_fee']);
        unset($data['photo_consent_recorded_at']);
        if ($this->creating) {
            $service->createMember(auth()->user(), $association->id, $data);
        } else {
            $service->updateMember(auth()->user(), $association->id, $this->memberId, $data);
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
        $service = app(MembershipService::class);
        $access = app(AssociationAccess::class);
        $associations = $access->associationsFor(auth()->user())->get();
        $association = $associations->firstWhere('id', $this->associationId);
        if (! $association && $associations->isNotEmpty()) {
            $association = $associations->first();
            $this->associationId = $association->id;
        }

        return view('vereinsverwaltung::livewire.board', [
            'association' => $association,
            'associations' => $associations,
            'applications' => $association ? MembershipApplication::query()->withoutGlobalScopes()
                ->with('association')
                ->select(['id', 'association_id', 'status', 'title', 'first_name', 'last_name', 'email', 'submitted_at', 'youth_member', 'photo_consent', 'youth_photo_consent'])
                ->where('association_id', $association->id)
                ->where('status', 'pending')->oldest('submitted_at')->get()
                : collect(),
            'members' => $association ? Member::query()->withoutGlobalScopes()
                ->select(['id', 'user_id', 'association_id', 'membership_type', 'youth_member', 'status', 'title', 'first_name', 'last_name', 'annual_fee_cents', 'mandate_status', 'photo_consent', 'youth_photo_consent'])
                ->where('association_id', $association->id)
                ->with('association')
                ->with(['user' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name', 'email', 'phone', 'mobile'])])
                ->orderByDesc('id')->paginate(20)
                : Member::query()->whereRaw('1 = 0')->paginate(20),
            'users' => $association ? $service->personnelForAssociation($association) : collect(),
            'editing' => $this->memberId && $association ? $service->memberForAssociation(auth()->user(), $association->id, $this->memberId) : null,
        ]);
    }
}
