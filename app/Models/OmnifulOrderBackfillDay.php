<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmnifulOrderBackfillDay extends Model
{
    protected $fillable = [
        'run_id',
        'day',
        'total',
        'existing',
        'missing',
        'skipped',
        'enqueued',
    ];

    protected $casts = [
        'day' => 'date',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(OmnifulOrderBackfillRun::class, 'run_id');
    }
}
