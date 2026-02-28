<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapSalesDocument extends Model
{
    protected $fillable = [
        'document_type',
        'doc_entry',
        'doc_num',
        'card_code',
        'doc_date',
        'amount',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'amount' => 'decimal:4',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
