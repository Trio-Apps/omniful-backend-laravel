<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapPaymentTerm extends Model
{
    protected $fillable = [
        'code',
        'name',
        'additional_days',
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
