<?php

namespace Extensions\Vereinsverwaltung\Services;

use App\Enums\MemberStatus;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Vereinsverwaltung\Models\Association;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    public function moduleIsActive(): bool
    {
        return app(ModuleRegistry::class)->isActive('verein');
    }

    /** @return array<int, string> */
    public function associationOptions(): array
    {
        return Association::query()->withoutGlobalScopes()->whereNotNull('location_id')->with('location')->orderBy('name')
            ->get()->mapWithKeys(fn (Association $association) => [$association->id => $association->name.' – '.($association->location?->name ?? 'Standort nicht zugewiesen')])
            ->all();
    }

    public function isLinkedMember(User $user): bool
    {
        return Member::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'member')
            ->exists();
    }

    public function setPersonnelMembership(User $user, bool $isMember): void
    {
        abort_unless($this->moduleIsActive(), 404);

        if ($isMember) {
            abort_unless($user->status === MemberStatus::Active, 422);
        }

        DB::transaction(function () use ($user, $isMember) {
            $locationId = $user->getAttribute('ext_location_id');
            $association = app(AssociationAccess::class)->associationForLocation($locationId);
            abort_if($isMember && ! $association, 422, 'Für den Standort dieses Kameraden ist kein Verein zugewiesen.');
            $member = Member::query()->withoutGlobalScopes()->where('user_id', $user->id)->lockForUpdate()->first();

            if (! $isMember) {
                if ($member && $member->status === 'member' && (! $association || $member->association_id === $association->id)) {
                    $member->forceFill(['status' => 'left', 'left_at' => now()->toDateString()])->save();
                }

                return;
            }

            $contactFields = [
                'title' => null,
                'first_name' => null,
                'last_name' => null,
                'birth_date' => null,
                'marital_status' => null,
                'phone' => null,
                'mobile' => null,
                'email' => null,
                'street' => null,
                'postal_code' => null,
                'city' => null,
            ];

            if (! $member) {
                Member::create([
                    ...$contactFields,
                    'user_id' => $user->id,
                    'association_id' => $association->id,
                    'membership_type' => 'active',
                    'status' => 'member',
                    'joined_at' => now()->toDateString(),
                    'annual_fee_cents' => (int) config('vereinsverwaltung.annual_fee_cents', 1500),
                    'mandate_status' => 'awaiting_signature',
                    'ext_location_id' => $locationId,
                ]);

                return;
            }

            $member->forceFill([
                ...$contactFields,
                'membership_type' => 'active',
                'association_id' => $association->id,
                'status' => 'member',
                'left_at' => null,
                'ext_location_id' => $locationId,
            ])->save();
        });
    }

    public function submitApplication(array $data): MembershipApplication
    {
        Validator::make($data, [
            'association_id' => ['required', 'integer', Rule::in(array_keys($this->associationOptions()))],
        ])->validate();
        abort_unless(Association::query()->withoutGlobalScopes()->whereKey($data['association_id'])->whereNotNull('location_id')->exists(), 422);

        if ($data['youth_member'] ?? false) {
            $data = array_merge($data, [
                'account_holder' => null,
                'bank_name' => null,
                'bic' => null,
                'iban' => null,
            ]);
        }

        return MembershipApplication::create([
            ...$data,
            'status' => 'pending',
            'consent_version' => (string) config('vereinsverwaltung.privacy_notice_version', '1'),
            'consented_at' => now(),
            'photo_consent_version' => (string) config('vereinsverwaltung.photo_consent_version', '1'),
            'photo_consent_recorded_at' => now(),
            'submitted_at' => now(),
        ]);
    }

    public function approveApplication(User $by, int $associationId, int $applicationId): Member
    {
        $association = app(AssociationAccess::class)->association($by, $associationId);

        return DB::transaction(function () use ($by, $association, $applicationId) {
            $application = MembershipApplication::query()->withoutGlobalScopes()->where('association_id', $association->id)->lockForUpdate()->findOrFail($applicationId);
            abort_unless($application->status === 'pending', 404);

            $member = Member::create([
                ...$application->only([
                    'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile',
                    'email', 'street', 'postal_code', 'city', 'account_holder', 'bank_name', 'bic', 'iban',
                    'youth_member', 'photo_consent', 'youth_photo_consent', 'photo_consent_version', 'photo_consent_recorded_at',
                ]),
                'association_id' => $association->id,
                'ext_location_id' => $association->location_id,
                'membership_type' => 'passive',
                'status' => 'member',
                'joined_at' => now()->toDateString(),
                'annual_fee_cents' => $application->youth_member ? 0 : (int) config('vereinsverwaltung.annual_fee_cents', 1500),
                'mandate_status' => $application->youth_member ? 'not_required' : 'awaiting_signature',
            ]);

            $application->forceFill([
                'status' => 'approved',
                'decided_at' => now(),
                'decided_by' => $by->id,
            ])->save();
            $application->redact();

            return $member;
        });
    }

    public function rejectApplication(User $by, int $associationId, int $applicationId): void
    {
        $association = app(AssociationAccess::class)->association($by, $associationId);

        DB::transaction(function () use ($by, $association, $applicationId) {
            $application = MembershipApplication::query()->withoutGlobalScopes()->where('association_id', $association->id)->lockForUpdate()->findOrFail($applicationId);
            abort_unless($application->status === 'pending', 404);
            $application->forceFill([
                'status' => 'rejected',
                'decided_at' => now(),
                'decided_by' => $by->id,
            ])->save();
            $application->redact();
        });
    }

    public function createMember(User $by, int $associationId, array $data): Member
    {
        $association = app(AssociationAccess::class)->association($by, $associationId);

        return DB::transaction(function () use ($by, $association, $data) {
            $member = Member::create([
                'association_id' => $association->id,
                'ext_location_id' => $association->location_id,
                'first_name' => '',
                'last_name' => '',
                'joined_at' => now()->toDateString(),
                'annual_fee_cents' => ! empty($data['youth_member']) ? 0 : (int) config('vereinsverwaltung.annual_fee_cents', 1500),
                'mandate_status' => ! empty($data['youth_member']) ? 'not_required' : 'awaiting_signature',
            ]);

            return $this->updateMember($by, $association->id, $member->id, $data);
        });
    }

    public function updateMember(User $by, int $associationId, int $memberId, array $data): Member
    {
        $association = app(AssociationAccess::class)->association($by, $associationId);
        $member = Member::query()->withoutGlobalScopes()->where('association_id', $association->id)->findOrFail($memberId);
        $data['association_id'] = $association->id;
        $data['ext_location_id'] = $association->location_id;
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
        foreach (['user_id', 'title', 'birth_date', 'marital_status', 'phone', 'mobile', 'email', 'street', 'postal_code', 'city', 'left_at', 'account_holder', 'bank_name', 'bic', 'iban', 'mandate_reference', 'mandate_signed_at'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }
        $linkedUser = filled($data['user_id'] ?? null)
            ? User::query()->withoutGlobalScopes()->where('is_active', true)->where('ext_location_id', $association->location_id)->findOrFail($data['user_id'])
            : null;
        if ($linkedUser) {
            $data = array_merge($data, [
                'title' => null,
                'first_name' => null,
                'last_name' => null,
                'birth_date' => null,
                'marital_status' => null,
                'phone' => null,
                'mobile' => null,
                'email' => null,
                'street' => null,
                'postal_code' => null,
                'city' => null,
                'association_id' => $association->id,
                'ext_location_id' => $association->location_id,
            ]);
        }
        $data['iban'] = filled($data['iban'] ?? null) ? strtoupper(preg_replace('/\s+/', '', $data['iban'])) : null;
        $data['bic'] = filled($data['bic'] ?? null) ? strtoupper($data['bic']) : null;
        $data['youth_member'] = filter_var($data['youth_member'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($data['youth_member']) {
            $data = array_merge($data, [
                'annual_fee_cents' => 0,
                'mandate_status' => 'not_required',
                'account_holder' => null,
                'bank_name' => null,
                'bic' => null,
                'iban' => null,
                'mandate_reference' => null,
                'mandate_signed_at' => null,
            ]);
        } elseif ($member->youth_member && $data['mandate_status'] === 'not_required') {
            $data['mandate_status'] = 'awaiting_signature';
            $data['annual_fee_cents'] = (int) config('vereinsverwaltung.annual_fee_cents', 1500);
        }
        $data = Validator::make($data, [
            'membership_type' => ['required', Rule::in(['active', 'passive'])],
            'status' => ['required', Rule::in(['member', 'left'])],
            'youth_member' => ['required', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('x_vv_members', 'user_id')->ignore($member->id)],
            'association_id' => ['required', 'integer', Rule::in([$association->id])],
            'ext_location_id' => ['required', 'integer', Rule::in([$association->location_id])],
            'title' => ['nullable', 'string', 'max:30'],
            'first_name' => [$linkedUser ? 'nullable' : 'required', 'string', 'max:100'],
            'last_name' => [$linkedUser ? 'nullable' : 'required', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date'],
            'marital_status' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:254'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'joined_at' => ['required', 'date'],
            'left_at' => ['nullable', 'required_if:status,left', 'date', 'after_or_equal:joined_at'],
            'annual_fee_cents' => ['required', 'integer', 'between:0,10000000'],
            'mandate_status' => ['required', Rule::in(['awaiting_signature', 'documented', 'revoked', 'not_required'])],
            'account_holder' => ['nullable', 'string', 'max:200'],
            'bank_name' => ['nullable', 'string', 'max:200'],
            'bic' => ['nullable', 'string', 'max:11', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i'],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/i'],
            'mandate_reference' => ['nullable', 'string', 'max:100'],
            'mandate_signed_at' => ['nullable', 'required_if:mandate_status,documented', 'date'],
        ], [], [
            'membership_type' => 'Mitgliedsart',
            'status' => 'Mitgliedsstatus',
            'user_id' => 'Kameradenkonto',
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'birth_date' => 'Geburtsdatum',
            'joined_at' => 'Beitrittsdatum',
            'left_at' => 'Austrittsdatum',
            'annual_fee_cents' => 'Jahresbeitrag',
            'mandate_status' => 'SEPA-Mandatsstatus',
            'mandate_signed_at' => 'Mandatsdatum',
            'account_holder' => 'Kontoinhaber',
            'bank_name' => 'Kreditinstitut',
            'bic' => 'BIC',
            'iban' => 'IBAN',
            'mandate_reference' => 'Mandatsreferenz',
        ])->validate();

        if ($data['status'] === 'member') {
            $data['left_at'] = null;
        }
        if (! $data['youth_member'] && $data['mandate_status'] === 'not_required') {
            throw ValidationException::withMessages(['mandate_status' => 'Bei einer regulären Vereinsmitgliedschaft muss der Mandatsstatus dokumentiert werden.']);
        }
        $member->fill($data)->save();

        return $member;
    }

    public function memberForAssociation(User $by, int $associationId, int $memberId): Member
    {
        $association = app(AssociationAccess::class)->association($by, $associationId);

        return Member::query()->withoutGlobalScopes()->where('association_id', $association->id)->findOrFail($memberId);
    }

    /** @return Collection<int, User> */
    public function personnelForAssociation(Association $association)
    {
        return User::query()->withoutGlobalScopes()->where('is_active', true)
            ->where('ext_location_id', $association->location_id)->orderBy('name')->get();
    }
}
