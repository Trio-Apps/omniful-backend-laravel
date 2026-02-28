<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCustomerFinance extends Model
{
    protected $table = 'sap_customer_finance';

    protected $fillable = [
        'customer_code',
        'customer_name',
        'currency_code',
        'balance',
        'current_balance',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
