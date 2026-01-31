<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OmnifulProductEvent extends Model
{
    protected $fillable = [
        'external_id',
        'payload',
        'headers',
        'signature_valid',
        'received_at',
        'sap_status',
        'sap_item_code',
        'sap_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
    ];
}
