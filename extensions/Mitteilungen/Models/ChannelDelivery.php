<?php

namespace Extensions\Mitteilungen\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelDelivery extends Model
{
    protected $table = 'x_mitteilungen_deliveries';

    protected $fillable = [
        'announcement_id', 'scope_key', 'transport', 'fingerprint', 'transaction_id', 'status', 'attempts', 'result', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
