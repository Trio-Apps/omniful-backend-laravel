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
    ];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
        'omniful_synced_at' => 'datetime',
    ];
}
