<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapBankAccount extends Model
{
    protected $fillable = [
        'account_code',
        'bank_code',
        'account_number',
        'branch',
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
