<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapChartOfAccount extends Model
{
    protected $fillable = [
        'code',
        'name',
        'father_account_key',
        'group_mask',
        'is_active',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
