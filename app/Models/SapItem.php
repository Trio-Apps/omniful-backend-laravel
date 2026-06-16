<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapItem extends Model
{
    protected $fillable = [
        'code',
        'name',
        'uom_group_entry',
        'payload',
        'synced_at',
        'status',
        'error',
        'omniful_status',
        'omniful_error',
        'omniful_synced_at',
        'omniful_payload',
        'omniful_response',
        'omniful_response_code',
    ];

    protected $casts = [
        'payload' => 'array',
        'omniful_payload' => 'array',
        'synced_at' => 'datetime',
        'omniful_synced_at' => 'datetime',
    ];
}
