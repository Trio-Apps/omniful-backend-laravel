<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapSyncEvent extends Model
{
    protected $fillable = [
        'event_key',
        'source_type',
        'source_id',
        'sap_action',
        'sap_status',
        'sap_doc_entry',
        'sap_doc_num',
        'sap_error',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
