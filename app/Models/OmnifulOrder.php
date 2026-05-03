<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OmnifulOrder extends Model
{
    private const JSON_PAYLOAD_ATTRIBUTES = [
        'sap_order_response',
        'sap_payment_response',
        'sap_card_fee_response',
        'sap_delivery_response',
        'sap_cogs_response',
        'sap_credit_note_response',
        'sap_cancel_cogs_response',
        'last_payload',
        'last_headers',
    ];

    protected $fillable = [
        'external_id',
        'omniful_status',
        'sap_status',
        'sap_doc_entry',
        'sap_doc_num',
        'sap_error',
        'sap_order_response',
        'sap_payment_status',
        'sap_payment_doc_entry',
        'sap_payment_doc_num',
        'sap_payment_error',
        'sap_payment_response',
        'sap_card_fee_status',
        'sap_card_fee_journal_entry',
        'sap_card_fee_journal_num',
        'sap_card_fee_error',
        'sap_card_fee_response',
        'sap_delivery_status',
        'sap_delivery_doc_entry',
        'sap_delivery_doc_num',
        'sap_delivery_error',
        'sap_delivery_response',
        'sap_cogs_status',
        'sap_cogs_journal_entry',
        'sap_cogs_journal_num',
        'sap_cogs_error',
        'sap_cogs_response',
        'sap_credit_note_status',
        'sap_credit_note_doc_entry',
        'sap_credit_note_doc_num',
        'sap_credit_note_error',
        'sap_credit_note_response',
        'sap_cancel_cogs_status',
        'sap_cancel_cogs_journal_entry',
        'sap_cancel_cogs_journal_num',
        'sap_cancel_cogs_error',
        'sap_cancel_cogs_response',
        'last_event_type',
        'last_event_at',
        'last_payload',
        'last_headers',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
        'sap_order_response' => 'array',
        'sap_payment_response' => 'array',
        'sap_card_fee_response' => 'array',
        'sap_delivery_response' => 'array',
        'sap_cogs_response' => 'array',
        'sap_credit_note_response' => 'array',
        'sap_cancel_cogs_response' => 'array',
        'last_payload' => 'array',
        'last_headers' => 'array',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array((string) $key, self::JSON_PAYLOAD_ATTRIBUTES, true)) {
            $value = $this->sanitizeJsonPayload($value);
        }

        return parent::setAttribute($key, $value);
    }

    private function sanitizeJsonPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeJsonPayload($item);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->sanitizeJsonPayload($item);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
}
