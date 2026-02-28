<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapBranch extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_disabled',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'is_disabled' => 'boolean',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
