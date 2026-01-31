<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapSupplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
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
