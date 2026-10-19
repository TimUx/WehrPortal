<?php

namespace Extensions\Vereinsverwaltung\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipApplication extends Model
{
    protected $table = 'x_vv_applications';

    protected $fillable = [
        'status', 'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile',
        'email', 'street', 'postal_code', 'city', 'account_holder', 'bank_name', 'bic', 'iban',
        'consent_version', 'consented_at', 'submitted_at', 'decided_at', 'decided_by', 'youth_member',
        'photo_consent', 'youth_photo_consent', 'photo_consent_version', 'photo_consent_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'birth_date' => 'encrypted',
            'marital_status' => 'encrypted',
            'phone' => 'encrypted',
            'mobile' => 'encrypted',
            'email' => 'encrypted',
            'street' => 'encrypted',
            'postal_code' => 'encrypted',
            'city' => 'encrypted',
            'account_holder' => 'encrypted',
            'bank_name' => 'encrypted',
            'bic' => 'encrypted',
            'iban' => 'encrypted',
            'youth_member' => 'boolean',
            'photo_consent' => 'boolean',
            'youth_photo_consent' => 'boolean',
            'consented_at' => 'immutable_datetime',
            'photo_consent_recorded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function displayName(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->first_name.' '.$this->last_name);
    }

    public function redact(): void
    {
        $this->forceFill(array_fill_keys([
            'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile',
            'email', 'street', 'postal_code', 'city', 'account_holder', 'bank_name', 'bic', 'iban',
        ], null))->save();
    }
}
