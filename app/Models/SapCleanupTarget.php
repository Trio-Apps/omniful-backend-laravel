<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCleanupTarget extends Model
{
    protected $fillable = [
        'doc_entry',
        'doc_num',
        'order_external_id',
        'card_code',
        'doc_total',
        'sap_doc_status',
        'lines',
        'related',
        'cleanup_state',
        'last_action',
        'last_error',
        'last_checked_at',
        'source_mode',
        'source_value',
    ];

    protected $casts = [
        'doc_entry' => 'integer',
        'doc_num' => 'integer',
        'doc_total' => 'decimal:2',
        'lines' => 'array',
        'related' => 'array',
        'last_checked_at' => 'datetime',
    ];
}
