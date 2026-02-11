<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCostCenter extends Model
{
    protected $fillable = [
        'code',
        'name',
        'dimension',
        'source',
        'is_active',
        'synced_at',
    ];

    protected $casts = [
        'dimension' => 'integer',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];
}

