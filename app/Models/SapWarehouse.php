<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapWarehouse extends Model
{
    protected $fillable = [
        'code',
        'name',
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
