<?php

namespace App\Models;

use App\Support\Utf8;
use Illuminate\Database\Eloquent\Model;

class OmnifulPurchaseOrderEvent extends Model
{
    protected $fillable = [
        'external_id',
        'payload',
        'payload_hash',
        'headers',
        'signature_valid',
        'received_at',
        'sap_status',
        'sap_doc_entry',
        'sap_doc_num',
        'sap_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['payload', 'headers', 'sap_error'], true)) {
            $value = Utf8::sanitize($value);
        }

        return parent::setAttribute($key, $value);
    }
}
