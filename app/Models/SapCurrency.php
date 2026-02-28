<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCurrency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
