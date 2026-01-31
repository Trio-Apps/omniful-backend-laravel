<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OmnifulReturnOrderEvent extends Model
{
    protected $fillable = [
        'external_id',
        'payload',
        'headers',
        'signature_valid',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
    ];
}
