<?php

namespace App\Models;

use App\Support\Utf8;
use Illuminate\Database\Eloquent\Model;

class OmnifulReturnOrderEvent extends Model
{
    private const JSON_PAYLOAD_ATTRIBUTES = [
        'payload',
        'headers',
        'sap_response',
        'sap_cogs_reversal_response',
    ];

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
        'sap_response',
        'sap_cogs_reversal_status',
        'sap_cogs_reversal_journal_entry',
        'sap_cogs_reversal_journal_num',
        'sap_cogs_reversal_error',
        'sap_cogs_reversal_response',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'sap_response' => 'array',
        'sap_cogs_reversal_response' => 'array',
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array((string) $key, self::JSON_PAYLOAD_ATTRIBUTES, true)) {
            $value = Utf8::sanitize($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Whether the full SAP flow for this return is finished: the AR credit memo
     * step AND the COGS reversal step are both in a completed state. Anything
     * else (failed, ignored, pending, or a step not started yet — e.g. COGS
     * reversal pending) counts as incomplete and is eligible for retry. Mirrors
     * the "Completed" definition used by the Process Steps panel on the view.
     */
    public function isSapFlowComplete(): bool
    {
        $completed = ['created', 'updated', 'logged', 'skipped'];

        return in_array((string) $this->sap_status, $completed, true)
            && in_array((string) $this->sap_cogs_reversal_status, $completed, true);
    }
}
