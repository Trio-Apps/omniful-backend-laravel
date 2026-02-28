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
        'sap_card_fee_status',
        'sap_card_fee_journal_entry',
        'sap_card_fee_journal_num',
        'sap_card_fee_error',
        'sap_delivery_status',
        'sap_delivery_doc_entry',
        'sap_delivery_doc_num',
        'sap_delivery_error',
        'sap_cogs_status',
        'sap_cogs_journal_entry',
        'sap_cogs_journal_num',
        'sap_cogs_error',
        'sap_credit_note_status',
        'sap_credit_note_doc_entry',
        'sap_credit_note_doc_num',
        'sap_credit_note_error',
        'sap_cancel_cogs_status',
        'sap_cancel_cogs_journal_entry',
        'sap_cancel_cogs_journal_num',
        'sap_cancel_cogs_error',
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
