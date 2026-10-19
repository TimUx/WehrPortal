<?php

namespace Extensions\Vereinsverwaltung\Services;

use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Member;
use Extensions\Vereinsverwaltung\Models\MembershipApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule('verein')
            && ($user->is_admin || $user->hasModuleRole('verein', 'vereinsvorstand'));
    }

    public function submitApplication(array $data): MembershipApplication
    {
        return MembershipApplication::create([
            ...$data,
            'status' => 'pending',
            'consent_version' => (string) config('vereinsverwaltung.privacy_notice_version', '1'),
            'consented_at' => now(),
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
                    'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile',
                    'email', 'street', 'postal_code', 'city', 'account_holder', 'bank_name', 'bic', 'iban',
                ]),
                'membership_type' => 'passive',
                'status' => 'member',
                'joined_at' => now()->toDateString(),
                'annual_fee_cents' => (int) config('vereinsverwaltung.annual_fee_cents', 1500),
                'mandate_status' => 'awaiting_signature',
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
                'joined_at' => now()->toDateString(),
                'annual_fee_cents' => (int) config('vereinsverwaltung.annual_fee_cents', 1500),
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
        $data['iban'] = filled($data['iban'] ?? null) ? strtoupper(preg_replace('/\s+/', '', $data['iban'])) : null;
        $data['bic'] = filled($data['bic'] ?? null) ? strtoupper($data['bic']) : null;
        $data = Validator::make($data, [
            'membership_type' => ['required', Rule::in(['active', 'passive'])],
            'status' => ['required', Rule::in(['member', 'left'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('x_vv_members', 'user_id')->ignore($member->id)],
            'title' => ['nullable', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
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
            'mandate_status' => ['required', Rule::in(['awaiting_signature', 'documented', 'revoked'])],
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
        $member->fill($data)->save();

        return $member;
    }
}
