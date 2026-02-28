<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapProfitCenter extends Model
{
    protected $fillable = [
        'code',
        'name',
        'dimension',
        'is_active',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'dimension' => 'integer',
        'is_active' => 'boolean',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
