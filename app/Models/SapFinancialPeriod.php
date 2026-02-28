<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapFinancialPeriod extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
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
