<?php

namespace Extensions\Vereinsverwaltung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    protected $table = 'x_vv_members';

    protected $fillable = [
        'user_id', 'membership_type', 'status', 'title', 'first_name', 'last_name', 'birth_date',
        'marital_status', 'phone', 'mobile', 'email', 'street', 'postal_code', 'city', 'joined_at',
        'left_at', 'annual_fee_cents', 'mandate_status', 'account_holder', 'bank_name', 'bic', 'iban',
        'mandate_reference', 'mandate_signed_at',
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
            'mandate_reference' => 'encrypted',
            'joined_at' => 'date',
            'left_at' => 'date',
            'mandate_signed_at' => 'date',
            'annual_fee_cents' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->first_name.' '.$this->last_name);
    }
}
