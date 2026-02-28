<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapBankingDocument extends Model
{
    protected $fillable = [
        'document_type',
        'doc_entry',
        'doc_num',
        'reference_code',
        'doc_date',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
