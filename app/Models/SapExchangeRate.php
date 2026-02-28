<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapExchangeRate extends Model
{
    protected $fillable = [
        'currency_code',
        'rate_date',
        'rate',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:8',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
