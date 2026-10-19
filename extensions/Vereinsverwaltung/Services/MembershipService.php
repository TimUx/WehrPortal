<?php

namespace Extensions\Vereinsverwaltung\Services;

use App\Enums\MemberStatus;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    /** @return list<string> */
    public function associationNames(): array
    {
        $stored = Setting::get('ext_vv_association_names');
        $names = is_string($stored) ? preg_split('/\R/u', $stored) : [];
        $names = array_values(array_unique(array_filter(array_map('trim', $names ?: []))));

        return $names ?: [config('vereinsverwaltung.association_name', 'Feuerwehrverein')];
    }

    /** @return list<string> */
    public function saveAssociationNames(string $input): array
    {
        $names = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input) ?: [])));

        Validator::make(['names' => $names], [
            'names' => ['required', 'array', 'min:1', 'max:50'],
            'names.*' => ['required', 'string', 'max:150'],
        ], [
            'names.required' => 'Mindestens ein Vereinsname ist erforderlich.',
            'names.min' => 'Mindestens ein Vereinsname ist erforderlich.',
            'names.max' => 'Es können höchstens 50 Vereine eingetragen werden.',
            'names.*.max' => 'Ein Vereinsname darf höchstens 150 Zeichen lang sein.',
        ])->validate();

        if (count(array_unique(array_map(mb_strtolower(...), $names))) !== count($names)) {
            throw ValidationException::withMessages(['associationNamesText' => 'Jeder Vereinsname darf nur einmal eingetragen werden.']);
        }

        Setting::set('ext_vv_association_names', implode("\n", $names));

        return $names;
    }

    public function isLinkedMember(User $user): bool
    {
        return Member::query()
            ->where('user_id', $user->id)
            ->where('status', 'member')
            ->exists();
    }

    public function setPersonnelMembership(User $user, bool $isMember): void
    {
        abort_unless(app(ModuleRegistry::class)->isActive('verein'), 404);

        if ($isMember) {
            abort_unless($user->status === MemberStatus::Active, 422);
        }

        DB::transaction(function () use ($user, $isMember) {
            $member = Member::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if (! $isMember) {
                if ($member && $member->status === 'member') {
                    $member->forceFill(['status' => 'left', 'left_at' => now()->toDateString()])->save();
                }

                return;
            }

            $locationId = $user->getAttribute('ext_location_id');
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
                    'association_name' => $this->associationNames()[0],
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
                'status' => 'member',
                'left_at' => null,
                'ext_location_id' => $locationId,
            ])->save();
        });
    }

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule('verein')
            && ($user->is_admin || $user->hasModuleRole('verein', 'vereinsvorstand'));
    }

    public function submitApplication(array $data): MembershipApplication
    {
        $data['association_name'] ??= $this->associationNames()[0];
        Validator::make($data, [
            'association_name' => ['required', 'string', Rule::in($this->associationNames())],
        ])->validate();

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

    public function approveApplication(User $by, int $applicationId): Member
    {
        Gate::forUser($by)->authorize('manage-verein');

        return DB::transaction(function () use ($by, $applicationId) {
            $application = MembershipApplication::query()->lockForUpdate()->findOrFail($applicationId);
            abort_unless($application->status === 'pending', 404);

            $member = Member::create([
                ...$application->only([
                    'association_name', 'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile',
                    'email', 'street', 'postal_code', 'city', 'account_holder', 'bank_name', 'bic', 'iban',
                    'youth_member', 'photo_consent', 'youth_photo_consent', 'photo_consent_version', 'photo_consent_recorded_at',
                ]),
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

    public function rejectApplication(User $by, int $applicationId): void
    {
        Gate::forUser($by)->authorize('manage-verein');

        DB::transaction(function () use ($by, $applicationId) {
            $application = MembershipApplication::query()->lockForUpdate()->findOrFail($applicationId);
            abort_unless($application->status === 'pending', 404);
            $application->forceFill([
                'status' => 'rejected',
                'decided_at' => now(),
                'decided_by' => $by->id,
            ])->save();
            $application->redact();
        });
    }

    public function createMember(User $by, array $data): Member
    {
        Gate::forUser($by)->authorize('manage-verein');

        return DB::transaction(function () use ($by, $data) {
            $member = Member::create([
                'first_name' => '',
                'last_name' => '',
                'association_name' => $data['association_name'] ?? $this->associationNames()[0],
                'joined_at' => now()->toDateString(),
                'annual_fee_cents' => ! empty($data['youth_member']) ? 0 : (int) config('vereinsverwaltung.annual_fee_cents', 1500),
                'mandate_status' => ! empty($data['youth_member']) ? 'not_required' : 'awaiting_signature',
            ]);

            return $this->updateMember($by, $member->id, $data);
        });
    }

    public function updateMember(User $by, int $memberId, array $data): Member
    {
        Gate::forUser($by)->authorize('manage-verein');
        $member = Member::query()->findOrFail($memberId);
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
        foreach (['user_id', 'title', 'birth_date', 'marital_status', 'phone', 'mobile', 'email', 'street', 'postal_code', 'city', 'left_at', 'account_holder', 'bank_name', 'bic', 'iban', 'mandate_reference', 'mandate_signed_at'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }
        $linkedUser = filled($data['user_id'] ?? null) ? User::query()->findOrFail($data['user_id']) : null;
        $data['association_name'] ??= $member->association_name ?: $this->associationNames()[0];
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
                'ext_location_id' => $linkedUser->getAttribute('ext_location_id'),
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
            'association_name' => ['required', 'string', 'max:150', Rule::in(array_unique([...$this->associationNames(), $member->association_name]))],
            'status' => ['required', Rule::in(['member', 'left'])],
            'youth_member' => ['required', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('x_vv_members', 'user_id')->ignore($member->id)],
            'ext_location_id' => ['nullable', 'integer'],
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
}
