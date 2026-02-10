<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OmnifulOrder extends Model
{
    protected $fillable = [
        'external_id',
        'omniful_status',
        'sap_status',
        'sap_doc_entry',
        'sap_doc_num',
        'sap_error',
        'sap_payment_status',
        'sap_payment_doc_entry',
        'sap_payment_doc_num',
        'sap_payment_error',
        'last_event_type',
        'last_event_at',
        'last_payload',
        'last_headers',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
        'last_payload' => 'array',
        'last_headers' => 'array',
    ];
}
